<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\Action;

use DateTimeImmutable;
use KeepersTeam\Webtlo\Config\ReportSend as ConfigReport;
use KeepersTeam\Webtlo\Enum\UpdateMark;
use KeepersTeam\Webtlo\Module\Report\CreateReport;
use KeepersTeam\Webtlo\Module\Report\EmptyFoundTopicsException;
use KeepersTeam\Webtlo\Module\Report\SendReport;
use KeepersTeam\Webtlo\Storage\Table\UpdateTime;
use KeepersTeam\Webtlo\Timers;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Отправка всех возможных отчётов во всё возможные места их размещения.
 */
final class SendKeeperReports
{
    /**
     * Дата полного обновления сведений.
     * Она же считается датой каждого из отправляемых отчётов.
     */
    private DateTimeImmutable $fullUpdateTime;

    /**
     * @param ConfigReport $configReport настройки отправки отчётов
     * @param CreateReport $createReport Создание отчётов
     */
    public function __construct(
        private readonly ConfigReport    $configReport,
        private readonly CreateReport    $createReport,
        private readonly SendReport      $sendReport,
        private readonly UpdateTime      $updateTime,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @param ?bool $reportOverride признак принудительной отправки "чистых" отчётов (Зажать CTRL при нажатии на кнопку отправки)
     */
    public function process(?bool $reportOverride = null): bool
    {
        Timers::start('send_reports');
        $this->logger->info('Начат процесс отправки отчётов...');

        /** Признак необходимости отправки "чистых" отчётов из настроек. */
        $reportRewrite = $this->configReport->unsetOtherTopics;
        if ($reportOverride === true) {
            $reportRewrite = true;

            $this->logger->notice('Получен сигнал для отправки "чистых" отчётов.');
        }

        // Проверка доступности API.
        $this->checkApiReportAccess();

        // Инициализация переменных для создания отчётов.
        Timers::start('create_report');
        $this->createReport->initConfig();
        $this->logger->debug('create report {sec}', ['sec' => Timers::getExecTime('create_report')]);

        // Проверим факт полного обновления сведений.
        if ($this->checkFullUpdateTime() === false) {
            return false;
        }

        // Если API доступно - отправляем отчёты.
        if ($this->sendReport->isApiEnable()) {
            $this->sendSubsectionsReports(reportRewrite: $reportRewrite);
        }

        // Желание отправить сводный отчёт на форум.
        if ($this->configReport->sendSummary) {
            $this->sendForumSummaryReport();
        } else {
            $this->logger->notice('Отправка сводного отчёта на форум отключена в настройках.');
        }

        $this->logger->info(
            'Процесс отправки отчётов завершён за {sec}',
            ['sec' => Timers::getExecTime('send_reports')]
        );

        return true;
    }

    /**
     * Проверка доступности API отчётов и установка необходимых признаков.
     */
    private function checkApiReportAccess(): void
    {
        $report = $this->sendReport;

        // Желание отправить отчёт через API.
        $report->setApiEnable($this->configReport->sendReports);

        // Проверяем доступность API.
        if ($report->isApiEnable()) {
            $report->checkApiAccess();
        }

        if (!$report->isApiEnable()) {
            $this->logger->notice('Отправка отчёта в API невозможна или отключена.');
        }
    }

    /**
     * Проверка наличия даты полного обновления сведений и запись этой даты в локальные переменные.
     */
    private function checkFullUpdateTime(): bool
    {
        $fullUpdateTime = $this->updateTime->checkReportsSendAvailable(
            markers: $this->createReport->getForums(),
            logger : $this->logger
        );

        if ($fullUpdateTime === null) {
            return false;
        }

        // Перезапишем актуальную дату отчётности.
        $this->fullUpdateTime = $fullUpdateTime;
        $this->createReport->setFullUpdateTime(updateTime: $fullUpdateTime);

        return true;
    }

    /**
     * Отправка отчётов по каждому хранимому подразделу в API отчётов.
     *
     * @param bool $reportRewrite признак отправки "чистых" отчётов
     */
    private function sendSubsectionsReports(bool $reportRewrite): void
    {
        $creator = $this->createReport;
        $report  = $this->sendReport;

        $Timers = [];

        // Задаём ид тем, с отчётами по хранимым подразделам.
        $creator->setForumTopics(reportTopics: $report->getReportTopics());

        $forumCount = $creator->getForumCount();

        $apiReportCount = 0;
        $forumsToReport = [];
        foreach ($creator->getForums() as $forumId) {
            // Пропускаем исключённые подразделы.
            if ($creator->isForumExcluded(forumId: $forumId)) {
                continue;
            }

            $timer = [];

            // Пробуем отправить отчёт по API.
            Timers::start("send_api_$forumId");

            try {
                Timers::start("search_db_$forumId");

                // Получаем раздачи, которые нужно отправить.
                $topicsToReport = $creator->getStoredForumTopics(forumId: $forumId);

                $timer['search_db'] = Timers::getExecTime("search_db_$forumId");

                // Записываем ид подраздела, раздачи которого удалось найти для отчёта.
                $forumsToReport[] = $forumId;

                // Пробуем отправить отчёт по API.
                $apiResult = $report->sendForumTopics(
                    forumId       : $forumId,
                    topicsToReport: $topicsToReport,
                    reportDate    : $this->fullUpdateTime,
                    reportRewrite : $reportRewrite,
                );

                $timer['send_api'] = Timers::getExecTime("send_api_$forumId");

                $this->logger->debug(
                    'API. Отчёт отправлен [{current}/{total}] {sec}',
                    [
                        'current' => ++$apiReportCount,
                        'total'   => $forumCount,
                        'sec'     => $timer['send_api'],
                        ...$apiResult,
                    ]
                );

                unset($topicsToReport, $apiResult);
            } catch (Throwable $e) {
                // Если отправка отчёта провалилась не по причине отсутствия хранимых раздач - записываем ид подраздела.
                if (!$e instanceof EmptyFoundTopicsException) {
                    $forumsToReport[] = $forumId;
                }

                $this->logger->notice('API. Отчёт не отправлен [{current}/{total}]. Причина: "{error}"', [
                    'forumId' => $forumId,
                    'error'   => $e->getMessage(),
                    'current' => ++$apiReportCount,
                    'total'   => $forumCount,
                ]);
            }

            $creator->clearCache(forumId: $forumId);
            $Timers[] = ['forum' => $forumId, ...$timer];

            unset($forumId, $timer);
        }

        // Отправка статуса хранимых подразделов и снятие галки с не хранимых.
        if (count($forumsToReport)) {
            // Отправляем статус хранения подразделов и отмечаем прочие как не хранимые, если включено.
            $setStatus = $report->setForumsStatus(
                forumIds        : array_unique($forumsToReport),
                unsetOtherForums: $this->configReport->unsetOtherSubForums
            );
            $this->logger->debug('kept forums setStatus', $setStatus);
        }

        // Запишем таймеры в журнал.
        if (count($Timers)) {
            $this->logger->debug((string) json_encode($Timers));
        }

        if ($apiReportCount > 0) {
            $this->logger->info('Отчётов отправлено в API: {count} шт.', ['count' => $apiReportCount]);

            // Запишем время отправки отчётов.
            $this->updateTime->setMarkerTime(marker: UpdateMark::SEND_REPORT);
        }
    }

    /**
     * Отправка "сводного" отчёта на форум и в API отчётов.
     */
    private function sendForumSummaryReport(): void
    {
        $creator = $this->createReport;
        $report  = $this->sendReport;

        try {
            if ($report->isApiEnable()) {
                // Формируем сводный для API.
                $customApiReport = $creator->getConfigTelemetry();

                $customApiReport['summary_report'] = $creator->getSummaryReport();

                // Отправляем Сводный отчёт и телеметрию в API.
                $report->sendCustomReport(apiCustom: $customApiReport);
            }

            // Проверяем доступ к форуму.
            if (!$report->checkForumAccess()) {
                throw new RuntimeException('Ошибка подключения к форуму.');
            }

            Timers::start('send_summary');
            // Формируем сводный отчёт.
            $summaryReport = $creator->getSummaryReport(withTelemetry: true);

            // Отправляем сводный отчёт.
            $report->sendForumSummaryReport(report: $summaryReport);

            // Запишем время отправки отчётов.
            $this->updateTime->setMarkerTime(marker: UpdateMark::SEND_REPORT);

            $this->logger->info(
                'Отправка сводного отчёта завершена за {sec}',
                ['sec' => Timers::getExecTime('send_summary')]
            );
        } catch (Throwable $e) {
            $this->logger->error($e->getMessage());
            $this->logger->warning('Нет доступа к форуму. Отправка сводного отчёта невозможна.');
        }
    }
}
