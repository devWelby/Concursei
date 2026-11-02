<?php

declare(strict_types=1);

namespace Concursei\Core\Net;

/**
 * Transporte cURL do coletor (D-017).
 * - Conexão fixada no IP validado via CURLOPT_RESOLVE: TLS/SNI e verificação de certificado continuam
 *   valendo para o nome do host; o cURL não faz nova consulta DNS (impede rebinding).
 * - Sem redirecionamento automático, sem proxy do ambiente, somente HTTPS.
 * - Tamanho limitado após descompressão (o callback conta bytes já decodificados); tipo MIME conferido
 *   antes de aceitar o corpo de respostas 2xx.
 */
final class CurlTransport implements HttpTransport
{
    public function __construct(private string $userAgent)
    {
    }

    public function send(PinnedRequest $request): RawResponse
    {
        $target = $request->target;
        $address = IpGuard::isIpv6($request->ip) ? '[' . $request->ip . ']' : $request->ip;
        $headers = [];
        $status = 0;
        $body = '';
        $abort = null;

        $outgoing = [];
        foreach ($request->headers as $name => $value) {
            if (preg_match('/^[A-Za-z0-9-]+$/', $name) && !preg_match('/[\r\n\0]/', $value)) {
                $outgoing[] = $name . ': ' . $value;
            }
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $target->url,
            CURLOPT_RESOLVE => [$target->host . ':' . $target->port . ':' . $address],
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROXY => '',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_CONNECTTIMEOUT => $request->connectTimeout,
            CURLOPT_TIMEOUT => $request->timeout,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXFILESIZE => $request->maxBytes,
            CURLOPT_USERAGENT => $this->userAgent,
            CURLOPT_HTTPHEADER => $outgoing,
            CURLOPT_HTTPGET => true,
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$headers, &$status): int {
                if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) {
                    $status = (int) $m[1];
                    $headers = [];
                } elseif (str_contains($line, ':')) {
                    [$name, $value] = explode(':', $line, 2);
                    $headers[strtolower(trim($name))] = trim($value);
                }
                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => static function ($ch, string $chunk) use (&$body, &$abort, &$status, &$headers, $request): int {
                if ($status >= 200 && $status < 300 && $body === '') {
                    $mime = strtolower(trim(explode(';', $headers['content-type'] ?? '')[0]));
                    if (!in_array($mime, $request->acceptTypes, true)) {
                        $abort = 'type';
                        return -1;
                    }
                }
                if (strlen($body) + strlen($chunk) > $request->maxBytes) {
                    $abort = 'size';
                    return -1;
                }
                $body .= $chunk;
                return strlen($chunk);
            },
        ]);
        $ok = curl_exec($ch);
        $errno = curl_errno($ch);
        $primaryIp = (string) curl_getinfo($ch, CURLINFO_PRIMARY_IP);
        unset($ch); // libera o handle (curl_close é obsoleto desde o PHP 8.0)

        if ($abort === 'type') {
            throw new FetchFailed('Tipo de conteúdo não aceito.', false, $status);
        }
        if ($abort === 'size' || $errno === CURLE_FILESIZE_EXCEEDED) {
            throw new FetchFailed('Resposta maior que o limite permitido.', false, $status);
        }
        if ($ok === false || $errno !== 0) {
            throw match ($errno) {
                CURLE_OPERATION_TIMEOUTED, CURLE_COULDNT_CONNECT, CURLE_RECV_ERROR, CURLE_SEND_ERROR, CURLE_GOT_NOTHING
                    => new FetchFailed('Falha de conexão ou tempo esgotado (cURL ' . $errno . ').', true),
                CURLE_SSL_CACERT, CURLE_SSL_CONNECT_ERROR
                    => new FetchFailed('Falha na verificação TLS (cURL ' . $errno . ').', false),
                default => new FetchFailed('Falha na requisição (cURL ' . $errno . ').', true),
            };
        }
        // Defesa adicional: a conexão precisa ter ido para o endereço validado.
        if ($primaryIp !== '' && @inet_pton($primaryIp) !== @inet_pton($request->ip)) {
            throw new UnsafeUrl('Conexão estabelecida com endereço diferente do validado.');
        }
        return new RawResponse($status, $headers, $body, $primaryIp);
    }
}
