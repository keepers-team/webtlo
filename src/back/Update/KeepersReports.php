<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\Update;

use KeepersTeam\Webtlo\Enum\UpdateMark;
use KeepersTeam\Webtlo\Enum\UpdateStatus;
use KeepersTeam\Webtlo\External\Api\V1\ApiError;
use KeepersTeam\Webtlo\External\ApiClient;
use KeepersTeam\Webtlo\External\ApiReport\V1\ReportForumResponse;
use KeepersTeam\Webtlo\External\ApiReportClient;
use KeepersTeam\Webtlo\Helper;
use KeepersTeam\Webtlo\Tables\KeepersLists;
use KeepersTeam\Webtlo\Tables\KeepersSeeders;
use KeepersTeam\Webtlo\Tables\UpdateTime;
use KeepersTeam\Webtlo\Timers;
use Psr\Log\LoggerInterface;
use Throwable;

final class KeepersReports
{
    use ExcludedKeepersTrait;

    public function __construct(
        private readonly ApiClient       $apiClient,
        private readonly ApiReportClient $apiReport,
        private readonly KeepersLists    $keepersLists,
        private readonly KeepersSeeders  $keepers,
        private readonly UpdateTime      $updateTime,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<string, mixed>[] $config
     * @return bool
     */
    public function updateReports(array $config): bool
    {
        // Список ид хранимых подразделов.
        $keptForums = array_keys($config['subsections'] ?? []);
        if (!count($keptForums)) {
            $this->logger->warning('Выполнить обновление сведений невозможно. Отсутствуют хранимые подразделы.');

            return false;
        }

        Timers::start('update_keepers');
        $this->logger->info('ApiReport. Начато обновление отчётов хранителей...');

        // Список ид обновлений подразделов.
        $keptForumsUpdate = array_map(fn($el) => 100000 + (int)$el, $keptForums);

        $updateStatus = $this->updateTime->getMarkersObject($keptForumsUpdate);
        $updateStatus->checkMarkersLess(15 * 60);

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
        if (!$this->getKeepersList()) {
            return false;
        }

        // Находим список игнорируемых хранителей.
        $excludedKeepers = self::getExcludedKeepersList($config);
        $this->setExcludedKeepers($excludedKeepers);
        if (count($excludedKeepers)) {
            $this->logger->debug('ApiReport. Исключены хранители', $excludedKeepers);
        }

        $forumsScanned = 0;
        $keeperIds     = [];

        if (isset($config['subsections'])) {
            // получаем данные
            foreach ($config['subsections'] as $forumId => $subsection) {
                $forumId = (int)$forumId;
                try {
                    $forumReports = $this->apiReport->getKeepersReports($forumId);
                } catch (Throwable $e) {
                    $this->logger->warning($e->getMessage());
                    continue;
                }

                foreach ($forumReports->keepers as $keeperReport) {
                    // Пропускаем игнорируемых хранителей.
                    if (in_array($keeperReport->keeperId, $this->excludedKeepers, true)) {
                        continue;
                    }

                    /** Данные о хранителе. */
                    $keeper = $this->keepers->getKeeperInfo($keeperReport->keeperId);

                    // Пропускаем раздачи несуществующих хранителей.
                    if (null === $keeper) {
                        continue;
                    }

                    // Пропускаем раздачи кандидатов в хранители.
                    if ($keeper->isCandidate) {
                        continue;
                    }

                    // Считаем уникальных хранителей.
                    $keeperIds[] = $keeper->keeperId;

                    // Записываем раздачи хранителя во временную таблицу.
                    $this->keepersLists->addKeptTopics($keeper, $keeperReport->topics);
                    $this->keepersLists->fillTempTable();
                }

                // Считаем обновлённые подразделы.
                $forumsScanned++;

                // Пометим факт обновления отчётов хранителей подраздела.
                $this->updateTime->setMarkerTime(100000 + $forumId);
            }
        }

        // Записываем изменения в локальную таблицу.
        $this->keepersLists->moveToOrigin($forumsScanned, count(array_unique($keeperIds)));

        // TODO подумать, нужен ли этот маркер вообще.
        // Записываем дату получения списков.
        $this->updateTime->setMarkerTime(UpdateMark::KEEPERS->value);
        $this->logger->info(
            'ApiReport. Обновление отчётов хранителей завершено за {sec}',
            ['sec' => Timers::getExecTime('update_keepers')]
        );

        return true;
    }

    public function getReportTopics(): ?ReportForumResponse
    {
        $response = $this->apiReport->getForumsReportTopics();
        if ($response instanceof ApiError) {
            $this->logger->error(
                'Не удалось получить список тем с отчётами',
                ['code' => $response->code, 'text' => $response->text]
            );

            return null;
        }

        return $response;
    }

    /**
     * Загрузить список всех хранителей.
     */
    private function getKeepersList(): bool
    {
        $response = $this->apiClient->getKeepersList();
        if ($response instanceof ApiError) {
            $this->logger->error(
                'Не получены данные о хранителях',
                ['code' => $response->code, 'text' => $response->text]
            );

            return false;
        }
        $this->keepers->addKeepersInfo($response->keepers);

        return true;
    }
}
