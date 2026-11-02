<?php

declare(strict_types=1);

namespace Concursei\Core\Http;

final class Route
{
    private string $regex;

    /**
     * @param array{0:class-string,1:string} $handler
     * @param list<string> $middleware
     * @param string $cache 'public' (pode ser cacheada se não houver sessão) ou 'private'
     */
    public function __construct(
        public readonly string $method,
        public readonly string $pattern,
        public readonly array $handler,
        public readonly array $middleware,
        public readonly ?string $name,
        public readonly string $cache = 'private',
    ) {
        $regex = preg_replace_callback('/\{(\w+)(?::([^}]+))?\}/', static function (array $m): string {
            $constraint = $m[2] ?? '[^/]+';
            return '(?P<' . $m[1] . '>' . $constraint . ')';
        }, $pattern);
        $this->regex = '#^' . $regex . '$#u';
    }

    /** @return array<string,string>|null */
    public function matches(string $path): ?array
    {
        if (!preg_match($this->regex, $path, $m)) {
            return null;
        }
        return array_filter($m, static fn ($k) => is_string($k), ARRAY_FILTER_USE_KEY);
    }
}
