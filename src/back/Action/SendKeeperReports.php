<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\Action;

use DateTimeImmutable;
use Generator;
use KeepersTeam\Webtlo\Config\ReportSend as ConfigReport;
use KeepersTeam\Webtlo\Enum\UpdateMark;
use KeepersTeam\Webtlo\Module\Report\CreateReport;
use KeepersTeam\Webtlo\Module\Report\EmptyFoundTopicsException;
use KeepersTeam\Webtlo\Module\Report\SendReport;
use KeepersTeam\Webtlo\Storage\Table\UpdateTime;
use KeepersTeam\Webtlo\Timers;
use Psr\Log\LoggerInterface;
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
        if (!$this->checkApiReportAccess()) {
            $this->logger->notice('Отправка отчёта в API невозможна или отключена.');

            return true;
        }

        // Инициализация переменных для создания отчётов.
        $this->createReport->initConfig();

        // Проверим факт полного обновления сведений.
        if ($this->checkFullUpdateTime() === false) {
            return false;
        }

        if ($this->configReport->sendMethod->bySubsections()) {
            // Отправляем отчёты по каждому хранимому подразделу.
            $this->sendSubsectionsReports(reportRewrite: $reportRewrite);
        } else {
            // Или просто кидаем все хранимые хеши.
            $this->sendHashesReports(reportRewrite: $reportRewrite);
        }

        // Отправляем сводный отчёт + телеметрию.
        $this->sendSummaryReport();

        $this->logger->info(
            'Процесс отправки отчётов завершён за {sec}',
            ['sec' => Timers::getExecTime('send_reports')]
        );

        return true;
    }

    /**
     * Проверка доступности API отчётов и установка необходимых признаков.
     */
    private function checkApiReportAccess(): bool
    {
        $report = $this->sendReport;

        // Желание отправить отчёт через API.
        $report->setApiEnable($this->configReport->sendReports);

        // Проверяем доступность API.
        if ($report->isApiEnable()) {
            $report->checkApiAccess();
        }

        return $report->isApiEnable();
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

        $forumCount = count($creator->getReportedForums());

        // Ограничения доступа для кандидатов в хранители.
        $user = $this->sendReport->getKeeperPermissions();

        // Статусы, которые нужно присвоить раздачам и подразделам.
        $statusRules = $this->configReport->getStatusRules();

        $apiReportCount = 0;
        $forumsToReport = [];
        foreach ($creator->getForums() as $forumId) {
            // Пропускаем исключённые подразделы.
            if ($creator->isForumExcluded(forumId: $forumId)) {
                continue;
            }

            if ($user->isCandidate && !$user->checkSubsectionAccess(forumId: $forumId)) {
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
                    statusRules   : $statusRules,
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

        if (count($skipped = $user->getSkippedSubsections())) {
            $this->logger->notice(
                'У кандидата в хранители нет доступа к указанным подразделам. Обратитесь к куратору.',
                ['skipped' => $skipped]
            );
        }

        // Отправка статуса хранимых подразделов и снятие галки с не хранимых.
        if (count($forumsToReport)) {
            // Отправляем статус хранения подразделов и отмечаем прочие как не хранимые, если включено.
            $setStatus = $report->setForumsStatus(
                forumIds        : array_unique($forumsToReport),
                statusRules     : $statusRules,
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
     * Отправка "сводного" отчёта в API отчётов.
     */
    private function sendSummaryReport(): void
    {
        $creator = $this->createReport;

        try {
            // Формируем сводный для API.
            $customApiReport = $creator->getConfigTelemetry();

            $customApiReport['summary_report'] = $creator->getSummaryReport();

            $this->sendReport->sendCustomReport(apiCustom: $customApiReport);
        } catch (Throwable $e) {
            $this->logger->warning($e->getMessage());
        }
    }

    /**
     * Отправка отчётов в виде списка хранимых хешей в API отчётов.
     *
     * @param bool $reportRewrite признак отправки "чистых" отчётов
     */
    private function sendHashesReports(bool $reportRewrite): void
    {
        $creator = $this->createReport;
        $report  = $this->sendReport;

        // Статусы, которые нужно присвоить раздачам и подразделам.
        $statusRules = $this->configReport->getStatusRules();

        $topics = $creator->findKeptTopics();

        /**
         * @return Generator<non-negative-int, string[]>
         */
        $generator = static function() use ($topics, $statusRules): Generator {
            // Разделяем раздачи на скачанные и качаемые.
            $completeTopics = $downloadingTopics = [];
            foreach ($topics as $topic) {
                if ($topic['done'] < 1.0) {
                    $downloadingTopics[] = $topic['hash'];
                } else {
                    $completeTopics[] = $topic['hash'];
                }

                if (count($completeTopics) >= 50_000) {
                    yield $statusRules->keptTopics => $completeTopics;

                    // Очищаем буфер.
                    $completeTopics = [];
                }

                if (count($downloadingTopics) >= 50_000) {
                    yield $statusRules->downloadingTopics => $downloadingTopics;

                    // Очищаем буфер.
                    $downloadingTopics = [];
                }
            }

            // Если есть остатки, то их тоже возвращаем.
            if ($completeTopics !== []) {
                yield $statusRules->keptTopics => $completeTopics;
            }

            if ($downloadingTopics !== []) {
                yield $statusRules->downloadingTopics => $downloadingTopics;
            }
        };

        $i = 0;
        foreach ($generator() as $status => $hashes) {
            Timers::start("send_api_chunks_$i");

            $apiResult = $report->sendReportHashes(
                hashes       : $hashes,
                reportDate   : $this->fullUpdateTime,
                status       : $status,
                reportRewrite: $reportRewrite,
            );

            $this->logger->debug(
                'API. Отчёт отправлен [{current}] {sec}',
                [
                    'current' => ++$i,
                    'sec'     => Timers::getExecTime("send_api_chunks_$i"),
                    ...$apiResult,
                ]
            );
        }

        // Вызываем пересчёт отметок хранимых подразделов.
        $resultStatusAuto = $report->setForumsStatusAuto();
        $this->logger->debug('setStatusAuto', $resultStatusAuto);

        // Запишем время отправки отчётов.
        $this->updateTime->setMarkerTime(marker: UpdateMark::SEND_REPORT);
    }
}
