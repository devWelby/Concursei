<?php

declare(strict_types=1);

/**
 * Helpers globais mínimos para templates. Escape contextual:
 *  - e()    : texto e atributos HTML entre aspas
 *  - e_url(): URL em href/src (bloqueia javascript:, data: etc.)
 *  - e_json(): dados para <script type="application/json"> (nunca innerHTML)
 */

if (!function_exists('e')) {
    function e(mixed $value): string
    {
        if ($value === null || is_array($value) || (is_object($value) && !method_exists($value, '__toString'))) {
            return '';
        }
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }
}

if (!function_exists('e_url')) {
    function e_url(?string $url): string
    {
        $url = trim((string) $url);
        if ($url === '') {
            return '';
        }
        // Permite caminhos locais ("/x", não "//host") e http(s) absolutos.
        $isLocal = str_starts_with($url, '/') && !str_starts_with($url, '//') && !str_starts_with($url, '/\\');
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if (!$isLocal && !in_array($scheme, ['http', 'https', 'mailto'], true)) {
            return '#';
        }
        return e($url);
    }
}

if (!function_exists('e_json')) {
    function e_json(mixed $data): string
    {
        return (string) json_encode(
            $data,
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );
    }
}
