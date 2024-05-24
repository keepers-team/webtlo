<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\TopicList\Filter;

enum SortDirection: int
{
    case UP   = 1;
    case DOWN = -1;

    public function sql(): string
    {
        return match ($this) {
            self::UP   => 'ASC',
            self::DOWN => 'DESC',
        };
    }
}
