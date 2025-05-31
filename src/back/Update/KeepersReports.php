<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\Update;

use KeepersTeam\Webtlo\Config\ReportSend;
use KeepersTeam\Webtlo\Config\SubForums;
use KeepersTeam\Webtlo\Enum\UpdateMark;
use KeepersTeam\Webtlo\Enum\UpdateStatus;
use KeepersTeam\Webtlo\External\Api\V1\KeepersResponse;
use KeepersTeam\Webtlo\External\ApiForumClient;
use KeepersTeam\Webtlo\External\ApiReportClient;
use KeepersTeam\Webtlo\External\Data\ApiError;
use KeepersTeam\Webtlo\Storage\Clone\KeepersLists;
use KeepersTeam\Webtlo\Storage\Clone\KeepersSeeders;
use KeepersTeam\Webtlo\Storage\Table\UpdateTime;
use KeepersTeam\Webtlo\Timers;
use Psr\Log\LoggerInterface;
use Throwable;

final class KeepersReports
{
    public function __construct(
        private readonly ApiForumClient  $apiClient,
        private readonly ApiReportClient $apiReport,
        private readonly ReportSend      $configReport,
        private readonly SubForums       $configSubForums,
        private readonly KeepersLists    $keepersLists,
        private readonly KeepersSeeders  $keepersSeeders,
        private readonly UpdateTime      $updateTime,
        private readonly LoggerInterface $logger,
    ) {}

    public function __destruct()
    {
        $this->apiReport->cleanupReports();
    }

    /**
     * Обновление списков хранимых раздач других хранителей.
     *
     * @return bool - true, если обновление выполнено успешно
     */
    public function update(): bool
    {
        // Список хранимых подразделов.
        $keptForums = $this->configSubForums->ids;
        if (!count($keptForums)) {
            $this->logger->warning('Выполнить обновление сведений невозможно. Отсутствуют хранимые подразделы.');

            return false;
        }

        Timers::start('update_keepers');
        $this->logger->info('ApiReport. Начато обновление отчётов хранителей...');

        // Список ид обновлений подразделов.
        $keptForumsUpdate = array_map(static fn($el) => 100000 + $el, $keptForums);

        $updateStatus = $this->updateTime->getMarkersObject(markers: $keptForumsUpdate);
        $updateStatus->checkMarkersLess(seconds: 15 * 60);

        // Если количество маркеров не совпадает, обнулим имеющиеся, чтобы обновить все.
        if ($updateStatus->getLastCheckStatus() === UpdateStatus::MISSED) {
            $this->keepersLists->clearLists();
        }

        // Проверим минимальную дату обновления данных других хранителей.
        if ($updateStatus->getLastCheckStatus() === UpdateStatus::EXPIRED) {
            $this->logger->notice(
                'ApiReport. Обновление отчётов хранителей не требуется. Дата последнего выполнения {date}',
                ['date' => $updateStatus->getMinUpdate()->format('d.m.Y H:i')]
            );

            return true;
        }

        // Проверка наличия доступа к API.
        if (!$this->apiReport->checkAccess()) {
            return false;
        }

        // Получаем список хранителей.
        $keepersList = $this->getKeepersList();
        if ($keepersList === null) {
            return false;
        }

        // Находим список игнорируемых хранителей.
        $excludedKeepers = $this->configReport->excludedKeepers;
        if (count($excludedKeepers)) {
            $this->logger->debug('ApiReport. Исключены хранители', $excludedKeepers);
        }

        $forumsScanned = 0;
        $keeperIds     = [];

        $apiReportCount = 0;

        $forumCount = count($keptForums);

        // Ограничения доступа для кандидатов в хранители.
        $user = $this->apiReport->getKeeperPermissions();

        // Если хранимых подразделов много, пробуем загрузить статический архив.
        if (!$user->isCandidate && $forumCount > 10) {
            $this->apiReport->downloadReportsArchive(subforums: $keptForums);
        }

        foreach ($keptForums as $forumId) {
            if ($user->isCandidate && !$user->checkSubsectionAccess(forumId: $forumId)) {
                continue;
            }

            Timers::start("get_report_api_$forumId");

            try {
                $forumReports = $this->apiReport->getKeepersReports(forumId: $forumId);
            } catch (Throwable $e) {
                $this->logger->warning($e->getMessage());

                continue;
            }

            foreach ($forumReports->keepers as $keeperReport) {
                // Пропускаем игнорируемых хранителей.
                if (in_array($keeperReport->keeperId, $excludedKeepers, true)) {
                    continue;
                }

                /** Данные о хранителе. */
                $keeper = $keepersList->getKeeperInfo(keeperId: $keeperReport->keeperId);

                // Пропускаем раздачи несуществующих хранителей.
                if ($keeper === null) {
                    continue;
                }

                // Записываем сидов-хранителей раздачи, не зависимо от статуса.
                $this->keepersSeeders->addKeptTopics(keeper: $keeper, topics: $keeperReport->topics);
                // Запись сидов-хранителей во временную таблицу.
                $this->keepersSeeders->cloneFill();

                // Пропускаем раздачи кандидатов в хранители.
                if ($keeper->isCandidate) {
                    continue;
                }

                // Считаем уникальных хранителей.
                $keeperIds[] = $keeper->keeperId;

                // Записываем раздачи хранителя во временную таблицу.
                $this->keepersLists->addKeptTopics(keeper: $keeper, topics: $keeperReport->topics);
                $this->keepersLists->fillTempTable();
            }

            // Считаем обновлённые подразделы.
            ++$forumsScanned;

            // Пометим факт обновления отчётов хранителей подраздела.
            $this->updateTime->setMarkerTime(marker: 100000 + $forumId);

            $this->logger->debug('Отчёт получен [{current}/{total}] {sec}', [
                'forumId' => $forumId,
                'current' => ++$apiReportCount,
                'total'   => $forumCount,
                'sec'     => Timers::getExecTime("get_report_api_$forumId"),
            ]);
        }

        if (count($skipped = $user->getSkippedSubsections())) {
            $this->logger->notice(
                'У кандидата в хранители нет доступа к указанным подразделам. Обратитесь к куратору.',
                ['skipped' => $skipped]
            );
        }

        // Записываем изменения в локальную таблицу.
        $this->keepersLists->moveToOrigin(
            forumsScanned: $forumsScanned,
            keepersCount : count(array_unique($keeperIds))
        );

        // Записываем данные о сидах-хранителях в БД.
        $this->keepersSeeders->moveToOrigin();

        // Записываем дату получения списков.
        $this->updateTime->setMarkerTime(marker: UpdateMark::KEEPERS);
        $this->logger->info(
            'ApiReport. Обновление отчётов хранителей завершено за {sec}',
            ['sec' => Timers::getExecTime('update_keepers')]
        );

        return true;
    }

    /**
     * Загрузить список всех хранителей.
     */
    private function getKeepersList(): ?KeepersResponse
    {
        $response = $this->apiClient->getKeepersList();
        if ($response instanceof ApiError) {
            $this->logger->error(
                'Не получены данные о хранителях',
                ['code' => $response->code, 'text' => $response->text]
            );

            return null;
        }

        return $response;
    }
}
