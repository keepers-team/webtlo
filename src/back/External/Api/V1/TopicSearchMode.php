<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\External\Api\V1;

/** Тип поиска раздач. */
enum TopicSearchMode: string
{
    case ID   = 'topic_id';
    case HASH = 'hash';
}
