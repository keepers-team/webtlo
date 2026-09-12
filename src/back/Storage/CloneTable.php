<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\Storage;

use KeepersTeam\Webtlo\Infrastructure\Database\ConnectionInterface;

final class CloneTable
{
    public function __construct(
        private readonly ConnectionInterface $con,
        private readonly CloneTableObject    $table,
    ) {}

    /**
     * @return array{}|string[]
     */
    public function getTableKeys(): array
    {
        return $this->table->keys;
    }

    public function getTableObject(): CloneTableObject
    {
        return $this->table;
    }

    /**
     * Создание временной таблицы с заданным именем.
     */
    public function createClone(string $cloneName): void
    {
        $tableKeysString = $this->table->getKeysSelect();

        $sql = "
            CREATE TEMP TABLE $cloneName
            AS
            SELECT $tableKeysString
            FROM {$this->table->origin}
            WHERE FALSE
        ";

        $this->con->executeStatement(sql: $sql);
    }

    /**
     * Запись данных во временную таблицу порциями.
     *
     * @param array<int|string, mixed> $dataSet
     * @param int<1, max>              $chunkSize
     */
    public function cloneFillChunk(array $dataSet, int $chunkSize = 500): void
    {
        $dataSet = array_chunk($dataSet, $chunkSize, true);

        foreach ($dataSet as $chunk) {
            $this->cloneFill(dataSet: $chunk);
        }
    }

    /**
     * Запись данных во временную таблицу.
     *
     * @param array<int|string, mixed> $dataSet
     */
    public function cloneFill(array $dataSet): void
    {
        $keys = count($this->table->keys) ? sprintf('(%s)', implode(',', $this->table->keys)) : '';

        $rows = $this->combineDataSet(dataSet: $dataSet, primaryKey: $this->table->primary);

        $sql = "INSERT INTO {$this->table->clone} $keys $rows";

        $this->con->executeStatement(sql: $sql);
    }

    /**
     * Проверить наличие записей в таблице-клоне и перенести их в основную таблицу.
     */
    public function writeTable(): int
    {
        $count = $this->cloneCount();
        if ($count > 0) {
            $this->moveToOrigin();
        }

        return $count;
    }

    /**
     * Переместить записи из временной таблицы в основную.
     */
    public function moveToOrigin(): void
    {
        $insKeys = $this->table->getKeysInsert();
        $selKeys = $this->table->getKeysSelect();

        $sql = "
            INSERT INTO {$this->table->origin}
                $insKeys
            SELECT $selKeys
            FROM {$this->table->clone}
        ";

        $this->con->executeStatement(sql: $sql);
    }

    public function querySelectPrimaryClone(): string
    {
        return "SELECT {$this->table->primary} FROM {$this->table->clone}";
    }

    /**
     * Количество строк во временной таблице.
     */
    public function cloneCount(): int
    {
        return $this->con->selectRowsCount(table: $this->table->clone);
    }

    /**
     * Очистить временную таблицу.
     */
    public function clearClone(): void
    {
        $sql = "DELETE FROM {$this->table->clone} WHERE TRUE";

        $this->con->executeStatement(sql: $sql);
    }

    /**
     * Удалить строки в оригинальной таблице, которых нет во временной.
     */
    public function clearUnusedRows(): void
    {
        $sql = "
            DELETE FROM {$this->table->origin}
            WHERE {$this->table->primary} NOT IN (
                SELECT {$this->table->primary}
                FROM {$this->table->clone}
            )
        ";

        $this->con->executeStatement(sql: $sql);
    }

    /**
     * Заменить данные хранителей для известных тем указанного подраздела.
     *
     * Актуально только для KeepersLists и KeepersSeeders.
     * Новые темы без строки в Topics тоже сохраняются до обновления списка тем.
     * Вызывать внутри транзакции вместе с обновлением второй таблицы и маркера.
     */
    public function replaceKeepersRows(int $forumId): void
    {
        $tab = $this->table;
        $keys = implode(', ', array_map(static fn(string $key) => "tmp.$key", $tab->keys));
        $insertKeys = $tab->getKeysInsert();

        $this->con->executeStatement(
            "
                DELETE FROM $tab->origin
                WHERE topic_id IN (SELECT id FROM Topics WHERE forum_id = ?)
            ",
            [$forumId]
        );

        // Темы без строки в Topics оставляем до обновления списка тем.
        // Известные темы другого подраздела отчёт менять не должен.
        $this->con->executeStatement(
            "
                INSERT INTO $tab->origin $insertKeys
                SELECT $keys FROM $tab->clone AS tmp
                LEFT JOIN Topics AS topic ON topic.id = tmp.topic_id
                WHERE topic.forum_id = ? OR topic.forum_id IS NULL
            ",
            [$forumId]
        );
    }

    /**
     * @param array<array-key, mixed> $dataSet
     */
    private function combineDataSet(array $dataSet, string $primaryKey = 'id'): string
    {
        // Экранирование строк.
        $quote = $this->con->getPdo()->quote(...);

        $rows = [];
        foreach ($dataSet as $id => $value) {
            $value = array_map(static function($elem) use ($quote) {
                return is_numeric($elem) ? $elem : $quote((string) $elem);
            }, $value);

            $rows[] = (empty($value[$primaryKey]) ? "$id," : '') . implode(',', $value);
        }

        return 'SELECT ' . implode(' UNION ALL SELECT ', $rows);
    }
}
