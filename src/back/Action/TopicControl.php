<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\Action;

use KeepersTeam\Webtlo\Clients\ClientFactory;
use KeepersTeam\Webtlo\Clients\ClientInterface;
use KeepersTeam\Webtlo\Clients\Data\Torrents;
use KeepersTeam\Webtlo\Config\TopicControl as ConfigControl;
use KeepersTeam\Webtlo\DTO\KeysObject;
use KeepersTeam\Webtlo\Helper;
use KeepersTeam\Webtlo\Module\Control\DbSearch;
use KeepersTeam\Webtlo\Module\Control\PeerCalc;
use KeepersTeam\Webtlo\Module\Control\Unseeded;
use KeepersTeam\Webtlo\Module\Control\ApiSearch;
use KeepersTeam\Webtlo\Timers;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

final class TopicControl
{
    /** @var array{}|int[]|string[] Исключённые из регулировки подразделов. */
    private array $excludedForums = [];

    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly ConfigControl   $topicControl,
        private readonly PeerCalc        $calc,
        private readonly ClientFactory   $clientFactory,
        private readonly ApiSearch       $api,
        private readonly DbSearch        $db,
        private readonly Unseeded        $unseeded,
    ) {
    }

    /**
     * @param array<string, mixed>[] $config
     */
    public function process(array $config, bool $schedule = false): void
    {
        Timers::start('control');
        $this->logger->info('[Control] Начат процесс регулировки раздач в торрент-клиентах...');

        $this->validateConfig(config: $config);

        // Проверяем возможность запуска регулировки.
        if (!$schedule && !Helper::isScheduleActionEnabled(config: $config, action: 'control')) {
            $this->logger->notice('[Control] Автоматическая регулировка раздач отключена в настройках.');

            return;
        }

        $this->findRepeatedSubForums();
        $this->unseeded->init();

        $topicControl = $this->topicControl;

        // Хранимые подразделы.
        $forums = $this->getKeptForumIds(config: $config);
        foreach ($config['clients'] as $clientId => $torrentClientData) {
            $clientId = (int)$clientId;

            $clientTag = sprintf('%s (%s)', $torrentClientData['cm'], $torrentClientData['cl']);

            $clientControlPeers = PeerCalc::getClientLimit($torrentClientData);
            if ($clientControlPeers === -1) {
                $this->logger->notice("Для клиента $clientTag отключена регулировка.");
                continue;
            }

            Timers::start("control_client_$clientId");
            // Подключаемся к торрент-клиенту.
            $client = $this->getTorrentClient(clientTag: $clientTag, clientProps: $torrentClientData);
            if (null === $client) {
                continue;
            }
            // Получаем раздачи из него.
            $torrents = $this->getClientTorrents($client, $clientTag);
            if (null === $torrents) {
                continue;
            }

            // Получаем раздачи из БД.
            $topicsHashes = $this->db->getStoredHashes(
                forums  : $forums,
                torrents: $torrents,
                timer   : "get_topics_$clientId",
            );

            // Счётчики применения фортуны при переключении состояния раздачи.
            $randomCounter = $randomProc = 0;

            $controlTopics = ['stop' => [], 'start' => []];
            foreach ($topicsHashes as $group => $hashes) {
                // Пропустим регулировку "прочих", если она отключена.
                if (ConfigControl::UnknownHashes === $group && !$topicControl->manageOtherSubsections) {
                    continue;
                }

                $subControlPeers = PeerCalc::getForumLimit(config: $config, group: $group);
                // Пропускаем исключённые из регулировки подразделы.
                if ($subControlPeers === -1) {
                    $this->excludedForums[] = $group;
                    continue;
                }

                Timers::start("subsection_$group");

                $forumUnseededTopics = [];
                if ($this->unseeded->checkLimit()) {
                    $forumUnseededTopics = $this->api->getUnseededHashes(
                        group: $group,
                        days : $topicControl->daysUntilUnseeded
                    );

                    $this->unseeded->updateTotal(count: count($forumUnseededTopics));
                }

                // Лимит пиров для регулировки текущей группы раздач.
                $peerLimit = $this->calc->calcLimit(
                    clientControlPeers    : $clientControlPeers,
                    subsectionControlPeers: $subControlPeers,
                );

                // Получаем данные о пирах искомых раздач и перебираем их.
                $topicsPeers = $this->api->getTopicsPeersGenerator(group: $group, hashes: $hashes);
                foreach ($topicsPeers as $topic) {
                    // Проверяем наличие и статус раздачи в клиенте.
                    $torrent = $torrents->getTorrent(hash: $topic->hash);
                    if (
                        // пропускаем отсутствующий торрент
                        null === $torrent
                        // пропускаем торрент с ошибкой
                        || $torrent->error
                        // пропускаем торрент на загрузке
                        || $torrent->done < 1.0
                    ) {
                        continue;
                    }

                    // Вычисляем необходимое изменение состояния раздачи в клиенте.

                    // Если раздача входит в список не сидированных, то она должна быть запущена.
                    $desiredChange = $this->unseeded->checkTorrent(
                        torrent       : $torrent,
                        unseededHashes: $forumUnseededTopics,
                    );
                    // Если не входит, то вычисляем по общему алгоритму.
                    if (null === $desiredChange) {
                        $desiredChange = $this->calc->determineDesiredState(
                            topic    : $topic,
                            peerLimit: $peerLimit,
                            isSeeding: !$torrent->paused,
                        );
                    }

                    if ($desiredChange->isRandom()) {
                        $randomCounter++;
                    }

                    if ($desiredChange->shouldStartSeeding()) {
                        if ($desiredChange->isRandom()) {
                            $randomProc++;
                        }

                        $controlTopics['start'][] = $torrent->clientHash;
                    } elseif ($desiredChange->shouldStopSeeding()) {
                        if ($desiredChange->isRandom()) {
                            $randomProc++;
                        }

                        $controlTopics['stop'][] = $torrent->clientHash;
                    }

                    unset($topic, $torrent);
                }

                $this->logger->debug('Обработка подраздела', [
                    'forumId'   => $group,
                    'count'     => count($hashes),
                    'unseeded'  => count($forumUnseededTopics),
                    'peerLimit' => $peerLimit,
                    'execTime'  => Timers::getExecTime("subsection_$group"),
                ]);

                unset($group, $hashes, $topicsPeers);
            }

            // Если сработал рандом, запишем в лог.
            if ($randomProc > 0) {
                $this->logger->debug(
                    'Случайное изменение состояния раздач [{randomProc}/{randomTotal}]',
                    ['randomProc' => $randomProc, 'randomTotal' => $randomCounter]
                );
            }

            if (!count($controlTopics['start']) && !count($controlTopics['stop'])) {
                $this->logger->notice(
                    'Регулировка раздач не требуется для торрент-клиента {tag}.',
                    ['tag' => $clientTag]
                );

                continue;
            }

            Timers::start("apply_control_$clientId");

            // Запускаем раздачи.
            if (count($controlTopics['start'])) {
                // TODO перекинуть задачу разбиения хешей на чанки торрент-клиентам.
                foreach (array_chunk($controlTopics['start'], 100) as $hashes) {
                    $response = $client->startTorrents(torrentHashes: $hashes);
                    if ($response === false) {
                        $this->logger->error('Возникли проблемы при отправке запроса на запуск раздач.');
                    }
                }
            }

            // Останавливаем раздачи.
            if (count($controlTopics['stop'])) {
                // TODO перекинуть задачу разбиения хешей на чанки торрент-клиентам.
                foreach (array_chunk($controlTopics['stop'], 100) as $hashes) {
                    $response = $client->stopTorrents(torrentHashes: $hashes);
                    if ($response === false) {
                        $this->logger->error('Возникли проблемы при отправке запроса на остановку раздач.');
                    }
                }
            }

            $this->logger->debug('Отправка команд завершена за {sec}.', ['sec' => Timers::getExecTime("apply_control_$clientId")]);

            $this->logger->info('Регулировка раздач в торрент-клиенте {tag} завершена за {sec}.', [
                'tag' => $clientTag,
                'sec' => Timers::getExecTime("control_client_$clientId"),
            ]);
            $this->logger->info('Запущено {start} шт. Остановлено {stop} шт. Всего {total} шт.', [
                'start' => count($controlTopics['start']),
                'stop'  => count($controlTopics['stop']),
                'total' => $torrents->count(),
            ]);
            unset($controlTopics);
        }

        $this->unseeded->close();
        $this->printExcludedForums();

        $this->logger->info('[Control] Регулировка раздач в торрент-клиентах завершена за {sec}.', [
            'sec' => Timers::getExecTime('control'),
        ]);
        $this->logger->info('-- DONE --');
    }

    /**
     * @param array<string, mixed>[] $config
     */
    private function validateConfig(array $config): void
    {
        if (empty($config['clients'])) {
            throw new RuntimeException('Список торрент-клиентов пуст. Проверьте настройки.');
        }

        if (empty($config['subsections'])) {
            throw new RuntimeException('Нет хранимых подразделов. Проверьте настройки.');
        }
    }

    /**
     * @param array<string, mixed>[] $config
     */
    private function getKeptForumIds(array $config): KeysObject
    {
        return KeysObject::create(array_keys($config['subsections']));
    }

    /**
     * Найти в БД хранимые подразделы, в нескольких клиентах и записать их.
     */
    private function findRepeatedSubForums(): void
    {
        $this->api->setCachedSubForums(forums: $this->db->getRepeatedSubForums());
    }

    /**
     * @param array<string, mixed> $clientProps
     */
    private function getTorrentClient(string $clientTag, array $clientProps): ?ClientInterface
    {
        try {
            $client = $this->clientFactory->fromConfigProperties(options: $clientProps);

            // Проверка доступности торрент-клиента.
            if ($client->isOnline() === false) {
                $this->logger->notice('Клиент {tag} в данный момент недоступен.', ['tag' => $clientTag]);

                return null;
            }

            return $client;
        } catch (Throwable $e) {
            $this->logger->warning('Торрент-клиент {tag} в данный момент недоступен: {error}', [
                'tag'   => $clientTag,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function getClientTorrents(ClientInterface $client, string $clientTag): ?Torrents
    {
        $this->logger->info('Получаем раздачи торрент-клиента {tag}.', ['tag' => $clientTag]);

        Timers::start("get_client_$clientTag");
        try {
            $torrents = $client->getTorrents(['simple' => true]);
        } catch (Throwable $e) {
            $this->logger->warning('Не удалось получить данные о раздачах от торрент-клиента {tag}: {error}', [
                'tag'   => $clientTag,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        $this->logger->info('{tag} получено раздач: {count} шт за {sec}', [
            'tag'   => $clientTag,
            'count' => $torrents->count(),
            'sec'   => Timers::getExecTime("get_client_$clientTag"),
        ]);

        return $torrents;
    }

    /**
     * Проверяем количество исключённых подразделов и пишем в лог.
     */
    private function printExcludedForums(): void
    {
        $excludedForums = array_unique($this->excludedForums);

        if (count($excludedForums)) {
            $this->logger->debug('Регулировка отключена для подразделов №№ {excluded}.', [
                'excluded' => implode(', ', $excludedForums),
            ]);
        }
    }
}
