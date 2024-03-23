<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\External\ApiReport;

enum KeepingStatuses: int
{
    case ReportedByApi     = 0b00000000_00000000_00000000_00000001;
    case Downloading       = 0b00000000_00000000_00000000_00000010;
    case ExcludeFromReport = 0b00000000_00000000_00000000_00000100;
    case PriorityMask      = 0b00000000_00000000_11111111_00000000;
    case ImportedFromForum = 0b00000000_00000001_00000000_00000000;
    case IgnoreNonReported = 0b00000000_00000010_00000000_00000000;
}
