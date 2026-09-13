<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\Action;

use KeepersTeam\Webtlo\Clients\ClientFactory;
use KeepersTeam\Webtlo\Clients\SavePathLookupInterface;
use KeepersTeam\Webtlo\Config\SubFolderType;
use KeepersTeam\Webtlo\Config\SubForum;
use KeepersTeam\Webtlo\Config\SubForums;
use KeepersTeam\Webtlo\Config\TorrentDownload;
use KeepersTeam\Webtlo\External\Construct\ApiReportConstructor;
use KeepersTeam\Webtlo\External\Data\ApiError;
use KeepersTeam\Webtlo\External\Data\TopicDetails;
use KeepersTeam\Webtlo\External\Data\TopicSearchMode;
use KeepersTeam\Webtlo\External\ForumClient;
use KeepersTeam\Webtlo\Storage\Table\Torrents;
use Psr\Log\LoggerInterface;
use RuntimeException;

/** Add current versions of torrents shown in the unregistered listing. */
final class ClientAddUnregisteredTopics
{
    public function __construct(
        private readonly LoggerInterface      $logger,
        private readonly Torrents             $torrents,
        private readonly ApiReportConstructor $apiConstructor,
        private readonly ClientAddTopics      $addKnownTopics,
        private readonly SubForums            $subForums,
        private readonly ClientFactory        $clientFactory,
        private readonly ForumClient          $forumClient,
        private readonly TorrentDownload      $downloadOptions,
    ) {}

    /**
     * @param mixed[] $selected
     */
    public function process(array $selected): string
    {
        if (count($selected) > 10000) {
            throw new RuntimeException('Выбрано слишком много раздач.');
        }

        $items = [];
        foreach ($selected as $item) {
            if (!is_array($item) || !is_string($item['hash'] ?? null)
                || !preg_match('/^(?:[a-fA-F0-9]{40}|[a-fA-F0-9]{64})$/', $item['hash'])
                || !is_int($item['client_id'] ?? null) || $item['client_id'] <= 0) {
                throw new RuntimeException('Некорректный список выбранных раздач.');
            }

            $key         = $item['hash'] . ':' . $item['client_id'];
            $items[$key] = ['hash' => $item['hash'], 'client_id' => $item['client_id']];
        }

        if ($items === []) {
            throw new RuntimeException('Выберите раздачи для скачивания.');
        }

        $found = [];
        foreach ($this->torrents->getSelectedUnregistered(array_values($items)) as $row) {
            $found[$row['old_hash'] . ':' . $row['client_id']] = $row;
        }

        $knownHashes = [];
        $unresolved  = [];
        $skipped     = 0;

        foreach ($items as $key => $item) {
            $row = $found[$key] ?? null;
            if ($row === null || (int) $row['topic_id'] <= 0) {
                $this->logger->warning('Выбранная раздача отсутствует или не имеет ID на форуме', $item);
                ++$skipped;

                continue;
            }

            if (!empty($row['current_hash'])) {
                $knownHashes[] = $row['current_hash'];
            } else {
                $unresolved[] = $row;
            }
        }

        $knownResult = '';
        if ($knownHashes !== []) {
            try {
                $knownResult = $this->addKnownTopics->process(array_values(array_unique($knownHashes)));
            } catch (RuntimeException $e) {
                $this->logger->warning('Не удалось добавить раздачи с известными хешами: {error}', [
                    'error' => $e->getMessage(),
                ]);
                $knownResult = $e->getMessage();
            }
        }

        $addedMissing = 0;

        if ($unresolved !== []) {
            $addedMissing = $this->addMissingTopics($unresolved, $skipped);
        }

        $missingResult = $unresolved === [] ? '' : sprintf(
            'Раздачи без локальных данных: добавлено %d из %d.',
            $addedMissing,
            count($unresolved),
        );
        if ($skipped > 0) {
            $missingResult .= sprintf(' Пропущено выбранных строк: %d.', $skipped);
        }
        $result = trim($knownResult . ' ' . $missingResult);
        $this->logger->info($result);

        return $result;
    }

    /**
     * @param array<int, array{old_hash: string, client_id: int, topic_id: int, current_hash: ?string}> $unresolved
     */
    private function addMissingTopics(array $unresolved, int &$skipped): int
    {
        $topicIds = array_values(array_unique(array_map(
            static fn(array $row): int => (int) $row['topic_id'],
            $unresolved,
        )));
        try {
            $response = $this->apiConstructor->createRequestClient()->getTopicsDetails(
                topics           : $topicIds,
                searchMode       : TopicSearchMode::ID,
                lookupOldVersions: false,
            );
        } catch (RuntimeException $e) {
            $this->logger->warning('Не удалось получить актуальные версии раздач из API: {error}', [
                'error' => $e->getMessage(),
            ]);
            $skipped += count($unresolved);

            return 0;
        }
        if ($response instanceof ApiError) {
            $this->logger->warning('Не удалось получить актуальные версии раздач из API: {error}', [
                'error' => $response->text,
            ]);
            $skipped += count($unresolved);

            return 0;
        }

        $actualById = [];

        foreach ($response->actualTopics as $topic) {
            $actualById[$topic->id] = $topic;
        }

        /** @var array<string, array{topic: TopicDetails, client_id: int, sub_forum: ?SubForum, old_hashes: string[]}> $planned */
        $planned = [];

        foreach ($unresolved as $row) {
            $topic = $actualById[(int) $row['topic_id']] ?? null;
            if ($topic === null || !$topic->status->isValid() || $topic->hash === $row['old_hash']) {
                $this->logger->warning('Актуальная версия раздачи не найдена или недоступна', [
                    'topic_id' => $row['topic_id'], 'hash' => $row['old_hash'],
                ]);
                ++$skipped;

                continue;
            }

            $subForum = $this->subForums->getSubForum($topic->forumId);
            if ($subForum !== null && $subForum->clientId <= 0) {
                $this->logger->warning('У подраздела не выбран торрент-клиент', ['forum_id' => $topic->forumId]);
                ++$skipped;

                continue;
            }

            $clientId = $subForum?->clientId ?: (int) $row['client_id'];
            $key      = $clientId . ':' . strtoupper($topic->hash);
            if (!isset($planned[$key])) {
                $planned[$key] = [
                    'topic'      => $topic,
                    'client_id'  => $clientId,
                    'sub_forum'  => $subForum,
                    'old_hashes' => [],
                ];
            } else {
                $this->logger->info('Повторная предыдущая версия актуальной раздачи пропущена', [
                    'topic_id' => $topic->id, 'client_id' => $clientId,
                ]);
                ++$skipped;
            }

            if ($subForum === null) {
                $planned[$key]['old_hashes'][] = $row['old_hash'];
            }
        }

        $existing = $this->torrents->getExistingClientHashes(array_map(
            static fn(array $item): array => ['hash' => $item['topic']->hash, 'client_id' => $item['client_id']],
            array_values($planned),
        ));

        $byClient = [];

        foreach ($planned as $key => $item) {
            if (isset($existing[$key])) {
                $this->logger->info('Актуальная версия уже есть в торрент-клиенте', [
                    'topic_id' => $item['topic']->id, 'client_id' => $item['client_id'],
                ]);
                ++$skipped;

                continue;
            }

            $byClient[$item['client_id']][$key] = $item;
        }

        $added = 0;

        foreach ($byClient as $clientId => $clientTopics) {
            $client = $this->clientFactory->getClientById((int) $clientId);
            if ($client === null) {
                $skipped += count($clientTopics);

                continue;
            }

            $oldHashes = [];

            foreach ($clientTopics as $item) {
                if ($item['sub_forum'] === null) {
                    array_push($oldHashes, ...$item['old_hashes']);
                }
            }

            $savePaths = $oldHashes !== [] && $client instanceof SavePathLookupInterface
                ? $client->getTorrentSavePaths(array_values(array_unique($oldHashes)))
                : [];
            $labels = [];

            foreach ($clientTopics as $item) {
                $topic    = $item['topic'];
                $subForum = $item['sub_forum'];
                if ($subForum !== null) {
                    $savePath = self::makeTopicContentPath($topic, $subForum);
                    $label    = $subForum->label;
                } else {
                    $paths = [];
                    foreach ($item['old_hashes'] as $oldHash) {
                        $path = $savePaths[strtoupper($oldHash)] ?? null;
                        if ($path !== null) {
                            $paths[$path] = true;
                        }
                    }

                    if (count($paths) !== 1) {
                        $this->logger->warning('Не удалось однозначно определить каталог предыдущей версии', [
                            'topic_id' => $topic->id, 'client_id' => $clientId,
                        ]);
                        ++$skipped;

                        continue;
                    }

                    $savePath = (string) array_key_first($paths);
                    $label    = '';
                }

                $stream = $this->forumClient->downloadTorrent(
                    infoHash    : $topic->hash,
                    addRetracker: $this->downloadOptions->addRetracker,
                );
                if ($stream === null || ($content = $stream->getContents()) === '') {
                    $this->logger->warning('Не удалось скачать торрент-файл актуальной версии', [
                        'topic_id' => $topic->id,
                    ]);
                    ++$skipped;

                    continue;
                }

                if (!$client->addTorrentContent(content: $content, savePath: $savePath, label: $label)) {
                    $this->logger->warning('Не удалось добавить актуальную версию в торрент-клиент', [
                        'topic_id' => $topic->id, 'client_id' => $clientId,
                    ]);
                    ++$skipped;

                    continue;
                }

                $this->torrents->insertAddedTopic(
                    hash    : $topic->hash,
                    clientId: (int) $clientId,
                    topicId : $topic->id,
                    name    : $topic->title,
                    size    : $topic->size,
                );
                if ($label !== '' && !$client->isLabelAddingAllowed()) {
                    $labels[$label][] = $topic->hash;
                }

                ++$added;
                usleep($client->getTorrentAddingSleep());
            }

            foreach ($labels as $label => $hashes) {
                sleep((int) round(count($hashes) / 20) + 1);
                if (!$client->setLabel(torrentHashes: $hashes, label: (string) $label)) {
                    $this->logger->warning('Не удалось установить метку после добавления', [
                        'client_id' => $clientId, 'label' => $label,
                    ]);
                }
            }
        }

        return $added;
    }

    private static function makeTopicContentPath(TopicDetails $topic, SubForum $subForum): string
    {
        $dataFolder = rtrim(trim($subForum->dataFolder), '/\\');
        if ($dataFolder === '' || $subForum->subFolderType === null) {
            return $dataFolder;
        }

        $subFolder = match ($subForum->subFolderType) {
            SubFolderType::Topic => (string) $topic->id,
            SubFolderType::Hash  => $topic->hash,
        };
        $delimiter = str_contains($dataFolder, '/') ? '/' : '\\';

        return $dataFolder . $delimiter . $subFolder;
    }
}
