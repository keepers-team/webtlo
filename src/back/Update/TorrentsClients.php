<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\Update;

use KeepersTeam\Webtlo\Clients\ClientFactory;
use KeepersTeam\Webtlo\Config\SubForums;
use KeepersTeam\Webtlo\Config\TopicSearch;
use KeepersTeam\Webtlo\Config\TorrentClients;
use KeepersTeam\Webtlo\Enum\UpdateMark;
use KeepersTeam\Webtlo\External\Api\V1\TopicDetails;
use KeepersTeam\Webtlo\External\Api\V1\TopicSearchMode;
use KeepersTeam\Webtlo\External\ApiReportClient;
use KeepersTeam\Webtlo\External\Data\ApiError;
use KeepersTeam\Webtlo\External\ForumClient;
use KeepersTeam\Webtlo\Storage\Clone\TopicsUnregistered;
use KeepersTeam\Webtlo\Storage\Clone\TopicsUntracked;
use KeepersTeam\Webtlo\Storage\Clone\Torrents;
use KeepersTeam\Webtlo\Storage\KeysObject;
use KeepersTeam\Webtlo\Storage\Table\UpdateTime;
use KeepersTeam\Webtlo\Timers;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

final class TorrentsClients
{
    /** @var array<string, string> */
    private array $timers = [];

    /** @var TopicDetails[] */
    private array $unregisteredApiTopics = [];

    /**
     * @param Torrents           $cloneTorrents     таблица хранимых раздач в торрент-клиентах
     * @param TopicsUntracked    $cloneUntracked    таблица хранимых раздач из других подразделов
     * @param TopicsUnregistered $cloneUnregistered таблица хранимых раздач, которые разрегистрированы на форуме
     */
    public function __construct(
        private readonly ApiReportClient    $apiClient,
        private readonly ForumClient        $forumClient,
        private readonly UpdateTime         $updateTime,
        private readonly SubForums          $subForums,
        private readonly TopicSearch        $topicSearch,
        private readonly ClientFactory      $clientFactory,
        private readonly TorrentClients     $torrentClients,
        private readonly Torrents           $cloneTorrents,
        private readonly TopicsUntracked    $cloneUntracked,
        private readonly TopicsUnregistered $cloneUnregistered,
        private readonly LoggerInterface    $logger,
    ) {}

    /**
     * Обновление списков хранимых раздач в торрент-клиентах.
     */
    public function update(): void
    {
        if (!$this->checkClientsCount()) {
            return;
        }

        // Получаем раздачи от клиентов и записываем их в БД.
        $this->updateClients();

        // Найдём раздачи из не хранимых подразделов.
        $this->updateUntracked();

        // Найдём разрегистрированные раздачи.
        $this->updateUnregistered();

        $log = json_encode($this->timers);
        if ($log !== false) {
            $this->logger->debug($log);
        }
    }

    /**
     * Проверим количество настроенных торрент-клиентов.
     */
    private function checkClientsCount(): bool
    {
        // Если нет настроенных торрент-клиентов, удалим все раздачи и отметку.
        if (!$this->torrentClients->count()) {
            $this->logger->notice('Торрент-клиенты не найдены.');

            $this->updateTime->setMarkerTime(marker: UpdateMark::CLIENTS, updateTime: 0);
            $this->cloneTorrents->clearOriginTable();

            return false;
        }

        return true;
    }

    private function updateClients(): void
    {
        $this->logger->info(
            'Сканирование торрент-клиентов... Найдено {count} шт.',
            ['count' => $this->torrentClients->count()]
        );

        /** Используемый домен трекера. */
        $forumDomain = $this->forumClient->getForumDomain();

        /** Клиенты, данные от которых получить не удалось */
        $failedClients = [];
        /** Клиенты исключённые из формирования отчётов и для успешного обновления - не обязательны. */
        $excludedClients = [];

        Timers::start('update_clients');
        foreach ($this->torrentClients->clients as $clientOptions) {
            $clientId  = $clientOptions->id;
            $clientTag = $clientOptions->name;

            Timers::start("update_client_$clientId");

            // Признак исключения раздач клиента из формируемых отчётов.
            if ($clientOptions->exclude) {
                $excludedClients[] = $clientId;
            }

            try {
                // Подключаемся к торрент-клиенту.
                $client = $this->clientFactory->getClient(clientOptions: $clientOptions);

                // Меняем домен трекера, для корректного поиска раздач.
                $client->setDomain(domain: $forumDomain);
            } catch (RuntimeException $e) {
                $this->logger->notice(
                    'Клиент {client} в данный момент недоступен',
                    ['client' => $clientTag, 'error' => $e->getMessage()]
                );
                $failedClients[] = $clientId;

                continue;
            }

            // Получаем список раздач.
            try {
                $torrents = $client->getTorrents();
            } catch (RuntimeException $e) {
                $this->logger->warning(
                    'Не удалось получить данные о раздачах от торрент-клиента {client}',
                    ['client' => $clientTag, 'error' => $e->getMessage()]
                );
                $failedClients[] = $clientId;

                continue;
            }

            $countTorrents = count($torrents);
            foreach ($torrents->getGenerator() as $torrent) {
                $this->cloneTorrents->addTorrent($clientId, $torrent);
            }
            unset($torrents);

            // Запишем данные хранимых раздач во временную таблицу.
            $this->cloneTorrents->cloneFill();

            $this->logger->info('{client} получено раздач: {count} шт за {sec}', [
                'client' => $clientTag,
                'count'  => $countTorrents,
                'sec'    => Timers::getExecTime("update_client_$clientId"),
            ]);

            unset($clientId, $clientOptions, $countTorrents);
        }
        $this->timers['update_clients'] = Timers::getExecTime('update_clients');

        // Добавим в БД полученные данные о раздачах.
        $this->cloneTorrents->writeTable();

        // Если обновление всех не исключённых клиентов прошло успешно - отметим это.
        if (!count(array_diff($failedClients, $excludedClients))) {
            $this->updateTime->setMarkerTime(marker: UpdateMark::CLIENTS);
        }

        // Удалим лишние раздачи из БД, исключая раздачи клиентов с ошибкой.
        $this->cloneTorrents->removeUnusedTorrentsRows(
            failedClients: KeysObject::create($failedClients)
        );
    }

    /**
     * Найдём раздачи из не хранимых подразделов.
     */
    private function updateUntracked(): void
    {
        try {
            // Выключено - прекращаем работу.
            if (!$this->topicSearch->untracked) {
                return;
            }

            // Включён ли поиск разрегистрированных раздач.
            $searchUnregistered = $this->topicSearch->unregistered;

            Timers::start('search_untracked');

            $untrackedTorrentHashes = $this->cloneTorrents->selectUntrackedRows(
                subsections: $this->subForums->getKeyObject()
            );

            // Нет раздач - прекращаем работу.
            $countUntracked = count($untrackedTorrentHashes);
            if (!$countUntracked) {
                return;
            }

            $this->logger->info(
                'Найдено уникальных сторонних раздач в клиентах: {count} шт.',
                ['count' => $countUntracked]
            );

            if ($countUntracked > 150) {
                $this->logger->notice(
                    'Хранится много сторонних раздач. Рекомендуется отключить поиск сторонних раздач или добавить подразделы в хранимые.'
                );

                // Обрежем раздачи, если их слишком много.
                $countLimit = rand(256, 512);
                if ($countUntracked > $countLimit) {
                    $this->logger->warning('Будет обработано {limit} сторонних раздач.', ['limit' => $countLimit]);

                    $untrackedTorrentHashes = array_slice($untrackedTorrentHashes, 0, $countLimit);
                }
            }

            // Проверим доступность API отчётов перед попыткой поиска раздач.
            if (!$this->apiClient->checkAccess()) {
                throw new RuntimeException('Ошибка доступа. Поиск прекращён.');
            }

            // Пробуем найти в API раздачи по их хешам из клиента.
            $response = $this->apiClient->getTopicsDetails(
                topics    : $untrackedTorrentHashes,
                searchMode: TopicSearchMode::HASH
            );

            if ($response instanceof ApiError) {
                $this->logger->debug(
                    'Не удалось найти данные о раздачах в API. {code}: {text}',
                    ['code' => $response->code, 'text' => $response->text]
                );

                $this->logger->debug('hashes', $untrackedTorrentHashes);

                return;
            }

            unset($untrackedTorrentHashes);

            // Нашлись актуальные раздачи.
            if (count($response->actualTopics)) {
                foreach ($response->actualTopics as $topic) {
                    // Пропускаем раздачи в невалидных статусах.
                    if (!$topic->status->isValid()) {
                        // Дописываем их в буфер если нужны.
                        if ($searchUnregistered) {
                            $this->unregisteredApiTopics[$topic->hash] = $topic;
                        }

                        continue;
                    }

                    $this->cloneUntracked->addTopic(topic: $topic);
                }

                // Если нашлись существующие на форуме раздачи, то записываем их в БД.
                $this->cloneUntracked->moveToOrigin();
            }

            // Если нашлись "прошлые релизы", и они нужны для следующего этапа.
            if (count($response->oldTopics) && $searchUnregistered) {
                foreach ($response->oldTopics as $topic) {
                    if (!$topic->status->isValid()) {
                        // Дописываем их в буфер если нужны.
                        $this->unregisteredApiTopics[$topic->hash] = $topic;
                    }
                }
            }
        } catch (Throwable $e) {
            $this->logger->warning(
                'Ошибка при поиске сторонних раздач. {error}',
                ['error' => $e->getMessage()]
            );
        } finally {
            $this->timers['search_untracked'] = Timers::getExecTime('search_untracked');

            // Удалим лишние раздачи из БД прочих.
            $this->cloneUntracked->clearUnusedRows();
        }
    }

    /**
     * Найдём разрегистрированные раздачи.
     */
    private function updateUnregistered(): void
    {
        try {
            // Выключено - прекращаем работу.
            if (!$this->topicSearch->untracked || !$this->topicSearch->unregistered) {
                return;
            }

            Timers::start('search_unregistered');
            $unregisteredTopics = $this->cloneUnregistered->searchUnregisteredTopics();

            // Если в БД есть разрегистрированные раздачи, ищем их статус на форуме.
            if (count($unregisteredTopics)) {
                if (!$this->forumClient->checkAccess()) {
                    throw new RuntimeException('Ошибка подключения к форуму. Поиск прекращён.');
                }

                foreach ($unregisteredTopics as $topicId => $infoHash) {
                    $topicData = $this->forumClient->getUnregisteredTopic(topicId: (int) $topicId);
                    if ($topicData === null) {
                        continue;
                    }

                    // Если о раздаче есть данные в API, то дописываем их, как более верные.
                    $apiTopicInfo = $this->getApiTopicInfo(infoHash: $infoHash);
                    if ($apiTopicInfo !== null) {
                        $topicData['name']   = $apiTopicInfo->title;
                        $topicData['status'] = $apiTopicInfo->status->label();
                        if (empty($topicData['priority'])) {
                            $topicData['priority'] = $apiTopicInfo->priority->label();
                        }
                    }

                    // Записываем данные раздачи в буфер.
                    $this->cloneUnregistered->addTopic(topic: [
                        $infoHash,
                        $topicData['name'],
                        $topicData['status'],
                        $topicData['priority'],
                        $topicData['transferred_from'],
                        $topicData['transferred_to'],
                        $topicData['transferred_by_whom'],
                    ]);

                    unset($topicId, $topicData, $apiTopicInfo);
                }

                $this->cloneUnregistered->fillTempTable();
            }

            $this->cloneUnregistered->moveToOrigin();
        } catch (Throwable $e) {
            $this->logger->warning(
                'Ошибка при поиске разрегистрированных раздач. {error}',
                ['error' => $e->getMessage()]
            );
        } finally {
            $this->timers['search_unregistered'] = Timers::getExecTime('search_unregistered');

            // Очищаем ненужные строки.
            $this->cloneUnregistered->clearUnusedRows();
        }
    }

    private function getApiTopicInfo(string $infoHash): ?TopicDetails
    {
        if (isset($this->unregisteredApiTopics[$infoHash])) {
            return $this->unregisteredApiTopics[$infoHash];
        }

        return null;
    }
}
