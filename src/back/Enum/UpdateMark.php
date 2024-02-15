<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\Enum;

/** Последнее обновление разных модулей */
enum UpdateMark: int
{
    /** Дерево подразделов. */
    case FORUM_TREE    = 9100;
    /** Списки хранимых раздач в подразделах. */
    case SUBSECTIONS   = 9101;
    /** Раздачи с высоким приоритетом хранения. */
    case HIGH_PRIORITY = 9102;
    /** Сканирование списков на форуме. */
    case FORUM_SCAN    = 9500;
    /** Раздачи в торрент-клиентах. */
    case CLIENTS       = 9600;
    /** Полное обновление всех сведений. */
    case FULL_UPDATE   = 9900;
    /** Успешная отправка отчётов. */
    case SEND_REPORT   = 9901;

    public function label(): string
    {
        return match ($this) {
            self::FORUM_TREE    => 'Дерево подразделов',
            self::SUBSECTIONS   => 'Списки хранимых раздач в подразделах',
            self::HIGH_PRIORITY => 'Раздачи с высоким приоритетом хранения',
            self::FORUM_SCAN    => 'Сканирование списков на форуме',
            self::CLIENTS       => 'Раздачи в торрент-клиентах',
            self::FULL_UPDATE   => 'Полное обновление всех сведений',
            self::SEND_REPORT   => 'Успешная отправка отчётов',
        };
    }
}
