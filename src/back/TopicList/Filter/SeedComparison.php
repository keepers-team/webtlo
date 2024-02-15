<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\TopicList\Filter;

/** Тип сравнения количества сидов раздачи. */
enum SeedComparison: int
{
    case INTERVAL = -1;
    case NO_LESS  = 0;
    case NO_MORE  = 1;
}
