<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\Update;

use KeepersTeam\Webtlo\Enum\UpdateMark;
use KeepersTeam\Webtlo\Enum\UpdateStatus;
use KeepersTeam\Webtlo\External\Api\V1\ApiError;
use KeepersTeam\Webtlo\External\Api\V1\KeepersResponse;
use KeepersTeam\Webtlo\External\ApiClient;
use KeepersTeam\Webtlo\External\ApiReportClient;
use KeepersTeam\Webtlo\Settings;
use KeepersTeam\Webtlo\Storage\Clone\KeepersLists;
use KeepersTeam\Webtlo\Storage\Table\UpdateTime;
use KeepersTeam\Webtlo\Timers;
use Psr\Log\LoggerInterface;
use Throwable;

final class KeepersReports
{
    use ExcludedKeepersTrait;

    public function __construct(
        private readonly ApiClient       $apiClient,
        private readonly ApiReportClient $apiReport,
        private readonly Settings        $settings,
        private readonly KeepersLists    $keepersLists,
        private readonly UpdateTime      $updateTime,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Обновление списков хранимых раздач других хранителей.
     *
     * @return bool - true, если обновление выполнено успешно
     */
    public function update(): bool
    {
        // Получаем параметры.
        $config = $this->settings->get();

        // Список ид хранимых подразделов.
        $keptForums = array_keys($config['subsections'] ?? []);
        if (!count($keptForums)) {
            $this->logger->warning('Выполнить обновление сведений невозможно. Отсутствуют хранимые подразделы.');

            return false;
        }

        Timers::start('update_keepers');
        $this->logger->info('ApiReport. Начато обновление отчётов хранителей...');

        // Список ид обновлений подразделов.
        $keptForumsUpdate = array_map(fn($el) => 100000 + (int) $el, $keptForums);

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
        $keepersList = $this->getKeepersList();
        if ($keepersList === null) {
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
            $apiReportCount = 0;

            $forumCount = count($config['subsections']);

            foreach ($config['subsections'] as $forumId => $subsection) {
                Timers::start("get_report_api_$forumId");

                $forumId = (int) $forumId;

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
                    $keeper = $keepersList->getKeeperInfo($keeperReport->keeperId);

                    // Пропускаем раздачи несуществующих хранителей.
                    if ($keeper === null) {
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
                ++$forumsScanned;

                // Пометим факт обновления отчётов хранителей подраздела.
                $this->updateTime->setMarkerTime(100000 + $forumId);

                $this->logger->debug('Отчёт получен [{current}/{total}] {sec}', [
                    'forumId' => $forumId,
                    'current' => ++$apiReportCount,
                    'total'   => $forumCount,
                    'sec'     => Timers::getExecTime("get_report_api_$forumId"),
                ]);
            }
        }

        // Записываем изменения в локальную таблицу.
        $this->keepersLists->moveToOrigin(
            forumsScanned: $forumsScanned,
            keepersCount : count(array_unique($keeperIds))
        );

        // Записываем дату получения списков.
        $this->updateTime->setMarkerTime(UpdateMark::KEEPERS->value);
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
