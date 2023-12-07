<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\TopicList\Filter;

enum SortDirection: int
{
    case UP   = 1;
    case DOWN = -1;
}