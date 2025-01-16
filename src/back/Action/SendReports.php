<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\Action;

use KeepersTeam\Webtlo\Config\ApiCredentials as ConfigCredentials;
use KeepersTeam\Webtlo\Config\ReportSend as ConfigReport;
use KeepersTeam\Webtlo\Enum\UpdateMark;
use KeepersTeam\Webtlo\External\ForumClient;
use KeepersTeam\Webtlo\Forum\Report\Creator as ReportCreator;
use KeepersTeam\Webtlo\Forum\SendReport;
use KeepersTeam\Webtlo\Storage\Table\UpdateTime;
use KeepersTeam\Webtlo\Timers;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

final class SendReports
{
    /**
     * @param ConfigCredentials $configCredentials хранительские ключи
     * @param ConfigReport      $configReport      настройки отправки отчётов
     * @param ReportCreator     $creator           Создание отчётов
     */
    public function __construct(
        private readonly ConfigCredentials $configCredentials,
        private readonly ConfigReport      $configReport,
        private readonly ForumClient       $forumClient,
        private readonly SendReport        $sendReport,
        private readonly ReportCreator     $creator,
        private readonly UpdateTime        $updateTime,
        private readonly LoggerInterface   $logger,
    ) {}

    public function process(): bool
    {
        Timers::start('send_reports');
        $this->logger->info('Начат процесс отправки отчётов...');

        // Подключаемся к API отчётов.
        Timers::start('init_api_report');

        $sendReport   = $this->sendReport;
        $reportConfig = $this->configReport;
        $updateTime   = $this->updateTime;

        // Признак необходимости отправки "чистых" отчётов из настроек.
        $reportRewrite = $reportConfig->unsetOtherTopics;

        // TODO вычленить это наружу.
        // Проверяем наличие запроса фронта о необходимости отправки чистых отчётов.
        $postData = json_decode((string) file_get_contents('php://input'), true);
        if (!empty($postData['cleanOverride']) && true === $postData['cleanOverride']) {
            $reportRewrite = true;

            $this->logger->notice('Получен сигнал для отправки "чистых" отчётов.');
        }
        unset($postData);

        // Желание отправить отчёт через API.
        $sendReport->setEnable($reportConfig->sendReports);

        // Проверяем доступность API.
        if ($sendReport->isEnable()) {
            $sendReport->checkAccess();
        }

        if (!$sendReport->isEnable()) {
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
        if ($sendReport->isEnable()) {
            $Timers = [];

            // Задаём ид тем, с отчётами по хранимым подразделам.
            $creator->setForumTopics(reportTopics: $sendReport->getReportTopics());

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
                    $apiResult = $sendReport->sendForumTopics(
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
                $setStatus = $sendReport->setForumsStatus(
                    forumIds        : $forumsToReport,
                    unsetOtherForums: $reportConfig->unsetOtherSubForums
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
        if ($reportConfig->sendSummary) {
            $this->sendSummaryReport();
        } else {
            $this->logger->notice('Отправка сводного отчёта на форум отключена в настройках.');
        }

        $this->logger->info(
            'Процесс отправки отчётов завершён за {sec}',
            ['sec' => Timers::getExecTime('send_reports')]
        );

        return true;
    }

    private function sendSummaryReport(): void
    {
        $creator     = $this->creator;
        $apiClient   = $this->sendReport;
        $forumClient = $this->forumClient;

        try {
            if ($apiClient->isEnable()) {
                // Формируем сводный для API.
                $customApiReport = $creator->getConfigTelemetry();

                $customApiReport['summary_report'] = $creator->getSummaryReport();

                // Отправляем Сводный отчёт и телеметрию в API.
                $apiClient->sendCustomReport(apiCustom: $customApiReport);
            }

            // Проверяем доступ к форуму.
            if (!$forumClient->checkConnection()) {
                throw new RuntimeException('Ошибка подключения к форуму.');
            }

            Timers::start('send_summary');
            // Формируем сводный отчёт.
            $forumSummary = $creator->getSummaryReport(withTelemetry: true);

            // Отправляем сводный отчёт.
            $forumClient->sendSummaryReport(userId: $this->configCredentials->userId, message: $forumSummary);

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
