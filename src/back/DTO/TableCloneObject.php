<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\DTO;

final class TableCloneObject
{
    /**
     * @param string           $origin  название оригинальной таблицы
     * @param string           $clone   название временной таблицы хранения данных
     * @param array{}|string[] $keys    поля/ключи таблицы
     * @param string           $primary PRIMARY KEY таблицы
     */
    public function __construct(
        public readonly string $origin,
        public readonly string $clone,
        public readonly array  $keys,
        public readonly string $primary
    ) {}

    public function getKeysSelect(): string
    {
        if (count($this->keys)) {
            return implode(',', $this->keys);
        }

        return '*';
    }

    public function getKeysInsert(): string
    {
        if (count($this->keys)) {
            return sprintf('(%s)', implode(',', $this->keys));
        }

        return '';
    }
}
