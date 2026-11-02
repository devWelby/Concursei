<?php

declare(strict_types=1);

namespace Concursei\Modules\Catalog;

use Concursei\Core\Clock;
use Concursei\Core\Database\Database;
use InvalidArgumentException;

/**
 * Cadastros de apoio: órgãos, bancas e cargos (carreiras). O nome da tabela
 * nunca vem da requisição — só das chaves de COLUMNS (allowlist).
 */
final class ReferenceRepository
{
    private const COLUMNS = [
        'organizations' => ['slug', 'name', 'acronym', 'sphere', 'uf', 'website_url'],
        'exam_boards' => ['slug', 'name', 'acronym', 'website_url'],
        'careers' => ['slug', 'name', 'area', 'education_level', 'description'],
    ];

    public function __construct(private Database $db, private Clock $clock)
    {
    }

    /** @return list<array<string,mixed>> */
    public function all(string $table): array
    {
        return $this->db->all('SELECT * FROM ' . $this->table($table) . ' ORDER BY name, id');
    }

    /** @return array<int,string> id => rótulo para <select> */
    public function options(string $table): array
    {
        $out = [];
        foreach ($this->all($table) as $row) {
            $acronym = $row['acronym'] ?? null;
            $out[(int) $row['id']] = $acronym !== null && $acronym !== '' ? $acronym . ' — ' . $row['name'] : (string) $row['name'];
        }
        return $out;
    }

    /** @return array<string,mixed>|null */
    public function find(string $table, int $id): ?array
    {
        return $this->db->one('SELECT * FROM ' . $this->table($table) . ' WHERE id = ?', [$id]);
    }

    public function exists(string $table, int $id): bool
    {
        return $this->db->scalar('SELECT 1 FROM ' . $this->table($table) . ' WHERE id = ?', [$id]) !== null;
    }

    /**
     * Insere ($id null) ou atualiza. Só as colunas da allowlist são gravadas.
     * @param array<string,mixed> $data
     * @throws SlugTaken
     */
    public function save(string $table, ?int $id, array $data): int
    {
        $table = $this->table($table);
        $cols = array_values(array_intersect(self::COLUMNS[$table], array_keys($data)));
        $values = array_map(static fn (string $c): mixed => $data[$c], $cols);
        $now = $this->clock->nowDb();
        try {
            if ($id === null) {
                return $this->db->insert(
                    sprintf('INSERT INTO %s (%s, created_at, updated_at) VALUES (%s, ?, ?)', $table, implode(', ', $cols), implode(', ', array_fill(0, count($cols), '?'))),
                    [...$values, $now, $now]
                );
            }
            $set = implode(', ', array_map(static fn (string $c): string => $c . ' = ?', $cols));
            $this->db->run(sprintf('UPDATE %s SET %s, updated_at = ? WHERE id = ?', $table, $set), [...$values, $now, $id]);
            return $id;
        } catch (\PDOException $e) {
            if (Database::isDuplicateKey($e)) {
                throw new SlugTaken('Endereço já usado.', 0, $e);
            }
            throw $e;
        }
    }

    private function table(string $table): string
    {
        if (!isset(self::COLUMNS[$table])) {
            throw new InvalidArgumentException('Tabela de referência desconhecida.');
        }
        return $table;
    }
}
