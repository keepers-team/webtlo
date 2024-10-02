<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\Clients;

use Generator;
use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Exception\GuzzleException;
use KeepersTeam\Webtlo\Clients\Data\Torrents;
use KeepersTeam\Webtlo\Config\TorrentClientOptions;
use KeepersTeam\Webtlo\Clients\Data\Torrent;
use KeepersTeam\Webtlo\Helper;
use KeepersTeam\Webtlo\Timers;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Class Qbittorrent
 * Supported by qBittorrent 4.1 and later
 * https://github.com/qbittorrent/qBittorrent/wiki/WebUI-API-(qBittorrent-4.1)
 */
final class Qbittorrent implements ClientInterface
{
    use Traits\AuthClient;
    use Traits\AllowedFunctions;
    use Traits\CheckDomain;
    use Traits\RetryMiddleware;
    use Traits\TopicIdSearch;

    /** Позволяет ли клиент присваивать раздаче категорию при добавлении. */
    private bool $categoryAddingAllowed = true;

    /** Пауза между добавлением раздач в торрент-клиент, миллисекунды. */
    private int $torrentAddingSleep = 100;

    /** Версия webApi. */
    private ?string $apiVersion = null;

    /**
     * Категории раздач в клиенте.
     *
     * @var ?array<string, array<string, string>>
     */
    private ?array $categories = null;

    /**
     * Статусы ошибок.
     *
     * @var string[]
     */
    private const errorStates = ['error', 'missingFiles', 'unknown'];

    /**
     * Статусы остановленной раздачи.
     *
     * @var string[]
     */
    private const pauseStates = [
        // Статусы webApi < 2.11
        'pausedUP',
        'pausedDL',
        // Статусы webApi >= 2.11
        'stoppedUP',
        'stoppedDL',
    ];

    private Client    $client;
    private CookieJar $jar;

    public function __construct(
        private readonly LoggerInterface      $logger,
        private readonly TorrentClientOptions $options
    ) {
        $this->jar = new CookieJar();

        // Параметры клиента.
        $this->client = new Client([
            'base_uri' => $this->getClientBase($this->options, 'api/v2/'),
            'cookies'  => $this->jar,
            'handler'  => $this->getDefaultHandler(),
            // Timeout options
            ...$this->options->getTimeoutOptions(),
        ]);

        if (!$this->login()) {
            throw new RuntimeException(
                'Не удалось авторизоваться в qbittorrent api. Проверьте параметры доступа к клиенту.'
            );
        }
    }

    public function getTorrents(array $filter = []): Torrents
    {
        /** Получить просто список раздач без дополнительных действий */
        $simpleRun = (bool)($filter['simple'] ?? 0);

        $generator = $this->generateTorrentsList(simpleRun: $simpleRun);

        $torrents = [];
        foreach ($generator as $hash => $payload) {
            $hash = (string)$hash;

            $torrents[$hash] = new Torrent(
                topicHash   : $hash,
                clientHash  : (string)$payload['client_hash'],
                name        : (string)$payload['name'],
                topicId     : $payload['topic_id'] ?: null,
                size        : (int)$payload['total_size'],
                added       : Helper::makeDateTime((int)$payload['time_added']),
                done        : $payload['done'],
                paused      : (bool)$payload['paused'],
                error       : (bool)$payload['error'],
                trackerError: $payload['tracker_error'] ?: null,
                comment     : $payload['comment'] ?: null,
                storagePath : $payload['storagePath'] ?? null
            );
        }

        $this->logger->debug('Done processing', Timers::getStash());

        return new Torrents($torrents);
    }

    public function addTorrent(string $torrentFilePath, string $savePath = '', string $label = ''): bool
    {
        $content = file_get_contents($torrentFilePath);
        if ($content === false) {
            $this->logger->error('Failed to upload file', ['filename' => basename($torrentFilePath)]);

            return false;
        }

        return $this->addTorrentContent($content, $savePath, $label);
    }

    public function addTorrentContent(string $content, string $savePath = '', string $label = ''): bool
    {
        $fields = [
            [
                'name'     => 'torrents',
                'filename' => 'torrentName.torrent',
                'contents' => $content,
                'headers'  => ['Content-Type' => 'application/x-bittorrent'],
            ],
            ['name' => 'savepath', 'contents' => $savePath],
        ];

        if (!empty($label)) {
            $this->checkLabelExists($label);
            $fields[] = ['name' => 'category', 'contents' => $label];
        }

        try {
            $response = $this->client->post('torrents/add', ['multipart' => $fields]);

            return $response->getStatusCode() === 200;
        } catch (GuzzleException $e) {
            if ($e->getCode() === 415) {
                $this->logger->error('Torrent file is not valid');
            } else {
                $this->logger->warning(
                    'Failed to add torrent',
                    ['code' => $e->getCode(), 'message' => $e->getMessage()]
                );
            }
        }

        return false;
    }

    public function setLabel(array $torrentHashes, string $label = ''): bool
    {
        $this->checkLabelExists($label);

        $fields = [
            'hashes'   => implode('|', array_map('strtolower', $torrentHashes)),
            'category' => $label,
        ];

        try {
            $response = $this->request(url: 'torrents/setCategory', params: $fields);

            return $response->getStatusCode() === 200;
        } catch (GuzzleException $e) {
            if ($e->getCode() === 409) {
                $this->logger->error('Category name does not exist', ['name' => $label]);
            } else {
                $this->logger->warning(
                    'Failed to set category',
                    ['code' => $e->getCode(), 'message' => $e->getMessage()]
                );
            }
        }

        return false;
    }

    public function createCategory(string $categoryName): void
    {
        $fields = [
            'category' => $categoryName,
            'savePath' => '',
        ];

        try {
            $this->request(url: 'torrents/createCategory', params: $fields);
        } catch (GuzzleException $e) {
            $statusCode = $e->getCode();
            if ($statusCode === 400) {
                $this->logger->error('Category name is empty');
            } elseif ($statusCode === 409) {
                $this->logger->error('Category name is invalid', ['name' => $categoryName]);
            }
        }
    }

    public function startTorrents(array $torrentHashes, bool $forceStart = false): bool
    {
        $fields = ['hashes' => implode('|', array_map('strtolower', $torrentHashes))];

        $methodName = $this->getTorrentMethodName(method: 'start');

        return $this->sendRequest(url: $methodName, params: $fields);
    }

    public function stopTorrents(array $torrentHashes): bool
    {
        $fields = ['hashes' => implode('|', array_map('strtolower', $torrentHashes))];

        $methodName = $this->getTorrentMethodName(method: 'stop');

        return $this->sendRequest(url: $methodName, params: $fields);
    }

    public function removeTorrents(array $torrentHashes, bool $deleteFiles = false): bool
    {
        $fields = [
            'hashes'      => implode('|', array_map('strtolower', $torrentHashes)),
            'deleteFiles' => $deleteFiles ? 'true' : 'false',
        ];

        return $this->sendRequest(url: 'torrents/delete', params: $fields);
    }

    public function recheckTorrents(array $torrentHashes): bool
    {
        $fields = ['hashes' => implode('|', array_map('strtolower', $torrentHashes))];

        return $this->sendRequest(url: 'torrents/recheck', params: $fields);
    }

    /**
     * Авторизация в торрент-клиенте.
     */
    private function login(): bool
    {
        if (!$this->authenticated) {
            try {
                $response = $this->client->post('auth/login', [
                    'form_params' => [
                        'username' => $this->options->credentials->username,
                        'password' => $this->options->credentials->password,
                    ],
                ]);

                // Проверяем наличие куки авторизации.
                $this->authenticated =
                    200 === $response->getStatusCode()
                    && $this->checkSID();

                return $this->authenticated;
            } catch (GuzzleException $e) {
                if ($e->getCode() === 403) {
                    $this->logger->error("User's IP is banned for too many failed login attempts");
                } else {
                    $this->logger->warning(
                        'Failed to make request',
                        ['code' => $e->getCode(), 'message' => $e->getMessage()]
                    );
                }
            } catch (Throwable $e) {
                $this->logger->warning(
                    'Failed to make request',
                    ['code' => $e->getCode(), 'message' => $e->getMessage()]
                );
            }

            return false;
        }

        return true;
    }

    /**
     * Завершить сессию.
     */
    private function logout(): bool
    {
        if ($this->authenticated) {
            try {
                $response = $this->client->post('auth/logout', ['form_params' => []]);

                $this->authenticated = !($response->getStatusCode() === 200);

                return !$this->authenticated;
            } catch (Throwable) {
            }

            return false;
        }

        return true;
    }

    /**
     * Проверяем наличие куки авторизации.
     */
    private function checkSID(): bool
    {
        $sid = $this->jar->getCookieByName('SID');
        if (null !== $sid) {
            $this->logger->debug('Got qbittorrent auth token', $sid->toArray());

            return true;
        }

        return false;
    }

    /**
     * @param string               $url
     * @param array<string, mixed> $params
     * @return ResponseInterface
     * @throws GuzzleException
     */
    private function request(string $url, array $params = []): ResponseInterface
    {
        return $this->client->post($url, ['form_params' => $params]);
    }

    /**
     * @param string               $url
     * @param array<string, mixed> $params
     * @return array<int|string, mixed>
     */
    private function makeRequest(string $url, array $params = []): array
    {
        try {
            $response = $this->request(url: $url, params: $params);
        } catch (GuzzleException $e) {
            $this->logger->error('Failed to make request', ['error' => $e->getCode(), 'message' => $e->getMessage()]);

            throw new RuntimeException('Failed to make request');
        }

        return json_decode($response->getBody()->getContents(), true);
    }

    /**
     * @param string               $url
     * @param array<string, mixed> $params
     * @return bool
     */
    private function sendRequest(string $url, array $params = []): bool
    {
        try {
            $response = $this->request(url: $url, params: $params);

            return $response->getStatusCode() === 200;
        } catch (Throwable $e) {
            $this->logger->warning('Failed to send request', ['code' => $e->getCode(), 'message' => $e->getMessage()]);
        }

        return false;
    }

    /**
     * Получить версию webApi торрент-клиента.
     */
    private function getApiVersion(): string
    {
        if (null !== $this->apiVersion) {
            return $this->apiVersion;
        }

        $apiVersion = 'default';
        try {
            $response = $this->request(url: 'app/webapiVersion');
            $apiVersion = $response->getBody()->getContents();
        } catch (Throwable $e) {
            $this->logger->warning(
                'Failed to get webApiVersion',
                ['code' => $e->getCode(), 'message' => $e->getMessage()]
            );
        }

        return $this->apiVersion = $apiVersion;
    }

    /**
     * Определить метод, который нужно вызвать в зависимости от версии webApi.
     */
    private function getTorrentMethodName(string $method): string
    {
        /**
         * Действия для запуска и остановки раздач по умолчанию (webApi < 2.11.0).
         * https://github.com/qbittorrent/qBittorrent/wiki/WebUI-API-(qBittorrent-4.1)#pause-torrents
         * https://github.com/qbittorrent/qBittorrent/wiki/WebUI-API-(qBittorrent-4.1)#resume-torrents
         */
        $actions = ['start' => 'resume', 'stop' => 'pause'];

        // Для версий кубита 5.0.0 (webApi >= 2.11) и выше - новые пути.
        // TODO добавить ссылку на описание, когда его сделают.
        if (version_compare($this->getApiVersion(), '2.11.0') >= 0) {
            $actions = ['start' => 'start', 'stop' => 'stop'];
        }

        return sprintf('torrents/%s', $actions[$method] ?? '');
    }

    private function generateTorrentsList(bool $simpleRun): Generator
    {
        // Получаем и обрабатываем список раздач от клиента.
        $torrents = $this->processTorrents(
            clientTorrents: $this->requestTorrents(),
            callback      : $simpleRun ? null : fn(string $clientHash) => $this->checkTorrentTrackers($clientHash)
        );

        if (!$simpleRun) {
            // Попытка найти ид раздачи в локальных таблицах.
            $this->tryFillTopicIdFromTopics($torrents);
            $this->tryFillTopicIdFromTorrents($torrents);

            // Для раздач, у которых нет ид раздачи, вытаскиваем комментарий.
            $this->tryFillTopicIdFromComments($torrents);
        }

        foreach ($torrents as $hash => $torrent) {
            yield $hash => $torrent;
        }
    }

    private function requestTorrents(): Generator
    {
        Timers::start('torrents_info');
        $response = $this->makeRequest(url: 'torrents/info');
        Timers::stash('torrents_info');

        foreach ($response as $torrent) {
            yield $torrent;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function processTorrents(Generator $clientTorrents, ?callable $callback = null): array
    {
        Timers::start('processing');

        $torrents = [];
        foreach ($clientTorrents as $torrent) {
            $clientHash    = strtoupper($torrent['hash']);
            $torrentHash   = strtoupper($torrent['infohash_v1'] ?? $clientHash);
            $torrentPaused = self::isTorrentStatePaused(state: (string)$torrent['state']);
            $torrentError  = self::isTorrentStateError(state: (string)$torrent['state']);
            $trackerError  = null;

            // Процент загрузки торрента.
            $progress = $torrent['progress'];
            if ($progress === 1 && !empty($torrent['availability'])) {
                if ($torrent['availability'] > 0 && $torrent['availability'] < 1) {
                    $progress = (float)$torrent['availability'];
                }
            }

            // Получение ошибок трекера.
            if (null !== $callback) {
                // Для раздач на паузе, нет рабочих трекеров и смысла их проверять тоже нет.
                if (!$torrentPaused && empty($torrent['tracker'])) {
                    $trackerError = $callback($clientHash);
                    if (null !== $trackerError) {
                        $torrentError = true;
                    }
                }
            }

            $torrents[$torrentHash] = [
                'topic_id'      => null,
                'comment'       => null,
                'done'          => $progress,
                'error'         => $torrentError,
                'name'          => $torrent['name'],
                'paused'        => $torrentPaused,
                'time_added'    => $torrent['added_on'],
                'total_size'    => $torrent['total_size'],
                'client_hash'   => $clientHash,
                'storagePath'   => $torrent['save_path'],
                'tracker_error' => $trackerError,
            ];

            unset($torrent, $clientHash, $torrentHash);
            unset($torrentPaused, $torrentError, $trackerError, $progress);
        }
        Timers::stash('processing');

        return $torrents;
    }

    /**
     * @param string $torrentHash
     * @return array{}|array{url: string, status: int, message: string}[]
     */
    private function getTrackers(string $torrentHash): array
    {
        $torrent_trackers = [];

        $trackers = $this->makeRequest(
            url   : 'torrents/trackers',
            params: ['hash' => strtolower($torrentHash)]
        );
        foreach ($trackers as $tracker) {
            if (!preg_match('/\*\*.*\*\*/', $tracker['url'])) {
                $torrent_trackers[] = [
                    'url'     => $tracker['url'],
                    'status'  => (int)$tracker['status'],
                    'message' => $tracker['msg'],
                ];
            }
        }

        return $torrent_trackers;
    }

    private function checkTorrentTrackers(string $clientHash): ?string
    {
        $trackers = $this->getTrackers($clientHash);

        if (!empty($trackers)) {
            foreach ($trackers as $tracker) {
                if ($tracker['status'] === 4) {
                    return (string)$tracker['message'];
                }
            }
        }

        return null;
    }

    /**
     * @param string $torrentHash
     * @return array<string, mixed>
     */
    private function getProperties(string $torrentHash): array
    {
        $properties = $this->makeRequest(
            url   : 'torrents/properties',
            params: ['hash' => strtolower($torrentHash)]
        );

        return Helper::convertKeysToString(array: $properties);
    }

    private function checkLabelExists(string $labelName = ''): void
    {
        if (empty($labelName)) {
            return;
        }

        if (null === $this->categories) {
            $this->categories = Helper::convertKeysToString(
                array: $this->makeRequest(url: 'torrents/categories')
            );
        }

        if (!array_key_exists($labelName, $this->categories)) {
            $this->createCategory($labelName);
            $this->categories[$labelName] = [
                'name' => $labelName,
            ];
        }
    }

    /**
     * Пробуем найти ид раздачи в дополнительных данных торрента.
     *
     * @param array<string, mixed> $torrents
     */
    private function tryFillTopicIdFromComments(array &$torrents): void
    {
        Timers::start('comment_search');

        $emptyTopics = self::getEmptyTopics($torrents);
        if (count($emptyTopics)) {
            $this->logger->debug('Start search torrents in comment column', ['empty' => count($emptyTopics)]);

            foreach ($emptyTopics as $torrentHash => $torrent) {
                $properties = $this->getProperties($torrent['client_hash']);
                if (!empty($properties)) {
                    $torrents[$torrentHash]['topic_id'] = $this->getTorrentTopicId($properties['comment']);
                    $torrents[$torrentHash]['comment']  = $properties['comment'];
                }

                unset($torrentHash, $torrent, $properties);
            }

            Timers::stash('comment_search');
            $this->logger->debug('End search torrents in comment column');
        }
    }

    private static function isTorrentStatePaused(string $state): bool
    {
        return in_array($state, self::pauseStates, true);
    }

    private static function isTorrentStateError(string $state): bool
    {
        return in_array($state, self::errorStates, true);
    }

    public function __destruct()
    {
        if (!$this->logout()) {
            $this->logger->warning('Unable to logout of qbittorrent api');
        }
    }
}
