<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\Clients;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\GuzzleException;
use KeepersTeam\Webtlo\Config\TorrentClientOptions;
use KeepersTeam\Webtlo\Module\Topics;
use KeepersTeam\Webtlo\Module\Torrents;
use KeepersTeam\Webtlo\Timers;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Class Utorrent
 * Supported by uTorrent 1.8.2 and later
 * https://forum.utorrent.com/topic/21814-web-ui-api/
 */
final class Utorrent implements ClientInterface
{
    use Traits\AuthClient;
    use Traits\AllowedFunctions;
    use Traits\CheckDomain;
    use Traits\RetryMiddleware;

    private const HashesPerRequest = 32;

    private bool $authenticated = false;

    private string $token;

    private Client $client;

    public function __construct(
        private readonly LoggerInterface      $logger,
        private readonly TorrentClientOptions $options
    ) {
        // Параметры клиента.
        $clientOptions = [
            'base_uri' => $this->getClientBase($this->options, 'gui/'),
            'handler'  => $this->getDefaultHandler(),
            // Timeout options
            ...$this->options->getTimeoutOptions(),
            // Auth options
            ...$this->options->getBasicAuth(),
        ];

        $this->client = new Client($clientOptions);

        if (!$this->login()) {
            throw new RuntimeException(
                'Не удалось авторизоваться в utorrent api. Проверьте параметры доступа к клиенту.'
            );
        }
    }

    public function getTorrents(array $filter = []): array
    {
        /** Получить просто список раздач без дополнительных действий */
        $simpleRun = $filter['simple'] ?? false;

        Timers::start('torrents_info');
        $response = $this->makeRequest(action: '1', method: 'list');
        Timers::stash('torrents_info');

        $torrents = [];
        Timers::start('processing');
        foreach ($response['torrents'] as $torrent) {
            /* status reference
                0 - loaded
                1 - queued
                2 - paused
                3 - error
                4 - checked
                5 - start after check
                6 - checking
                7 - started
            */
            $torrentState  = decbin($torrent[1]);
            $torrentHash   = strtoupper($torrent[0]);
            $torrentPaused = $torrentState[2] || !$torrentState[7] ? 1 : 0;

            $torrents[$torrentHash] = [
                'topic_id'      => null,
                'comment'       => '',
                'done'          => $torrent[4] / 1000,
                'error'         => $torrentState[3],
                'name'          => $torrent[2],
                'paused'        => $torrentPaused,
                'time_added'    => '',
                'total_size'    => $torrent[3],
                'tracker_error' => '',
            ];
        }
        Timers::stash('processing');

        if (!$simpleRun) {
            // Пробуем найти раздачи в локальной БД.
            Timers::start('db_topics_search');
            $topics = Topics::getTopicsIdsByHashes(array_keys($torrents));
            if (count($topics)) {
                $torrents = array_replace_recursive($torrents, $topics);
            }
            Timers::stash('db_topics_search');

            // Пробуем найти раздачи в локальной таблице раздач в клиентах.
            $emptyTopics = array_filter($torrents, fn($el) => empty($el['topic_id']));
            if (count($emptyTopics)) {
                Timers::start('db_torrents_search');
                $topics = Torrents::getTopicsIdsByHashes(array_keys($emptyTopics));
                if (count($topics)) {
                    $torrents = array_replace_recursive($torrents, $topics);
                }
                unset($topics);
                Timers::stash('db_torrents_search');
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
        $this->setSetting('dir_active_download_flag', '1');
        if (!empty($savePath)) {
            $this->setSetting('dir_active_download', $savePath);
            sleep(1);
        }

        // Данные добавляемого торрента.
        $fields = [
            [
                'name'     => 'torrent_file',
                'filename' => 'torrentName.torrent',
                'contents' => $content,
                'headers'  => ['Content-Type' => 'application/x-bittorrent'],
            ],
        ];

        $query = $this->buildHttpQuery(method: 'action', action: 'add-file');

        try {
            $response = $this->client->post('', ['query' => $query, 'multipart' => $fields]);

            return $response->getStatusCode() === 200;
        } catch (GuzzleException $e) {
            $this->logger->warning(
                'Failed to add torrent',
                ['code' => $e->getCode(), 'message' => $e->getMessage()]
            );
        }

        return false;
    }

    public function setLabel(array $torrentHashes, string $label = ''): bool
    {
        return $this->setProperties($torrentHashes, 'label', $label);
    }

    public function startTorrents(array $torrentHashes, bool $forceStart = false): bool
    {
        $action = $forceStart ? 'forcestart' : 'start';

        return $this->sendRequest(action: $action, params: ['hashes' => $torrentHashes]);
    }

    public function recheckTorrents(array $torrentHashes): bool
    {
        // uTorrent может перехешировать только остановленные раздачи.
        if ($this->stopTorrents($torrentHashes)) {
            return $this->sendRequest(action: 'recheck', params: ['hashes' => $torrentHashes]);
        }

        return false;
    }

    public function stopTorrents(array $torrentHashes): bool
    {
        return $this->sendRequest(action: 'stop', params: ['hashes' => $torrentHashes]);
    }

    public function removeTorrents(array $torrentHashes, bool $deleteFiles = false): bool
    {
        $action = $deleteFiles ? 'removedata' : 'remove';

        return $this->sendRequest(action: $action, params: ['hashes' => $torrentHashes]);
    }

    /**
     * Авторизация в торрент-клиенте.
     */
    private function login(): bool
    {
        if (!$this->authenticated) {
            try {
                $response = $this->client->post('token.html');

                $html = $response->getBody()->getContents();

                // Проверяем токен авторизации.
                preg_match('|<div id=\'token\'.+>(.*)</div>|', $html, $tokenMatches);
                if (!empty($tokenMatches)) {
                    $this->token = $tokenMatches[1];

                    $this->authenticated = true;
                }

                return $this->authenticated;
            } catch (ClientException $e) {
                $response = $e->getResponse();

                if (401 === $response->getStatusCode()) {
                    $this->logger->error('Failed to authenticate');
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
     * @param string               $action
     * @param array<string, mixed> $params
     * @param string               $method
     * @return ResponseInterface
     * @throws GuzzleException
     */
    private function request(string $action, array $params = [], string $method = 'action'): ResponseInterface
    {
        // Если передан список хешей, то пересобираем его в строку с повторением ключа
        // hash=hash1&hash=hash2...
        $hashes = [];
        if (!empty($params['hashes'])) {
            $hashes = array_map(fn($el) => "hash=$el", $params['hashes']);
        }
        unset($params['hashes']);

        $props = $this->buildHttpQuery(method: $method, action: $action, params: $params);
        $query = implode('&', [$props, ...$hashes]);

        return $this->client->get('', ['query' => $query]);
    }

    /**
     * @param string               $action
     * @param array<string, mixed> $params
     * @param string               $method
     * @return array<string, mixed>
     */
    private function makeRequest(string $action, array $params = [], string $method = 'action'): array
    {
        try {
            $response = $this->request(action: $action, params: $params, method: $method);
        } catch (GuzzleException $e) {
            $this->logger->error('Failed to make request', ['error' => $e->getCode(), 'message' => $e->getMessage()]);

            throw new RuntimeException('Failed to make request');
        }

        return json_decode($response->getBody()->getContents(), true);
    }

    /**
     * @param string               $action
     * @param array<string, mixed> $params
     * @param string               $method
     * @return bool
     */
    private function sendRequest(string $action, array $params = [], string $method = 'action'): bool
    {
        try {
            $response = $this->request(action: $action, params: $params, method: $method);

            return $response->getStatusCode() === 200;
        } catch (Throwable $e) {
            $this->logger->warning('Failed to send request', ['code' => $e->getCode(), 'message' => $e->getMessage()]);
        }

        return false;
    }

    /**
     * Кодируем выполняемое действие + токен авторизации.
     *
     * @param string               $method
     * @param string               $action
     * @param array<string, mixed> $params
     * @return string
     */
    private function buildHttpQuery(string $method, string $action, array $params = []): string
    {
        return http_build_query([
            'token' => $this->token,
            $method => $action,
            ...$params,
        ]);
    }

    /**
     * Изменение параметров торрента.
     *
     * @param string[] $hashes
     * @param string   $property
     * @param string   $value
     * @return bool
     */
    private function setProperties(array $hashes, string $property, string $value): bool
    {
        // Экранируем значение, т.к. передавать будем GET-ом.
        $value = urlencode($value);
        // Создаём строки присвоение значения каждому хешу.
        $hashes = array_map(fn($hash) => sprintf('%s&s=%s&v=%s', $hash, $property, $value), $hashes);

        // Делим итоговый список на части.
        $hashesChunks = array_chunk($hashes, self::HashesPerRequest);

        $result = true;
        foreach ($hashesChunks as $hashesChunk) {
            $response = $this->sendRequest(action: 'setprops', params: ['hashes' => $hashesChunk]);
            if ($response === false) {
                $result = false;
            }
        }

        return $result;
    }

    /**
     * Изменение параметров торрент-клиента.
     */
    private function setSetting(string $setting, string $value): bool
    {
        return $this->sendRequest(action: 'setsetting', params: ['s' => $setting, 'v' => $value]);
    }
}
