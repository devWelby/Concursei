<?php

declare(strict_types=1);

namespace Concursei\Core\Database;

use PDO;
use PDOException;
use PDOStatement;
use Throwable;

/**
 * Wrapper PDO: consultas preparadas, modo estrito, UTC, utf8mb4.
 * Conexão preguiçosa e não persistente (hospedagem compartilhada tem limite de conexões).
 */
class Database
{
    private ?PDO $pdo = null;
    private int $txDepth = 0;

    /** @param array{host:string,port:int,database:string,username:string,password:string} $cfg */
    public function __construct(private array $cfg)
    {
    }

    public function pdo(): PDO
    {
        if ($this->pdo === null) {
            $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $this->cfg['host'], $this->cfg['port'], $this->cfg['database']);
            $this->pdo = new PDO($dsn, $this->cfg['username'], $this->cfg['password'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_STRINGIFY_FETCHES => false,
                PDO::ATTR_PERSISTENT => false,
                PDO::ATTR_TIMEOUT => 5,
                PDO::MYSQL_ATTR_MULTI_STATEMENTS => false,
            ]);
            $this->pdo->exec("SET time_zone = '+00:00', sql_mode = 'STRICT_ALL_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION,ONLY_FULL_GROUP_BY', NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
        }
        return $this->pdo;
    }

    public function isConnected(): bool
    {
        return $this->pdo !== null;
    }

    public function disconnect(): void
    {
        $this->pdo = null;
        $this->txDepth = 0;
    }

    /** @param array<string|int,mixed> $params */
    public function run(string $sql, array $params = []): PDOStatement
    {
        $stmt = $this->pdo()->prepare($sql);
        foreach ($params as $key => $value) {
            $name = is_int($key) ? $key + 1 : (str_starts_with($key, ':') ? $key : ':' . $key);
            $type = match (true) {
                is_int($value) => PDO::PARAM_INT,
                is_bool($value) => PDO::PARAM_BOOL,
                $value === null => PDO::PARAM_NULL,
                default => PDO::PARAM_STR,
            };
            $stmt->bindValue($name, $value, $type);
        }
        $stmt->execute();
        return $stmt;
    }

    /** @return array<string,mixed>|null */
    public function one(string $sql, array $params = []): ?array
    {
        $row = $this->run($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    /** @return list<array<string,mixed>> */
    public function all(string $sql, array $params = []): array
    {
        return $this->run($sql, $params)->fetchAll();
    }

    public function scalar(string $sql, array $params = []): mixed
    {
        $v = $this->run($sql, $params)->fetchColumn();
        return $v === false ? null : $v;
    }

    public function insert(string $sql, array $params = []): int
    {
        $this->run($sql, $params);
        return (int) $this->pdo()->lastInsertId();
    }

    public function affected(string $sql, array $params = []): int
    {
        return $this->run($sql, $params)->rowCount();
    }

    /**
     * Executa em transação. Reentrante (usa a transação externa).
     * Repete em deadlock/lock wait timeout (1213/1205) somente no nível externo.
     *
     * @template T
     * @param callable(Database):T $fn
     * @return T
     */
    public function transaction(callable $fn, int $retries = 2): mixed
    {
        if ($this->txDepth > 0) {
            $this->txDepth++;
            try {
                return $fn($this);
            } finally {
                $this->txDepth--;
            }
        }
        $attempt = 0;
        while (true) {
            $this->pdo()->beginTransaction();
            $this->txDepth = 1;
            try {
                $result = $fn($this);
                $this->pdo()->commit();
                $this->txDepth = 0;
                return $result;
            } catch (Throwable $e) {
                $this->txDepth = 0;
                if ($this->pdo()->inTransaction()) {
                    $this->pdo()->rollBack();
                }
                $code = $e instanceof PDOException ? (int) ($e->errorInfo[1] ?? 0) : 0;
                if (in_array($code, [1213, 1205], true) && $attempt < $retries) {
                    $attempt++;
                    usleep(random_int(20_000, 120_000) * $attempt);
                    continue;
                }
                throw $e;
            }
        }
    }

    public function inTransaction(): bool
    {
        return $this->txDepth > 0;
    }

    /** True quando a exceção é violação de chave única (1062). */
    public static function isDuplicateKey(Throwable $e): bool
    {
        return $e instanceof PDOException && (int) ($e->errorInfo[1] ?? 0) === 1062;
    }

    /** Escapa curingas de LIKE (o valor continua indo por parâmetro). */
    public static function likeEscape(string $term): string
    {
        return addcslashes($term, '%_\\');
    }
}
