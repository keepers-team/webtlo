<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\Module\Report;

/** Тип формирования отчёта. */
enum CreationMode
{
    case CRON;
    case UI;
}
