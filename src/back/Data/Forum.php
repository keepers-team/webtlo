<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\Data;

use JsonSerializable;

/**
 * Данные подраздела.
 */
final class Forum implements JsonSerializable
{
    /**
     * @param int    $id    ид подраздела
     * @param string $name  название подраздела
     * @param int    $count количество раздач
     * @param int    $size  суммарный размер
     */
    public function __construct(
        public readonly int    $id,
        public readonly string $name,
        public readonly int    $count,
        public readonly int    $size
    ) {}

    /**
     * @return array{id: int, name: string, count: int, size: int}
     */
    public function jsonSerialize(): array
    {
        return (array) $this;
    }
}
