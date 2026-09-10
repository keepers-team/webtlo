<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\External\ApiReport;

/**
 * Возможные признаки статуса хранимой раздачи или подраздела.
 */
enum KeepingStatuses: int
{
    /**
     * Признак получения статуса через API.
     */
    case ReportedByApi = 0b00000000_00000000_00000000_00000001;

    /**
     * Признак скачиваемой раздачи.
     */
    case Downloading = 0b00000000_00000000_00000000_00000010;

    /**
     * Признак раздачи исключённой из отчётов.
     */
    case ExcludeFromReport = 0b00000000_00000000_00000000_00000100;

    /**
     * Признак импорта отчёта из постов на форуме.
     *
     * (Не актуально)
     */
    case ImportedFromForum = 0b00000000_00000001_00000000_00000000;

    /**
     * Флаг явного указания хранения раздач.
     *
     * Влияет на цвет отчёта в KP. Зелёный/голубой -> явно заявлено / по сидированию.
     */
    case IgnoreNonReported = 0b00000000_00000010_00000000_00000000;

    /**
     * Маска метода отправки отчёта.
     *
     * 0 - это WebTLO, потому практического применения нет.
     */
    case ReporterMethodMask = 0b00001111_00000000_00000000_00000000;

    /**
     * Маска ид инстанса WebTLO отправителя.
     *
     * 0 - по умолчанию.
     *
     * Фактически, используем 3 бита, значение от 0 до 7.
     */
    case ReporterIdMask = 0b00000000_00000000_11111111_00000000;

    /**
     * Сдвиг поля ReporterId.
     */
    public const REPORTER_ID_SHIFT = 8;

    /**
     * Желаемый статус подраздела.
     *
     * @param non-negative-int $reporterId
     *
     * @return non-negative-int
     */
    public static function buildSubForumStatus(int $reporterId): int
    {
        $status =  self::ReportedByApi->value | self::IgnoreNonReported->value;

        if ($reporterId > 0) {
            $status |= ($reporterId << self::REPORTER_ID_SHIFT) & self::ReporterIdMask->value;
        }

        return $status;
    }

    /**
     * Желаемый статус группы раздач.
     *
     * @param non-negative-int $reporterId
     *
     * @return non-negative-int
     */
    public static function buildTopicStatus(int $reporterId, bool $downloading = false): int
    {
        $status = self::ReportedByApi->value;

        if ($downloading) {
            $status |= self::Downloading->value;
        }

        if ($reporterId > 0) {
            $status |= ($reporterId << self::REPORTER_ID_SHIFT) & self::ReporterIdMask->value;

            // Очевидное приведение типов, потому что PHPStan не понимает побитовый сдвиг.
            $status = max(0, $status);
        }

        return $status;
    }
}
