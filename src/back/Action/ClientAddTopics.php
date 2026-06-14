<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\Action;

use KeepersTeam\Webtlo\Clients\ClientFactory;
use KeepersTeam\Webtlo\Clients\ClientInterface;
use KeepersTeam\Webtlo\Config\ApiCredentials;
use KeepersTeam\Webtlo\Config\SubFolderType;
use KeepersTeam\Webtlo\Config\SubForum;
use KeepersTeam\Webtlo\Config\SubForums;
use KeepersTeam\Webtlo\Config\TorrentClients;
use KeepersTeam\Webtlo\Config\TorrentDownload;
use KeepersTeam\Webtlo\Data\DownloadedTopic;
use KeepersTeam\Webtlo\External\ForumClient;
use KeepersTeam\Webtlo\Helper;
use KeepersTeam\Webtlo\Storage\Table\Topics;
use KeepersTeam\Webtlo\Storage\Table\Torrents;
use KeepersTeam\Webtlo\Timers;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Добавление раздач в торрент клиент по списку хешей.
 */
final class ClientAddTopics
{
    /**
     * @param ApiCredentials  $apiCredentials  параметры авторизации в API форума
     * @param ForumClient     $forumClient     подключение к форуму
     * @param SubForums       $subsections     хранимые подразделы
     * @param ClientFactory   $clientFactory   подключение к торрент-клиенту
     * @param TorrentClients  $clients         используемые торрент-клиенты
     * @param TorrentDownload $downloadOptions параметры загрузки торрент-файлов
     * @param Torrents        $torrents        таблица раздач в клиентах
     * @param Topics          $topics          таблица раздач в подразделах
     */
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly ApiCredentials  $apiCredentials,
        private readonly ForumClient     $forumClient,
        private readonly SubForums       $subsections,
        private readonly ClientFactory   $clientFactory,
        private readonly TorrentClients  $clients,
        private readonly TorrentDownload $downloadOptions,
        private readonly Torrents        $torrents,
        private readonly Topics          $topics,
    ) {
        // Проверим наличие нужных параметров.
        if (!$this->subsections->count()) {
            throw new RuntimeException('В настройках не найдены хранимые подразделы');
        }

        if (!$this->clients->count()) {
            throw new RuntimeException('В настройках не найдены торрент-клиенты');
        }
    }

    /**
     * @param string[] $hashes
     */
    public function process(array $hashes): void
    {
        Timers::start('add_topics_to_client');
        $this->logger->info('Запущен процесс добавления раздач в торрент-клиенты...');

        // Подключаемся к форуму используя ключи API.
        $this->forumConnect();

        // Получение ID раздач с привязкой к подразделу
        $topicHashesByForums = $this->topics->getGroupedTopics(hashes: $hashes);
        if (!count($topicHashesByForums)) {
            throw new RuntimeException('Не получены идентификаторы раздач с привязкой к подразделу');
        }

        $totalTorrentFilesAdded = 0;
        $usedTorrentClients     = [];
        foreach ($topicHashesByForums as $subForumId => $forumTopics) {
            if (empty($forumTopics)) {
                continue;
            }

            // Параметры хранимого подраздела. Если не нашли - пропускаем.
            $subForum = $this->getSubForumOptions(subForumId: (int) $subForumId);
            if ($subForum === null) {
                continue;
            }

            // Подключаемся к торрент-клиенту. Если недоступен - пропускаем.
            $client = $this->clientFactory->getClientById(clientId: $subForum->clientId);
            if ($client === null) {
                continue;
            }

            // Пробуем скачать торрент-файлы раздач во временную папку.
            $downloadedTorrents = $this->downloadTorrentFiles(forumTopics: $forumTopics);
            unset($subForumId, $forumTopics);

            if (!count($downloadedTorrents)) {
                $this->logger->notice(
                    'Нет скачанных торрент-файлов для добавления их в торрент-клиент "{tag}"',
                    ['tag' => $client->getClientTag()],
                );

                continue;
            }

            $logClient = ['tag' => $client->getClientTag()];

            // Добавляем раздачи в нужный торрент-клиент.
            $addedTorrentHashes = $this->addTorrentsToClient(
                topics  : $downloadedTorrents,
                client  : $client,
                subForum: $subForum,
            );
            unset($downloadedTorrents);

            // Сохраним количество добавленных раздач для общего счётчика.
            $countAddedTorrents = count($addedTorrentHashes);
            if (!$countAddedTorrents) {
                $this->logger->warning('Не удалось добавить раздачи в торрент-клиент "{tag}"', $logClient);

                continue;
            }

            // Указываем раздачам метку, если она не выставлена при добавлении раздач.
            if ($subForum->label !== '' && !$client->isLabelAddingAllowed()) {
                // Ждём добавления раздач, чтобы проставить метку
                sleep((int) round(count($addedTorrentHashes) / 20) + 1);

                // устанавливаем метку
                $response = $client->setLabel(torrentHashes: $addedTorrentHashes, label: $subForum->label);
                if ($response === false) {
                    $this->logger->warning('Возникли проблемы при отправке запроса на установку метки', $logClient);
                }
            }

            // Записываем новые раздачи как хранимые в торрент-клиенте.
            $this->torrents->addDownloadedTorrents(hashes: $addedTorrentHashes, clientId: $subForum->clientId);

            $this->logger->info(
                'Добавлено раздач в торрент-клиент "{tag}": {count} шт.',
                [...$logClient, 'count' => $countAddedTorrents]
            );

            $usedTorrentClients[]   = $subForum->clientId;
            $totalTorrentFilesAdded += $countAddedTorrents;
        }

        $totalTorrentClients = count(array_unique($usedTorrentClients));

        $this->logger->info(
            'Процесс добавления раздач в торрент-клиенты завершён за {sec}',
            ['sec' => Timers::getExecTime('add_topics_to_client')]
        );

        $this->logger->info(
            'Задействовано торрент-клиентов — {clients}, добавлено раздач всего — {topics} шт.',
            ['clients' => $totalTorrentClients, 'topics' => $totalTorrentFilesAdded]
        );
    }

    /**
     * Скачать торрент-файлы по списку раздач.
     *
     * @param array{topic_id: int, info_hash: string}[] $forumTopics
     *
     * @return DownloadedTopic[]
     */
    private function downloadTorrentFiles(array $forumTopics): array
    {
        $torrentFilePathTemplate = $this->getTorrentFilePathTemplate();

        $downloadedTorrents = [];
        foreach ($forumTopics as $row) {
            $topic = new DownloadedTopic(
                hash    : $row['info_hash'],
                topicId : $row['topic_id'],
                filePath: sprintf($torrentFilePathTemplate, $row['info_hash'])
            );

            $stream = $this->forumClient->downloadTorrent(
                infoHash    : $topic->hash,
                addRetracker: $this->downloadOptions->addRetracker,
            );
            if ($stream === null) {
                $this->logger->error('Не удалось скачать торрент-файл', $topic->jsonSerialize());

                continue;
            }

            $torrentFile = $stream->getContents();
            if (empty($torrentFile)) {
                continue;
            }

            // Сохранить содержимое в файл.
            $response = file_put_contents($topic->filePath, $torrentFile);
            if ($response === false) {
                $this->logger->error('Произошла ошибка при сохранении торрент-файла', $topic->jsonSerialize());

                continue;
            }

            $downloadedTorrents[] = $topic;
        }

        return $downloadedTorrents;
    }

    /**
     * @param DownloadedTopic[] $topics
     *
     * @return string[]
     */
    private function addTorrentsToClient(array $topics, ClientInterface $client, SubForum $subForum): array
    {
        $clientAddingSleep = $client->getTorrentAddingSleep();

        // Убираем последний слэш в пути каталога для данных
        $dataFolder = trim($subForum->dataFolder);
        $dataFolder = rtrim($dataFolder, '/\\');

        $addedTorrentHashes = [];
        // Добавление раздач в торрент-клиенты.
        foreach ($topics as $topic) {
            $torrentSavePath = $this->makeTopicContentPath(topic: $topic, dataPath: $dataFolder, subForum: $subForum);

            // Добавляем раздачу в торрент-клиент.
            $response = $client->addTorrent(
                torrentFilePath: $topic->filePath,
                savePath       : $torrentSavePath,
                label          : $subForum->label
            );

            if ($response !== false) {
                $addedTorrentHashes[] = $topic->hash;
            }

            // Пауза между добавлениями раздач, в зависимости от клиента (0.5 сек по умолчанию)
            usleep($clientAddingSleep);
        }

        return $addedTorrentHashes;
    }

    private function forumConnect(): void
    {
        // Проверим подключение к форуму.
        if (!$this->forumClient->checkAccess()) {
            throw new RuntimeException('Ошибка подключения к форуму.');
        }

        // Записываем ключи доступа к API.
        $this->forumClient->setApiCredentials(apiCredentials: $this->apiCredentials);
    }

    private function getTorrentFilePathTemplate(): string
    {
        // Полный путь до каталога сохранения файлов.
        $localPath = Helper::getStorageSubFolderPath(subFolder: 'tfiles');

        // Очищаем каталог от старых файлов.
        Helper::removeDirRecursive($localPath);

        // Создаём каталог для файлов.
        Helper::checkDirRecursive($localPath);

        // Шаблон пути для сохранения файлов
        return Helper::normalizePathEncoding($localPath . DIRECTORY_SEPARATOR . '[webtlo].h%s.torrent');
    }

    /**
     * Создать путь хранения содержимого раздачи на диске в клиенте.
     * В зависимости от настроек подраздела.
     */
    private function makeTopicContentPath(DownloadedTopic $topic, string $dataPath, SubForum $subForum): string
    {
        // Если путь хранения не указан - используем пустую строку.
        // Потому что разные клиенты по разному понимают не абсолютный путь хранения добавляемых раздач.
        // TODO Возможно стоит этот момент доработать.
        if (empty($dataPath)) {
            return '';
        }

        // Если создание подкаталога не выбрано, используем указанный путь без изменений.
        if ($subForum->subFolderType === null) {
            return $dataPath;
        }

        // Подкаталог для каждой раздачи.
        $subFolderPath = match ($subForum->subFolderType) {
            SubFolderType::Topic => (string) $topic->topicId,
            SubFolderType::Hash  => $topic->hash,
        };

        // Попытка угадать, тип ФС торрент-клиента по пути хранения.
        $delimiter = !str_contains($dataPath, '/') ? '\\' : '/';

        return $dataPath . $delimiter . $subFolderPath;
    }

    private function getSubForumOptions(int $subForumId): ?SubForum
    {
        $subForum = $this->subsections->getSubForum(subForumId: $subForumId);
        if ($subForum === null) {
            $this->logger->warning('В настройках нет данных о подразделе с идентификатором "' . $subForumId . '"');

            return null;
        }

        if (!$subForum->clientId) {
            $this->logger->warning('К подразделу "' . $subForumId . '" не привязан торрент-клиент');

            return null;
        }

        return $subForum;
    }
}
