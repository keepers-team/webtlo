<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\Traits;

use KeepersTeam\Webtlo\Legacy\Log;
use PDO;
use PDOException;
use PDOStatement;
use RuntimeException;
use Throwable;

trait DbQueryTrait
{
    /**
     * Подготовить запрос и выполнить с параметрами.
     *
     * @param string         $sql
     * @param (int|string)[] $param
     * @return PDOStatement
     */
    public function executeStatement(string $sql, array $param = []): PDOStatement
    {
        try {
            $sth = $this->db->prepare($sql);
            if (false === $sth) {
                throw new PDOException('Cant create PDOStatement');
            }

            $sth->execute($param);

            return $sth;
        } catch (Throwable $e) {
            $this->rollbackTransaction();

            Log::append($sql);
            throw new RuntimeException($e->getMessage(), (int)$e->getCode(), $e);
        }
    }

    /**
     * Запрос набора строк.
     *
     * @param string         $sql
     * @param (int|string)[] $param
     * @param int            $pdo
     * @return array<int, mixed>
     */
    public function query(string $sql, array $param = [], int $pdo = PDO::FETCH_ASSOC): array
    {
        $sth = $this->executeStatement($sql, $param);

        return (array)$sth->fetchAll($pdo);
    }

    /**
     * Запрос одной строки.
     *
     * @param string         $sql
     * @param (int|string)[] $param
     * @param int            $pdo
     * @return int|string
     */
    public function queryRow(string $sql, array $param = [], int $pdo = PDO::FETCH_ASSOC): mixed
    {
        $sth = $this->executeStatement($sql, $param);

        return $sth->fetch($pdo);
    }

    /**
     * Запрос одной ячейки.
     *
     * @param string         $sql
     * @param (int|string)[] $param
     * @return mixed
     */
    public function queryColumn(string $sql, array $param = []): mixed
    {
        return $this->queryRow($sql, $param, PDO::FETCH_COLUMN);
    }

    /**
     * Запрос count счётчика.
     *
     * @param string         $sql
     * @param (int|string)[] $param
     * @return int
     */
    public function queryCount(string $sql, array $param = []): int
    {
        return (int)($this->queryColumn($sql, $param) ?? 0);
    }

    /** Запрос количество строк в таблице. */
    public function selectRowsCount(string $table): int
    {
        return $this->queryCount("SELECT COUNT() FROM $table");
    }

    public function beginTransaction(): void
    {
        $this->db->beginTransaction();
    }

    public function commitTransaction(): void
    {
        if ($this->db->inTransaction()) {
            $this->db->commit();
        }
    }

    public function rollbackTransaction(): void
    {
        if ($this->db->inTransaction()) {
            $this->db->rollBack();
        }
    }

    /** Выполнить готовый запрос к БД. */
    protected function executeQuery(string $sql): void
    {
        try {
            $this->db->exec($sql);
        } catch (Throwable $e) {
            Log::append($sql);
            throw new RuntimeException($e->getMessage(), (int)$e->getCode(), $e);
        }
    }
}
