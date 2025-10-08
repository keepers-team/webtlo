<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\Storage\Traits;

use PDO;
use PDOException;
use PDOStatement;
use RuntimeException;
use Throwable;

trait DbQuery
{
    /**
     * Подготовить запрос и выполнить с параметрами.
     *
     * @param (int|string)[] $param
     */
    public function executeStatement(string $sql, array $param = []): PDOStatement
    {
        try {
            $sth = $this->db->prepare($sql);
            if ($sth === false) {
                throw new PDOException('Cant create PDOStatement');
            }

            $sth->execute($param);

            return $sth;
        } catch (Throwable $e) {
            $this->rollbackTransaction();

            $this->logger->error(
                'SQL. Ошибка выполнения запроса',
                ['method' => 'executeStatement', 'exception' => $e, 'query' => $sql, 'param' => $param]
            );

            throw new RuntimeException($e->getMessage(), (int) $e->getCode(), $e);
        }
    }

    /**
     * Запрос набора строк.
     *
     * @param (int|string)[] $param
     *
     * @return array<int, mixed>
     */
    public function query(string $sql, array $param = [], int $pdo = PDO::FETCH_ASSOC): array
    {
        $sth = $this->executeStatement($sql, $param);

        return (array) $sth->fetchAll($pdo);
    }

    /**
     * Запрос одной строки.
     *
     * @param (int|string)[] $param
     *
     * @return ?array<string, mixed>
     */
    public function queryRow(string $sql, array $param = []): ?array
    {
        $sth = $this->executeStatement($sql, $param);

        $result = $sth->fetch(PDO::FETCH_ASSOC);
        if ($result === false) {
            return null;
        }

        return $result;
    }

    /**
     * Запрос одной ячейки.
     *
     * @param (int|string)[] $param
     *
     * @return null|int|string
     */
    public function queryColumn(string $sql, array $param = []): mixed
    {
        $sth = $this->executeStatement($sql, $param);

        return $sth->fetch(PDO::FETCH_COLUMN);
    }

    /**
     * Запрос count счётчика.
     *
     * @param (int|string)[] $param
     */
    public function queryCount(string $sql, array $param = []): int
    {
        return (int) $this->queryColumn($sql, $param);
    }

    /**
     * Получить количество изменённых строк предыдущим запросом.
     */
    public function queryChanges(): int
    {
        return (int) $this->queryColumn('SELECT CHANGES()');
    }

    /**
     * Запрос количество строк в таблице.
     */
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

    /**
     * Выполнить готовый запрос к БД.
     */
    protected function executeQuery(string $sql): void
    {
        try {
            $this->db->exec($sql);
        } catch (Throwable $e) {
            $this->logger->error(
                'SQL. Ошибка выполнения запроса',
                ['method' => 'executeQuery', 'exception' => $e, 'query' => $sql]
            );

            throw new RuntimeException($e->getMessage(), (int) $e->getCode(), $e);
        }
    }
}
