<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\Storage;

use KeepersTeam\Webtlo\DB;
use KeepersTeam\Webtlo\DTO\TableCloneObject;

final class CloneTable
{
    public function __construct(
        private readonly DB              $db,
        private readonly TableCloneObject $table,
    ) {
    }

    /**
     * @return array{}|string[]
     */
    public function getTableKeys(): array
    {
        return $this->table->keys;
    }

    public function getTableObject(): TableCloneObject
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

        $this->db->executeStatement(sql: $sql);
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

        $rows = $this->db->combineDataSet(dataSet: $dataSet, primaryKey: $this->table->primary);

        $sql = "INSERT INTO {$this->table->clone} $keys $rows";

        $this->db->executeStatement(sql: $sql);
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

        $this->db->executeStatement(sql: $sql);
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
        return $this->db->selectRowsCount(table: $this->table->clone);
    }

    /**
     * Очистить временную таблицу.
     */
    public function clearClone(): void
    {
        $sql = "DELETE FROM {$this->table->clone} WHERE TRUE";

        $this->db->executeStatement(sql: $sql);
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

        $this->db->executeStatement(sql: $sql);
    }

    /**
     * Удалить ненужные строки о хранимых раздачах хранителей.
     *
     * Актуально только для KeepersLists и KeepersSeeders.
     */
    public function removeUnusedKeepersRows(): void
    {
        $tab = $this->table;

        $this->db->executeStatement(
            "
                DELETE FROM $tab->origin
                WHERE topic_id || keeper_id NOT IN (
                    SELECT upd.topic_id || upd.keeper_id
                    FROM $tab->clone AS tmp
                    LEFT JOIN $tab->origin AS upd ON tmp.topic_id = upd.topic_id AND tmp.keeper_id = upd.keeper_id
                    WHERE upd.topic_id IS NOT NULL
                )
            "
        );
    }
}
