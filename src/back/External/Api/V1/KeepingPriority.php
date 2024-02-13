<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\External\Api\V1;

/** Приоритет хранения раздачи. */
enum KeepingPriority: int
{
    case Low    = 0;
    case Normal = 1;
    case High   = 2;
}
