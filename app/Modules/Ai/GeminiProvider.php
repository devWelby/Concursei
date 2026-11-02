<?php

declare(strict_types=1);

namespace Concursei\Modules\Ai;

/**
 * Gemini via REST (generateContent) por cURL no back-end (D-018).
 * Chave no cabeçalho x-goog-api-key (nunca na URL); TLS verificado; sem proxy do ambiente;
 * resposta limitada em tamanho. Conferido com a documentação oficial em 2026-11 (generateContent,
 * responseMimeType/responseSchema, usageMetadata, finishReason, promptFeedback.blockReason).
 */
final class GeminiProvider implements AiProvider
{
    private const MAX_RESPONSE_BYTES = 4 * 1024 * 1024;

    public function __construct(
        private string $apiKey,
        private string $model,
        private string $endpoint,
        private int $timeout,
    ) {
    }

    public function name(): string
    {
        return 'gemini';
    }

    public function model(): string
    {
        return $this->model;
    }

    public function isConfigured(): bool
    {
        return $this->apiKey !== '' && preg_match('/^[a-z0-9][a-z0-9.\-]{1,80}$/', $this->model) === 1;
    }

    public function generate(AiRequest $request): AiResponse
    {
        if (!$this->isConfigured()) {
            throw new AiError('config', false);
        }
        $payload = json_encode([
            'systemInstruction' => ['parts' => [['text' => $request->system]]],
            'contents' => [['role' => 'user', 'parts' => [['text' => $request->text]]]],
            'generationConfig' => [
                'responseMimeType' => 'application/json',
                'responseSchema' => $request->schema,
                'maxOutputTokens' => $request->maxOutputTokens,
                'temperature' => $request->temperature,
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        $body = '';
        $headers = [];
        $tooBig = false;
        $ch = curl_init(rtrim($this->endpoint, '/') . '/models/' . rawurlencode($this->model) . ':generateContent');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'x-goog-api-key: ' . $this->apiKey],
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROXY => '',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_ENCODING => '',
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$headers): int {
                if (str_contains($line, ':')) {
                    [$name, $value] = explode(':', $line, 2);
                    $headers[strtolower(trim($name))] = trim($value);
                }
                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => static function ($ch, string $chunk) use (&$body, &$tooBig): int {
                if (strlen($body) + strlen($chunk) > self::MAX_RESPONSE_BYTES) {
                    $tooBig = true;
                    return -1;
                }
                $body .= $chunk;
                return strlen($chunk);
            },
        ]);
        $ok = curl_exec($ch);
        $errno = curl_errno($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        unset($ch);

        if ($tooBig) {
            throw new AiError('invalid_json', false, null, true);
        }
        if ($ok === false || $errno !== 0) {
            // Tempo esgotado depois do envio: o provedor pode ter processado (e cobrado) o pedido.
            throw $errno === CURLE_OPERATION_TIMEOUTED ? new AiError('timeout', true, null, true) : new AiError('network', true);
        }
        return self::parse($status, $body, $headers['retry-after'] ?? null, $this->model);
    }

    /** Interpreta a resposta HTTP do generateContent (separado para teste sem rede). */
    public static function parse(int $status, string $body, ?string $retryAfter, string $model): AiResponse
    {
        $json = json_decode($body, true);
        if ($status < 200 || $status > 299) {
            throw self::httpError($status, is_array($json) ? $json : [], $retryAfter);
        }
        if (!is_array($json)) {
            throw new AiError('invalid_json', false);
        }
        if (isset($json['promptFeedback']['blockReason'])) {
            throw new AiError('blocked', false);
        }
        $candidate = $json['candidates'][0] ?? null;
        $finish = is_array($candidate) && isset($candidate['finishReason']) ? (string) $candidate['finishReason'] : null;
        if (in_array($finish, ['SAFETY', 'RECITATION', 'BLOCKLIST', 'PROHIBITED_CONTENT', 'SPII', 'IMAGE_SAFETY'], true)) {
            throw new AiError('blocked', false);
        }
        if ($finish === 'MAX_TOKENS') {
            throw new AiError('truncated', false);
        }
        $text = '';
        foreach ((is_array($candidate) ? ($candidate['content']['parts'] ?? []) : []) as $part) {
            // Partes de "pensamento" não fazem parte da resposta.
            if (is_array($part) && isset($part['text']) && empty($part['thought'])) {
                $text .= (string) $part['text'];
            }
        }
        $text = trim($text);
        if ($text === '') {
            throw new AiError('empty', false);
        }
        $text = (string) preg_replace('/^```(?:json)?\s*|\s*```$/', '', $text);
        $data = json_decode($text, true, 64);
        if (!is_array($data) || array_is_list($data) && $data !== []) {
            throw new AiError('invalid_json', false);
        }
        $usage = is_array($json['usageMetadata'] ?? null) ? $json['usageMetadata'] : [];
        $int = static fn (string $k): ?int => isset($usage[$k]) && is_numeric($usage[$k]) ? (int) $usage[$k] : null;
        $output = $int('candidatesTokenCount') !== null || $int('thoughtsTokenCount') !== null
            ? (int) $int('candidatesTokenCount') + (int) $int('thoughtsTokenCount') : null;
        return new AiResponse($data, (string) ($json['modelVersion'] ?? $model), $finish, $int('promptTokenCount'), $output, $int('totalTokenCount'));
    }

    /** @param array<string,mixed> $json */
    private static function httpError(int $status, array $json, ?string $retryAfter): AiError
    {
        $after = null;
        if ($retryAfter !== null && ctype_digit(trim($retryAfter))) {
            $after = (int) trim($retryAfter);
        }
        foreach (($json['error']['details'] ?? []) as $detail) {
            if (is_array($detail) && isset($detail['retryDelay']) && preg_match('/^(\d+)(?:\.\d+)?s$/', (string) $detail['retryDelay'], $m)) {
                $after = (int) $m[1];
            }
        }
        return match (true) {
            $status === 401, $status === 403 => new AiError('auth', false),
            $status === 404, $status === 400 => new AiError('bad_request', false),
            $status === 429 => new AiError('rate_limited', true, $after),
            $status === 408, $status === 504 => new AiError('timeout', true, $after, true),
            $status >= 500 => new AiError('unavailable', true, $after),
            default => new AiError('bad_request', false),
        };
    }
}
