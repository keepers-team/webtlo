<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\Action;

use KeepersTeam\Webtlo\Config\ReportSend as ConfigReport;
use KeepersTeam\Webtlo\Enum\UpdateMark;
use KeepersTeam\Webtlo\Forum\Report\Creator as ReportCreator;
use KeepersTeam\Webtlo\Forum\SendReport;
use KeepersTeam\Webtlo\Storage\Table\UpdateTime;
use KeepersTeam\Webtlo\Timers;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

final class SendKeeperReports
{
    /**
     * @param ConfigReport  $configReport настройки отправки отчётов
     * @param ReportCreator $creator      Создание отчётов
     */
    public function __construct(
        private readonly ConfigReport    $configReport,
        private readonly SendReport      $sendReport,
        private readonly ReportCreator   $creator,
        private readonly UpdateTime      $updateTime,
        private readonly LoggerInterface $logger,
    ) {}

    public function process(?bool $reportOverride = null): bool
    {
        Timers::start('send_reports');
        $this->logger->info('Начат процесс отправки отчётов...');

        // Подключаемся к API отчётов.
        Timers::start('init_api_report');

        $report     = $this->sendReport;
        $updateTime = $this->updateTime;

        // Признак необходимости отправки "чистых" отчётов из настроек.
        $reportRewrite = $this->configReport->unsetOtherTopics;
        if (true === $reportOverride) {
            $reportRewrite = true;

            $this->logger->notice('Получен сигнал для отправки "чистых" отчётов.');
        }

        // Желание отправить отчёт через API.
        $report->setApiEnable($this->configReport->sendReports);

        // Проверяем доступность API.
        if ($report->isApiEnable()) {
            $report->checkApiAccess();
        }

        if (!$report->isApiEnable()) {
            $this->logger->notice('Отправка отчёта в API невозможна или отключена');
        }
        $this->logger->debug('init api report {sec}', ['sec' => Timers::getExecTime('init_api_report')]);

        Timers::start('create_report');

        $creator = $this->creator;
        $creator->initConfig();

        $this->logger->debug('create report {sec}', ['sec' => Timers::getExecTime('create_report')]);

        // Проверим полное обновление.
        $fullUpdateTime = $this->updateTime->checkReportsSendAvailable(
            markers: $creator->getForums(),
            logger : $this->logger
        );
        if (null === $fullUpdateTime) {
            return false;
        }

        // Перезапишем актуальную дату отчётности, после проверки.
        $creator->setFullUpdateTime(updateTime: $fullUpdateTime);

        // Если API доступно - отправляем отчёты.
        if ($report->isApiEnable()) {
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
                $forumsToReport[] = $forumId;

                Timers::start("send_api_$forumId");

                try {
                    Timers::start("search_db_$forumId");

                    // Получаем раздачи, которые нужно отправить.
                    $topicsToReport = $creator->getStoredForumTopics(forum_id: $forumId);

                    $timer['search_db'] = Timers::getExecTime("search_db_$forumId");

                    // Пробуем отправить отчёт по API.
                    $apiResult = $report->sendForumTopics(
                        forumId       : $forumId,
                        topicsToReport: $topicsToReport,
                        reportDate    : $fullUpdateTime,
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
                    $this->logger->notice('API. Отчёт не отправлен [{current}/{total}]. Причина: "{error}"', [
                        'forumId' => $forumId,
                        'error'   => $e->getMessage(),
                        'current' => ++$apiReportCount,
                        'total'   => $forumCount,
                    ]);
                }

                $creator->clearCache($forumId);
                $Timers[] = ['forum' => $forumId, ...$timer];

                unset($forumId, $timer);
            }

            // Отправка статуса хранимых подразделов и снятие галки с не хранимых.
            if (count($forumsToReport)) {
                // Отправляем статус хранения подразделов и отмечаем прочие как не хранимые, если включено.
                $setStatus = $report->setForumsStatus(
                    forumIds        : $forumsToReport,
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
                $updateTime->setMarkerTime(marker: UpdateMark::SEND_REPORT);
            }
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

    private function sendForumSummaryReport(): void
    {
        $creator = $this->creator;
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
