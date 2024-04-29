<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\Clients;

use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Exception\GuzzleException;
use KeepersTeam\Webtlo\Config\TorrentClientOptions;
use KeepersTeam\Webtlo\Module\Topics;
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

    /** Позволяет ли клиент присваивать раздаче категорию при добавлении. */
    private bool $categoryAddingAllowed = true;

    /** Пауза между добавлением раздач в торрент-клиент, миллисекунды. */
    private int $torrentAddingSleep = 100;

    /**
     * Категории раздач в клиенте.
     *
     * @var ?string[]
     */
    private ?array $categories = null;

    /**
     * Статусы ошибок.
     *
     * @var string[]
     */
    private array $errorStates = ['error', 'missingFiles', 'unknown'];

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

    public function getTorrents(array $filter = []): array
    {
        /** Получить просто список раздач без дополнительных действий */
        $simpleRun = $filter['simple'] ?? false;

        Timers::start('torrents_info');
        $response = $this->makeRequest(url: 'torrents/info');
        Timers::stash('torrents_info');

        $torrents = [];
        Timers::start('processing');
        foreach ($response as $torrent) {
            $clientHash    = $torrent['hash'];
            $torrentHash   = strtoupper($torrent['infohash_v1'] ?? $torrent['hash']);
            $torrentPaused = str_starts_with($torrent['state'], 'paused') ? 1 : 0;
            $torrentError  = in_array($torrent['state'], $this->errorStates) ? 1 : 0;

            $torrents[$torrentHash] = [
                'topic_id'      => null,
                'comment'       => '',
                'done'          => $torrent['progress'],
                'error'         => $torrentError,
                'name'          => $torrent['name'],
                'paused'        => $torrentPaused,
                'time_added'    => $torrent['added_on'],
                'total_size'    => $torrent['total_size'],
                'client_hash'   => $clientHash,
                'tracker_error' => '',
            ];

            if (!$simpleRun) {
                // Получение ошибок трекера.
                // Для раздач на паузе, нет рабочих трекеров и смысла их проверять тоже нет.
                if (!$torrentPaused && empty($torrent['tracker'])) {
                    $torrentTrackers = $this->getTrackers($clientHash);
                    if (!empty($torrentTrackers)) {
                        foreach ($torrentTrackers as $torrentTracker) {
                            if ((int)$torrentTracker['status'] === 4) {
                                $torrents[$torrentHash]['tracker_error'] = $torrentTracker['msg'];
                                break;
                            }
                        }
                    }
                    unset($torrentTrackers);
                }
            }

            unset($clientHash, $torrentHash, $torrentPaused, $torrentError);
        }
        Timers::stash('processing');

        if (!$simpleRun) {
            Timers::start('db_search');
            // Пробуем найти раздачи в локальной БД.
            $topics = Topics::getTopicsIdsByHashes(array_keys($torrents));
            if (count($topics)) {
                $torrents = array_replace_recursive($torrents, $topics);
            }
            unset($topics);
            Timers::stash('db_search');

            // Для раздач, у которых нет ид раздачи, вытаскиваем комментарий
            $emptyTopics = array_filter($torrents, fn($el) => empty($el['topic_id']));
            if (count($emptyTopics)) {
                Timers::start('comment_search');
                foreach ($emptyTopics as $torrentHash => $torrent) {
                    // получение ссылки на раздачу
                    $properties = $this->getProperties($torrent['client_hash']);
                    if (!empty($properties)) {
                        $torrents[$torrentHash]['topic_id'] = $this->getTorrentTopicId($properties['comment']);
                        $torrents[$torrentHash]['comment']  = $properties['comment'];
                    }
                    unset($torrentHash, $torrent, $properties);
                }
                Timers::stash('comment_search');
            }
            unset($emptyTopics);
        }

        $this->logger->debug('Topics search', Timers::getStash());

        return $torrents;
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

        return $this->sendRequest(url: 'torrents/resume', params: $fields);
    }

    public function stopTorrents(array $torrentHashes): bool
    {
        $fields = ['hashes' => implode('|', array_map('strtolower', $torrentHashes))];

        return $this->sendRequest(url: 'torrents/pause', params: $fields);
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
                        ['code' => $e->getCode(), 'message' => htmlspecialchars(trim($e->getMessage()))]
                    );
                }
            } catch (Throwable $e) {
                $this->logger->warning(
                    'Failed to make request',
                    ['code' => $e->getCode(), 'message' => htmlspecialchars(trim($e->getMessage()))]
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
     * @param string $torrentHash
     * @return array<string, mixed>
     */
    private function getProperties(string $torrentHash): array
    {
        return $this->makeRequest(
            url   : 'torrents/properties',
            params: ['hash' => strtolower($torrentHash)]
        );
    }

    /**
     * @param string $torrentHash
     * @return array<string, mixed>[]
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
                $torrent_trackers[] = $tracker;
            }
        }

        return $torrent_trackers;
    }

    private function checkLabelExists(string $labelName = ''): void
    {
        if (empty($labelName)) {
            return;
        }

        if (null === $this->categories) {
            $this->categories = $this->makeRequest(url: 'torrents/categories');
        }

        if (!array_key_exists($labelName, $this->categories)) {
            $this->createCategory($labelName);
            $this->categories[$labelName] = [
                'name' => $labelName,
            ];
        }
    }

    public function __destruct()
    {
        if (!$this->logout()) {
            $this->logger->warning('Unable to logout of qbittorrent api');
        }
    }
}
