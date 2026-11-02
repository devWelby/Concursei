<?php

declare(strict_types=1);

namespace Concursei\Core\Security;

use RuntimeException;

/**
 * Primitivas padrão (sem criptografia caseira): AES-256-GCM (OpenSSL) para
 * cifrar segredos em repouso (ex.: semente TOTP) e HMAC-SHA256 para
 * pseudonimizar identificadores (IP) e derivar chaves de busca. As subchaves
 * são derivadas do APP_KEY via HKDF com rótulos distintos.
 */
final class Crypto
{
    private const CIPHER = 'aes-256-gcm';
    private const VERSION = "\x01";

    private string $encKey;
    private string $macKey;

    public function __construct(string $appKey)
    {
        $raw = self::decodeKey($appKey);
        $this->encKey = hash_hkdf('sha256', $raw, 32, 'concursei:enc:v1');
        $this->macKey = hash_hkdf('sha256', $raw, 32, 'concursei:mac:v1');
    }

    public static function decodeKey(string $appKey): string
    {
        $value = str_starts_with($appKey, 'base64:') ? base64_decode(substr($appKey, 7), true) : false;
        if ($value === false || strlen($value) < 32) {
            throw new RuntimeException('APP_KEY ausente ou fraca (use: php bin/console key:generate).');
        }
        return $value;
    }

    public static function generateKey(): string
    {
        return 'base64:' . base64_encode(random_bytes(32));
    }

    public function encrypt(string $plaintext, string $aad = ''): string
    {
        $iv = random_bytes(12);
        $tag = '';
        $ct = openssl_encrypt($plaintext, self::CIPHER, $this->encKey, OPENSSL_RAW_DATA, $iv, $tag, $aad, 16);
        if ($ct === false) {
            throw new RuntimeException('Falha ao cifrar.');
        }
        return base64_encode(self::VERSION . $iv . $tag . $ct);
    }

    public function decrypt(string $payload, string $aad = ''): string
    {
        $bin = base64_decode($payload, true);
        if ($bin === false || strlen($bin) < 29 || $bin[0] !== self::VERSION) {
            throw new RuntimeException('Conteúdo cifrado inválido.');
        }
        $iv = substr($bin, 1, 12);
        $tag = substr($bin, 13, 16);
        $ct = substr($bin, 29);
        $pt = openssl_decrypt($ct, self::CIPHER, $this->encKey, OPENSSL_RAW_DATA, $iv, $tag, $aad);
        if ($pt === false) {
            throw new RuntimeException('Falha ao decifrar (chave incorreta ou conteúdo adulterado).');
        }
        return $pt;
    }

    /** HMAC binário de 32 bytes (para colunas BINARY(32)). */
    public function mac(string $purpose, string $value): string
    {
        return hash_hmac('sha256', $purpose . "\0" . $value, $this->macKey, true);
    }

    /** Token aleatório URL-safe (256 bits por padrão). */
    public static function randomToken(int $bytes = 32): string
    {
        return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
    }

    /** Hash de token para armazenamento (o token em claro nunca vai ao banco). */
    public static function tokenHash(string $token): string
    {
        return hash('sha256', $token, true);
    }
}
