<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\Config;

use KeepersTeam\Webtlo\Enum\SendReportMethod as Method;

/**
 * Параметры отправки и получения отчётов.
 */
final class ReportSend
{
    /**
     * @param bool      $sendReports         отправлять ли отчёт в целом
     * @param Method    $sendMethod          метод отправки отчётов (подразделы|хеши)
     * @param int<0, 7> $reporterId          порядковый номер инстанса
     * @param bool      $sendTelemetry       отправлять дополнительные сведения об установке программы и настройках
     * @param bool      $excludeAuthored     исключить ли свои раздачи из отчёта
     * @param bool      $unsetOtherTopics    при отправке отчёта по подразделу, снимать признак хранения с раздач, которых больше нет в БД (в т.ч. разрегистрированные и обновлённые раздачи)
     * @param bool      $unsetOtherSubForums снимать признак хранения у подразделов, которые более не хранятся, согласно настроек
     * @param int       $daysUpdateExpire    количество дней обновления данных, после истечения которых, отправка отчётов невозможна
     * @param int[]     $excludedSubForums   исключённые из отправки отчётов хранимые подразделы
     * @param int[]     $excludedClients     исключённые из отправки отчётов торрент-клиенты
     * @param int[]     $excludedKeepers     игнорируемые хранители
     */
    public function __construct(
        public readonly bool   $sendReports,
        public readonly Method $sendMethod,
        public readonly int    $reporterId,
        public readonly bool   $sendTelemetry,
        public readonly bool   $excludeAuthored,
        public readonly bool   $unsetOtherTopics,
        public readonly bool   $unsetOtherSubForums,
        public readonly int    $daysUpdateExpire,
        public readonly array  $excludedSubForums,
        public readonly array  $excludedClients,
        public readonly array  $excludedKeepers,
    ) {}
}
