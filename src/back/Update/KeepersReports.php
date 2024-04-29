<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\Update;

use Exception;
use KeepersTeam\Webtlo\Enum\UpdateStatus;
use KeepersTeam\Webtlo\External\Api\V1\ApiError;
use KeepersTeam\Webtlo\External\Api\V1\KeeperData;
use KeepersTeam\Webtlo\External\ApiClient;
use KeepersTeam\Webtlo\External\ApiReportClient;
use KeepersTeam\Webtlo\Module\LastUpdate;
use KeepersTeam\Webtlo\Tables\KeepersLists;
use KeepersTeam\Webtlo\Tables\KeepersSeeders;
use KeepersTeam\Webtlo\Timers;
use Psr\Log\LoggerInterface;
use Throwable;

final class KeepersReports
{
    public function __construct(
        private readonly ApiClient       $apiClient,
        private readonly ApiReportClient $apiReport,
        private readonly KeepersLists    $keepersLists,
        private readonly KeepersSeeders  $keepers,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @throws Exception
     */
    public function update(array $cfg): bool
    {
        $this->logger->info('Начато обновление списков раздач хранителей...');

        // Список ид хранимых подразделов.
        $keptForums = array_keys($cfg['subsections'] ?? []);

        // Список ид обновлений подразделов.
        $keptForumsUpdate = array_map(fn($el) => 100000 + $el, $keptForums);

        $updateStatus = new LastUpdate($keptForumsUpdate);
        $updateStatus->checkMarkersLess(15 * 50);

        // Если количество маркеров не совпадает, обнулим имеющиеся, чтобы обновить все.
        if ($updateStatus->getLastCheckStatus() === UpdateStatus::MISSED) {
            $this->keepersLists->clearLists();
        }

        // Проверим минимальную дату обновления данных других хранителей.
        if ($updateStatus->getLastCheckStatus() === UpdateStatus::EXPIRED) {
            $this->logger->notice(
                sprintf(
                    'Обновление списков других хранителей и сканирование форума не требуется. Дата последнего выполнения %s',
                    date('d.m.y H:i', $updateStatus->getLastCheckUpdateTime())
                )
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

        $forumsScanned = 0;
        $keeperIds     = [];

        if (isset($cfg['subsections'])) {
            // получаем данные
            foreach ($cfg['subsections'] as $forumId => $subsection) {
                try {
                    $forumReports = $this->apiReport->getKeepersReports($forumId);
                } catch (Throwable $e) {
                    $this->logger->warning($e->getMessage());
                    continue;
                }

                foreach ($forumReports->keepers as $keeperReport) {
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
                LastUpdate::setTime(100000 + $forumId);
            }
        }

        // Записываем изменения в локальную таблицу.
        $this->keepersLists->moveToOrigin($forumsScanned, count(array_unique($keeperIds)));

        $this->logger->info(
            'Обновление списков раздач хранителей завершено за ' . Timers::getExecTime('update_keepers')
        );

        return true;
    }

    /**
     * Загрузить список всех хранителей.
     */
    private function getKeepersList(): bool
    {
        $response = $this->apiClient->getKeepersList();
        if ($response instanceof ApiError) {
            $this->logger->error(
                sprintf('Не получены данные о хранителях (%d: %s).', $response->code, $response->text)
            );

            return false;
        }
        $this->keepers->addKeepersInfo($response->keepers);

        return true;
    }
}
