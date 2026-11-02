<?php

declare(strict_types=1);

namespace Concursei\Core\Database;

use RuntimeException;

/**
 * Migrações SQL incrementais (database/migrations/NNNN_nome.sql), execução serial
 * com lock nomeado e checksum. ATENÇÃO: DDL no MySQL/MariaDB faz commit implícito,
 * portanto NÃO há rollback transacional de migração. Recuperação: backup prévio
 * + migração de correção (ver docs/OPERATIONS.md).
 */
final class Migrator
{
    private const LOCK = 'concursei_migrations';

    public function __construct(private Database $db, private string $dir)
    {
    }

    private function ensureTable(): void
    {
        $this->db->run('CREATE TABLE IF NOT EXISTS schema_migrations (
            version INT UNSIGNED NOT NULL PRIMARY KEY,
            name VARCHAR(190) NOT NULL,
            checksum CHAR(64) NOT NULL,
            applied_at DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    }

    /** @return array<int,array{version:int,name:string,path:string,checksum:string}> */
    public function available(): array
    {
        $out = [];
        foreach (glob($this->dir . '/*.sql') ?: [] as $path) {
            if (!preg_match('/^(\d{4})_([a-z0-9_]+)\.sql$/', basename($path), $m)) {
                throw new RuntimeException('Nome de migração inválido: ' . basename($path));
            }
            $version = (int) $m[1];
            if (isset($out[$version])) {
                throw new RuntimeException('Versão de migração duplicada: ' . $m[1]);
            }
            $sql = (string) file_get_contents($path);
            $out[$version] = ['version' => $version, 'name' => $m[2], 'path' => $path, 'checksum' => hash('sha256', str_replace("\r\n", "\n", $sql))];
        }
        ksort($out);
        return $out;
    }

    /** @return array<int,array{name:string,checksum:string}> */
    public function applied(): array
    {
        $this->ensureTable();
        $rows = $this->db->all('SELECT version, name, checksum FROM schema_migrations ORDER BY version');
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['version']] = ['name' => (string) $r['name'], 'checksum' => (string) $r['checksum']];
        }
        return $out;
    }

    /**
     * @param callable(string):void|null $log
     * @return list<string> migrações aplicadas nesta execução
     */
    public function migrate(?callable $log = null): array
    {
        $log ??= static function (string $m): void {
        };
        $this->ensureTable();
        if ((int) $this->db->scalar('SELECT GET_LOCK(?, 30)', [self::LOCK]) !== 1) {
            throw new RuntimeException('Outra migração está em execução (lock não obtido).');
        }
        try {
            $applied = $this->applied();
            foreach ($applied as $v => $info) {
                $file = $this->available()[$v] ?? null;
                if ($file !== null && $file['checksum'] !== $info['checksum']) {
                    throw new RuntimeException(sprintf('Migração %04d foi alterada após aplicada (checksum divergente). Crie uma nova migração de correção.', $v));
                }
            }
            $done = [];
            foreach ($this->available() as $v => $m) {
                if (isset($applied[$v])) {
                    continue;
                }
                $log(sprintf('Aplicando %04d_%s...', $v, $m['name']));
                $statements = self::split((string) file_get_contents($m['path']));
                foreach ($statements as $i => $stmt) {
                    try {
                        $this->db->pdo()->exec($stmt);
                    } catch (\PDOException $e) {
                        throw new RuntimeException(sprintf('Falha na migração %04d, instrução %d: %s', $v, $i + 1, $e->getMessage()), 0, $e);
                    }
                }
                $this->db->run('INSERT INTO schema_migrations (version, name, checksum, applied_at) VALUES (?, ?, ?, UTC_TIMESTAMP())', [$v, $m['name'], $m['checksum']]);
                $done[] = sprintf('%04d_%s', $v, $m['name']);
            }
            return $done;
        } finally {
            $this->db->scalar('SELECT RELEASE_LOCK(?)', [self::LOCK]);
        }
    }

    /**
     * Divide o arquivo em instruções terminadas por ";" no fim da linha.
     * Comentários "-- " de linha inteira são removidos. Não suporta DELIMITER/procedures (não usados).
     * @return list<string>
     */
    public static function split(string $sql): array
    {
        $sql = str_replace("\r\n", "\n", $sql);
        $lines = array_filter(explode("\n", $sql), static fn (string $l): bool => !preg_match('/^\s*--(\s|$)/', $l));
        $parts = preg_split('/;\s*$/m', implode("\n", $lines)) ?: [];
        return array_values(array_filter(array_map('trim', $parts), static fn (string $s): bool => $s !== ''));
    }
}
