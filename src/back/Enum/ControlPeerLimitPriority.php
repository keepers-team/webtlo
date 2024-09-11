<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\Enum;

/**
 * Приоритет разных значений лимита пиров при регулировке.
 */
enum ControlPeerLimitPriority: int
{
    case Subsection = 1;
    case Client     = 2;
}
