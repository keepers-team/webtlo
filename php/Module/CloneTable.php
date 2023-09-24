<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\Module;

use Db;

/**
 * Описание таблицы и её временной копии.
 */
final class CloneTable
{
    private function __construct(
        public readonly string $origin,
        public readonly string $clone,
        public readonly array  $keys = [],
        public readonly string $primary = 'id'
    ) {
    }

    /**
     * Создать временую таблицу и вернуть объект.
     */
    public static function create(
        string $table,
        array $keys = [],
        string $primary = 'id',
        string $prefix = 'New'
    ): self {
        $clone = Db::temp_copy_table($table, $keys, $prefix);

        return new self($table, $clone, $keys, $primary);
    }

    /**
     * Запись данных во временную таблицу.
     */
    public function cloneFill(array $dataset): void
    {
        Db::table_insert_dataset($this->clone, $dataset, $this->primary, $this->keys);
    }

    /**
     * Запись данных во временную таблицу порциями.
     */
    public function cloneFillChunk(array $dataset, int $chunkSize = 500): void
    {
        $dataset = array_chunk($dataset, $chunkSize, true);
        foreach ($dataset as $chunk) {
            $this->cloneFill($chunk);
            unset($chunk);
        }
    }

    /**
     * Количество строк во временной таблице.
     */
    public function cloneCount(): int
    {
        return Db::select_count($this->clone);
    }

    /**
     * Переместить записи из временной таблицы в основную.
     */
    public function moveToOrigin(): void
    {
        Db::table_insert_temp($this->origin, $this->clone, $this->keys);
    }

    /** Очистить временную таблицу. */
    public function clearClone(): void
    {
        $sql = "DELETE FROM $this->clone";
        Db::query_database($sql);
    }

    /**
     * Удалить строки в оригинале, которых нет во временной таблице.
     */
    public function clearUnusedRows(): void
    {
        $sql = "DELETE FROM $this->origin WHERE $this->primary NOT IN ( SELECT $this->primary FROM $this->clone )";
        Db::query_database($sql);
    }
}