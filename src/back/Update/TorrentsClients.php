<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\Update;

use KeepersTeam\Webtlo\Clients\ClientFactory;
use KeepersTeam\Webtlo\DB;
use KeepersTeam\Webtlo\DTO\KeysObject;
use KeepersTeam\Webtlo\Enum\UpdateMark;
use KeepersTeam\Webtlo\External\Api\V1\ApiError;
use KeepersTeam\Webtlo\External\Api\V1\KeepingPriority;
use KeepersTeam\Webtlo\External\Api\V1\TopicDetails;
use KeepersTeam\Webtlo\External\Api\V1\TopicSearchMode;
use KeepersTeam\Webtlo\External\Api\V1\TorrentStatus;
use KeepersTeam\Webtlo\External\ApiClient;
use KeepersTeam\Webtlo\External\ForumClient;
use KeepersTeam\Webtlo\Settings;
use KeepersTeam\Webtlo\Storage\Clone\TopicsUnregistered;
use KeepersTeam\Webtlo\Storage\Clone\TopicsUntracked;
use KeepersTeam\Webtlo\Storage\Clone\Torrents;
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
        private readonly ApiClient          $apiClient,
        private readonly ForumClient        $forumClient,
        private readonly Settings           $settings,
        private readonly DB                 $db,
        private readonly UpdateTime         $updateTime,
        private readonly ClientFactory      $clientFactory,
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

        // Получаем параметры.
        $config = $this->settings->get();

        // Найдём раздачи из не хранимых подразделов.
        $doUpdateUntracked = (bool) $config['update']['untracked'];
        $this->updateUntracked($doUpdateUntracked);

        // Найдём разрегистрированные раздачи.
        $doUpdateUnregistered = $config['update']['untracked'] && $config['update']['unregistered'];
        $this->updateUnregistered($doUpdateUnregistered);

        $log = json_encode($this->timers);
        if (false !== $log) {
            $this->logger->debug($log);
        }
    }

    /**
     * Проверим количество настроенных торрент-клиентов.
     */
    private function checkClientsCount(): bool
    {
        // Получаем параметры.
        $config = $this->settings->get();

        // Если нет настроенных торрент-клиентов, удалим все раздачи и отметку.
        if (empty($config['clients'])) {
            $this->logger->notice('Торрент-клиенты не найдены.');

            $this->updateTime->setMarkerTime(UpdateMark::CLIENTS->value, 0);
            $this->db->executeStatement('DELETE FROM Torrents WHERE TRUE');

            return false;
        }

        return true;
    }

    private function updateClients(): void
    {
        // Получаем параметры.
        $config = $this->settings->get();

        $this->logger->info(
            'Сканирование торрент-клиентов... Найдено {count} шт.',
            ['count' => count($config['clients'])]
        );

        /** Используемый домен трекера. */
        $forumDomain = $this->settings->getForumDomain();

        /** Клиенты, данные от которых получить не удалось */
        $failedClients = [];
        /** Клиенты исключённые из формирования отчётов и для успешного обновления - не обязательны. */
        $excludedClients = [];

        Timers::start('update_clients');
        foreach ($config['clients'] as $torrentClientId => $torrentClientData) {
            $torrentClientId = (int) $torrentClientId;

            Timers::start("update_client_$torrentClientId");
            $clientTag = sprintf('%s (%s)', $torrentClientData['cm'], $torrentClientData['cl']);

            // Признак исключения раздач клиента из формируемых отчётов.
            if ($torrentClientData['exclude'] ?? false) {
                $excludedClients[] = $torrentClientId;
            }

            try {
                // Подключаемся к торрент-клиенту.
                $client = $this->clientFactory->fromConfigProperties(options: $torrentClientData);

                // Меняем домен трекера, для корректного поиска раздач.
                $client->setDomain(domain: $forumDomain);
            } catch (RuntimeException $e) {
                $this->logger->notice(
                    'Клиент {client} в данный момент недоступен',
                    ['client' => $clientTag, 'error' => $e->getMessage()]
                );
                $failedClients[] = $torrentClientId;

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
                $failedClients[] = $torrentClientId;

                continue;
            }

            $countTorrents = count($torrents);
            foreach ($torrents->getGenerator() as $torrent) {
                $this->cloneTorrents->addTorrent($torrentClientId, $torrent);
            }
            unset($torrents);

            // Запишем данные хранимых раздач во временную таблицу.
            $this->cloneTorrents->cloneFill();

            $this->logger->info('{client} получено раздач: {count} шт за {sec}', [
                'client' => $clientTag,
                'count'  => $countTorrents,
                'sec'    => Timers::getExecTime("update_client_$torrentClientId"),
            ]);

            unset($torrentClientId, $torrentClientData, $countTorrents);
        }
        $this->timers['update_clients'] = Timers::getExecTime('update_clients');

        // Добавим в БД полученные данные о раздачах.
        $this->cloneTorrents->writeTable();

        // Если обновление всех не исключённых клиентов прошло успешно - отметим это.
        if (!count(array_diff($failedClients, $excludedClients))) {
            $this->updateTime->setMarkerTime(UpdateMark::CLIENTS->value);
        }

        // Удалим лишние раздачи из БД, исключая раздачи клиентов с ошибкой.
        $failedClients = KeysObject::create($failedClients);
        $this->cloneTorrents->removeUnusedTorrentsRows(failedClients: $failedClients);
    }

    /**
     * Найдём раздачи из не хранимых подразделов.
     */
    private function updateUntracked(bool $doUpdateUntracked): void
    {
        $config = $this->settings->get();

        $subsections = (array) ($config['subsections'] ?? []);
        $subsections = KeysObject::create(array_keys($subsections));

        if ($doUpdateUntracked) {
            Timers::start('search_untracked');

            $untrackedTorrentHashes = $this->cloneTorrents->selectUntrackedRows(subsections: $subsections);

            $countUntracked = count($untrackedTorrentHashes);
            if ($countUntracked) {
                $this->logger->info(
                    'Найдено уникальных сторонних раздач в клиентах: {count} шт.',
                    ['count' => $countUntracked]
                );

                if ($countUntracked > 150) {
                    $this->logger->notice(
                        'Хранится много сторонних раздач. Рекомендуется отключить поиск сторонних раздач или добавить подразделы в хранимые.'
                    );
                }

                // Пробуем найти в API раздачи по их хешам из клиента.
                $response = $this->apiClient->getTopicsDetails($untrackedTorrentHashes, TopicSearchMode::HASH);

                if ($response instanceof ApiError) {
                    $this->logger->debug(
                        'Не удалось найти данные о раздачах в API. {code}: {text}',
                        ['code' => $response->code, 'text' => $response->text]
                    );

                    $this->logger->debug('hashes', $untrackedTorrentHashes);
                } elseif (count($response->topics)) {
                    foreach ($response->topics as $topicData) {
                        // Пропускаем раздачи в невалидных статусах.
                        if (!TorrentStatus::isValidStatus($topicData->status)) {
                            $this->unregisteredApiTopics[$topicData->hash] = $topicData;

                            continue;
                        }

                        $this->cloneUntracked->addTopic(topic: $topicData);
                    }

                    // Если нашлись существующие на форуме раздачи, то записываем их в БД.
                    $this->cloneUntracked->moveToOrigin();
                }
            }

            $this->timers['search_untracked'] = Timers::getExecTime('search_untracked');
        }
        // Удалим лишние раздачи из БД прочих.
        $this->cloneUntracked->clearUnusedRows();
    }

    /**
     * Найдём разрегистрированные раздачи.
     */
    private function updateUnregistered(bool $doUpdateUnregistered): void
    {
        if ($doUpdateUnregistered) {
            Timers::start('search_unregistered');

            try {
                $unregisteredTopics = $this->cloneUnregistered->searchUnregisteredTopics();

                // Если в БД есть разрегистрированные раздачи, ищем их статус на форуме.
                if (count($unregisteredTopics)) {
                    if (!$this->forumClient->checkConnection()) {
                        throw new RuntimeException('Ошибка подключения к форуму. Поиск прекращён.');
                    }

                    foreach ($unregisteredTopics as $topicId => $infoHash) {
                        $topicData = $this->forumClient->getUnregisteredTopic((int) $topicId);
                        if (null === $topicData) {
                            continue;
                        }

                        // Если о раздаче есть данные в API, то дописываем их, как более верные.
                        $topic = $this->getApiTopicInfo($infoHash);
                        if (null !== $topic) {
                            $topicData['name']   = $topic->title;
                            $topicData['status'] = $topic->status->label();
                            if (empty($topicData['priority'])) {
                                $topicData['priority'] = KeepingPriority::Normal->label();
                            }
                        }

                        // Записываем данные раздачи в буфер.
                        $this->cloneUnregistered->addTopic([
                            $infoHash,
                            $topicData['name'],
                            $topicData['status'],
                            $topicData['priority'],
                            $topicData['transferred_from'],
                            $topicData['transferred_to'],
                            $topicData['transferred_by_whom'],
                        ]);

                        unset($topicId, $topicData, $topic);
                    }

                    $this->cloneUnregistered->fillTempTable();
                }
            } catch (Throwable $e) {
                $this->logger->warning(
                    'Ошибка при поиске разрегистрированных раздач. {error}',
                    ['error' => $e->getMessage()]
                );
            }
            $this->cloneUnregistered->moveToOrigin();

            $this->timers['search_unregistered'] = Timers::getExecTime('search_unregistered');
        }

        // Очищаем ненужные строки.
        $this->cloneUnregistered->clearUnusedRows();
    }

    private function getApiTopicInfo(string $infoHash): ?TopicDetails
    {
        if (isset($this->unregisteredApiTopics[$infoHash])) {
            return $this->unregisteredApiTopics[$infoHash];
        }

        return null;
    }
}
