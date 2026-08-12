<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\External\Data;

/**
 * Тип поиска раздач.
 */
enum TopicSearchMode: string
{
    case ID   = 'id';
    case HASH = 'hash';

    /**
     * Количество раздач (параметров) в одном запросе для POST запроса.
     *
     * @return positive-int
     */
    public function postParamChunkSize(): int
    {
        return match ($this) {
            self::ID   => 1500,
            self::HASH => 1000,
        };
    }
}
