<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\Config;

use KeepersTeam\Webtlo\Helper;

/**
 * Параметры отправки и получения отчётов.
 */
final class ReportSend
{
    /**
     * @param bool  $sendReports         отправлять отчёты по хранимым подразделам
     * @param bool  $sendSummary         отправлять сводный отчёт
     * @param bool  $sendTelemetry       отправлять дополнительные сведения об установке программы и настройках
     * @param bool  $unsetOtherTopics    При отправке отчёта по подразделу, снимать признак хранения с раздач, которых больше нет в БД (в т.ч. разрегистрированные и обновлённые раздачи).
     * @param bool  $unsetOtherSubForums снимать признак хранения у подразделов, которые более не хранятся, согласно настроек
     * @param int   $daysUpdateExpire    количество дней обновления данных, после истечения которых, отправка отчётов невозможна
     * @param int[] $excludedSubForums   исключённые из отправки отчётов хранимые подразделы
     * @param int[] $excludedClients     исключённые из отправки отчётов торрент-клиенты
     * @param int[] $excludedKeepers     игнорируемые хранители
     */
    public function __construct(
        public readonly bool  $sendReports,
        public readonly bool  $sendSummary,
        public readonly bool  $sendTelemetry,
        public readonly bool  $unsetOtherTopics,
        public readonly bool  $unsetOtherSubForums,
        public readonly int   $daysUpdateExpire,
        public readonly array $excludedSubForums,
        public readonly array $excludedClients,
        public readonly array $excludedKeepers,
    ) {}

    /**
     * @param array<string, mixed> $cfg
     */
    public static function getReportSend(array $cfg): ReportSend
    {
        $report = $cfg['reports'] ?? [];

        return new ReportSend(
            sendReports        : (bool) ($report['send_report_api'] ?? 1),
            sendSummary        : (bool) ($report['send_summary_report'] ?? 1),
            sendTelemetry      : (bool) ($report['send_report_settings'] ?? 1),
            unsetOtherTopics   : (bool) ($report['unset_other_topics'] ?? 0),
            unsetOtherSubForums: (bool) ($report['unset_other_forums'] ?? 1),
            daysUpdateExpire   : (int) ($report['days_update_expire'] ?? 5),
            excludedSubForums  : Helper::explodeInt((string) ($report['exclude_forums_ids'] ?? '')),
            excludedClients    : Helper::explodeInt((string) ($report['exclude_clients_ids'] ?? '')),
            excludedKeepers    : Helper::explodeInt((string) ($report['exclude_keepers_ids'] ?? '')),
        );
    }
}
