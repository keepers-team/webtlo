<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\Enum;

/**
 * Метод отправки отчётов.
 */
enum SendReportMethod: int
{
    /**
     * Отчёт по каждому подразделу.
     */
    case Subsection = 1;

    /**
     * Просто отправка всех известных хранимых хешей раздач.
     */
    case Hash = 2;
}
