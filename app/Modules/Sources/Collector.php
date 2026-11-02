<?php

declare(strict_types=1);

namespace Concursei\Modules\Sources;

use Concursei\Core\Net\FetchFailed;
use Concursei\Core\Net\SafeHttpClient;
use Concursei\Core\Net\SafeUrl;
use Concursei\Core\Net\UnsafeUrl;
use Concursei\Modules\Jobs\JobFailed;
use Concursei\Modules\Jobs\Queue;
use Concursei\Modules\Jobs\RetryLater;

/**
 * Coleta de fontes (D-017): requisição condicional (ETag/Last-Modified), identificação por hash,
 * armazenamento fora do webroot e encadeamento de jobs (descoberta → download → extração de texto).
 * Fonte "page" é monitorada para descobrir PDFs; fonte "document" é o próprio arquivo.
 */
class Collector
{
    public const MAX_DISCOVERED = 10;

    public function __construct(
        private SourceRepository $sources,
        private SafeHttpClient $http,
        private DocumentStore $store,
        private Queue $queue,
    ) {
    }

    /** @return array<string,scalar|null> */
    public function check(int $sourceId): array
    {
        $source = $this->sources->find($sourceId) ?? throw new JobFailed('Fonte inexistente.');
        if ((int) $source['enabled'] !== 1) {
            return ['skipped' => 'desativada'];
        }
        $isPage = $source['method'] === 'page';
        $result = $this->guard($sourceId, fn () => $this->http->get((string) $source['url'], self::allowedHosts($source), [
            'etag' => $source['etag'],
            'last_modified' => $source['last_modified'],
            'accept' => $isPage ? ['text/html', 'application/xhtml+xml'] : SafeHttpClient::DOCUMENT_TYPES,
        ]));
        if ($result->notModified()) {
            $this->sources->markChecked($sourceId, $result->etag ?? $source['etag'], $result->lastModified ?? $source['last_modified'], null, false);
            return ['changed' => false, 'http' => 304];
        }
        $hash = hash('sha256', $result->body);
        $changed = $hash !== $source['last_hash'];
        $summary = ['changed' => $changed];
        if ($changed && $isPage) {
            $summary['discovered'] = $this->discover($source, $result->url, $result->body);
        } elseif ($changed) {
            $doc = $this->storeDocument($sourceId, $result->url, $hash, $result->mimeType, $result->body);
            $summary['document_id'] = $doc['id'];
        }
        $this->sources->markChecked($sourceId, $result->etag, $result->lastModified, $hash, $changed);
        return $summary;
    }

    /** Download de um documento descoberto na página da fonte. @return array<string,scalar|null> */
    public function fetchDocument(int $sourceId, string $url): array
    {
        $source = $this->sources->find($sourceId) ?? throw new JobFailed('Fonte inexistente.');
        $result = $this->guard($sourceId, fn () => $this->http->get($url, self::allowedHosts($source)));
        $doc = $this->storeDocument($sourceId, $result->url, hash('sha256', $result->body), $result->mimeType, $result->body);
        return ['document_id' => $doc['id'], 'new' => $doc['created']];
    }

    /** @return list<string> */
    public static function allowedHosts(array $source): array
    {
        $hosts = [strtolower((string) parse_url((string) $source['url'], PHP_URL_HOST))];
        foreach (explode(',', (string) ($source['extra_hosts'] ?? '')) as $h) {
            $h = strtolower(trim($h));
            if ($h !== '' && SafeUrl::isDnsName(ltrim(str_starts_with($h, '*.') ? substr($h, 2) : $h, '.'))) {
                $hosts[] = $h;
            }
        }
        return array_values(array_unique($hosts));
    }

    /** @return array{id:int,created:bool} */
    private function storeDocument(int $sourceId, string $url, string $hash, string $mime, string $body): array
    {
        $path = $this->store->put($hash, $mime, $body);
        $doc = $this->sources->addDocument($sourceId, $url, $hash, $mime, strlen($body), $path);
        if ($doc['created']) {
            $this->queue->dispatch('document.extract_text', ['document_id' => $doc['id']], 'document.extract_text:' . $doc['id']);
        }
        return $doc;
    }

    /** PDFs ligados na página, dentro dos hosts autorizados, ainda não baixados. */
    private function discover(array $source, string $pageUrl, string $html): int
    {
        $hosts = self::allowedHosts($source);
        $count = 0;
        foreach (TextExtractor::links($html) as $href) {
            if ($count >= self::MAX_DISCOVERED) {
                break;
            }
            try {
                $url = SafeUrl::parse(SafeUrl::resolve($pageUrl, $href), $hosts)->url;
            } catch (UnsafeUrl) {
                continue;
            }
            if (!preg_match('/\.pdf$/i', (string) parse_url($url, PHP_URL_PATH)) || $this->sources->documentUrlKnown((int) $source['id'], $url)) {
                continue;
            }
            $this->queue->dispatch('source.fetch_document', ['source_id' => (int) $source['id'], 'url' => $url], 'source.fetch_document:' . hash('sha256', $source['id'] . '|' . $url));
            $count++;
        }
        return $count;
    }

    /**
     * Traduz falhas de coleta: política violada bloqueia a fonte (sem nova tentativa);
     * falha temporária reagenda com backoff; demais falhas são definitivas para este job.
     * @template T
     * @param callable():T $fetch
     * @return T
     */
    private function guard(int $sourceId, callable $fetch): mixed
    {
        try {
            return $fetch();
        } catch (UnsafeUrl $e) {
            $this->sources->markFailure($sourceId, $e->getMessage(), true);
            throw new JobFailed('Bloqueado pela política do coletor: ' . $e->getMessage());
        } catch (FetchFailed $e) {
            $this->sources->markFailure($sourceId, $e->getMessage(), false);
            if ($e->retryable) {
                throw new RetryLater($e->getMessage(), $e->retryAfterSeconds);
            }
            throw new JobFailed($e->getMessage());
        }
    }
}
