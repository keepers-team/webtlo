<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\External\Api\V1;

/** Тип поиска раздач. */
enum TopicSearchMode: string
{
    case ID   = 'topic_id';
    case HASH = 'hash';

    /**
     * @return positive-int
     */
    public function paramsLimit(): int
    {
        return match ($this) {
            self::ID   => 100,
            /*
             * Hashes are longer, so to avoid HTTP 414 in legacy API
             * we're capping max identifiers per request.
             */
            self::HASH => 32,
        };
    }
}
