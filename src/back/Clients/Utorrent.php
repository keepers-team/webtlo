<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\Clients;

use Generator;
use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\GuzzleException;
use KeepersTeam\Webtlo\Clients\Data\Torrent;
use KeepersTeam\Webtlo\Clients\Data\Torrents;
use KeepersTeam\Webtlo\Config\TorrentClientOptions;
use KeepersTeam\Webtlo\DateHelper;
use KeepersTeam\Webtlo\Storage\Table\Topics as TableTopics;
use KeepersTeam\Webtlo\Storage\Table\Torrents as TableTorrents;
use KeepersTeam\Webtlo\Timers;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Class Utorrent.
 *
 * Supported by uTorrent 1.8.2 and later.
 *
 * @see https://forum.utorrent.com/topic/21814-web-ui-api/
 */
final class Utorrent implements ClientInterface
{
    use Traits\AllowedFunctions;
    use Traits\AuthClient;
    use Traits\CheckDomain;
    use Traits\ClientTag;
    use Traits\RetryMiddleware;
    use Traits\TopicIdSearch;

    private const HashesPerRequest = 32;

    private const ACTION_CHUNK_SIZE = 100;

    private string $token;

    private Client    $client;
    private CookieJar $jar;

    public function __construct(
        private readonly LoggerInterface      $logger,
        private readonly TorrentClientOptions $options,
        private readonly TableTopics          $tableTopics,
        private readonly TableTorrents        $tableTorrents,
    ) {
        // Авторизация через Set-Cookie для 3.*
        $this->jar = new CookieJar();

        // Параметры клиента.
        $clientOptions = [
            'base_uri' => $this->getClientBase($this->options, 'gui/'),
            'cookies'  => $this->jar,
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

    public function getTorrents(array $filter = []): Torrents
    {
        /** Получить просто список раздач без дополнительных действий */
        $simpleRun = (bool) ($filter['simple'] ?? 0);

        $generator = $this->generateTorrentsList(simpleRun: $simpleRun);

        $torrents = [];
        foreach ($generator as $hash => $payload) {
            $hash = (string) $hash;

            $torrents[$hash] = new Torrent(
                topicHash   : $hash,
                clientHash  : $hash,
                name        : (string) $payload['name'],
                topicId     : $payload['topic_id'] ?: null,
                size        : (int) $payload['total_size'],
                added       : DateHelper::makeDateTime(datetime: (int) $payload['time_added']),
                done        : $payload['done'],
                paused      : (bool) $payload['paused'],
                error       : (bool) $payload['error'],
                trackerError: $payload['tracker_error'] ?: null,
                comment     : $payload['comment'] ?: null,
                storagePath : null
            );
        }

        $this->logger->debug('Done processing', Timers::getStash());

        return new Torrents(torrents: $torrents);
    }

    public function addTorrent(string $torrentFilePath, string $savePath = '', string $label = ''): bool
    {
        $content = file_get_contents($torrentFilePath);
        if ($content === false) {
            $this->logger->error('Failed to upload file', ['filename' => basename($torrentFilePath)]);

            return false;
        }

        return $this->addTorrentContent(content: $content, savePath: $savePath, label: $label);
    }

    public function addTorrentContent(string $content, string $savePath = '', string $label = ''): bool
    {
        $this->setSetting(setting: 'dir_active_download_flag', value: '1');
        if (!empty($savePath)) {
            $this->setSetting(setting: 'dir_active_download', value: $savePath);
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
            $response = $this->client->post(uri: '', options: ['query' => $query, 'multipart' => $fields]);

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
        return $this->setProperties(hashes: $torrentHashes, property: 'label', value: $label);
    }

    public function startTorrents(array $torrentHashes, bool $forceStart = false): bool
    {
        $action = $forceStart ? 'forcestart' : 'start';

        return $this->actionTorrents(action: $action, hashes: $torrentHashes);
    }

    public function stopTorrents(array $torrentHashes): bool
    {
        return $this->actionTorrents(action: 'stop', hashes: $torrentHashes);
    }

    public function recheckTorrents(array $torrentHashes): bool
    {
        // uTorrent может перехешировать только остановленные раздачи.
        if ($this->stopTorrents(torrentHashes: $torrentHashes)) {
            return $this->actionTorrents(action: 'recheck', hashes: $torrentHashes);
        }

        return false;
    }

    public function removeTorrents(array $torrentHashes, bool $deleteFiles = false): bool
    {
        $action = $deleteFiles ? 'removedata' : 'remove';

        return $this->actionTorrents(action: $action, hashes: $torrentHashes);
    }

    /**
     * Авторизация в торрент-клиенте.
     */
    private function login(): bool
    {
        if (!$this->authenticated) {
            try {
                $response = $this->client->post(uri: 'token.html');

                $html = $response->getBody()->getContents();

                // Проверяем токен авторизации для версий 1.* и 2.*
                preg_match('|<div id=\'token\'.+>(.*)</div>|', $html, $tokenMatches);
                if (!empty($tokenMatches)) {
                    $this->token = $tokenMatches[1];

                    $this->authenticated = true;
                }

                return $this->authenticated;
            } catch (ClientException $e) {
                $response = $e->getResponse();

                if ($response->getStatusCode() === 401) {
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
     * @param literal-string       $action
     * @param array<string, mixed> $params
     * @param literal-string       $method
     *
     * @throws GuzzleException
     */
    private function request(string $action, array $params = [], string $method = 'action'): ResponseInterface
    {
        // Если передан список хешей, то пересобираем его в строку с повторением ключа
        // hash=hash1&hash=hash2...
        $hashes = [];
        if (!empty($params['hashes'])) {
            $hashes = array_map(static fn($el) => "hash=$el", $params['hashes']);
        }
        unset($params['hashes']);

        $props = $this->buildHttpQuery(method: $method, action: $action, params: $params);
        $query = implode('&', [$props, ...$hashes]);

        return $this->client->get(uri: '', options: ['query' => $query]);
    }

    /**
     * @param literal-string       $action
     * @param array<string, mixed> $params
     * @param literal-string       $method
     *
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

        return $this->validateResponse(response: $response);
    }

    /**
     * @param literal-string       $action
     * @param array<string, mixed> $params
     * @param literal-string       $method
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
     * Выполняет массовое действие над торрентами с разбивкой.
     *
     * @param literal-string       $action
     * @param string[]             $hashes
     * @param array<string, mixed> $extra
     *
     * @return bool true, если все части успешно обработаны, иначе false
     */
    private function actionTorrents(string $action, array $hashes, array $extra = []): bool
    {
        if ($hashes === []) {
            return true;
        }

        $result = true;
        foreach (array_chunk($hashes, self::ACTION_CHUNK_SIZE) as $chunk) {
            $response = $this->sendRequest(
                action: $action,
                params: ['hashes' => $chunk, ...$extra]
            );
            if ($response === false) {
                $result = false;
            }
        }

        return $result;
    }

    /**
     * Кодируем выполняемое действие + токен авторизации.
     *
     * @param literal-string       $method
     * @param literal-string       $action
     * @param array<string, mixed> $params
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
     * @return array<string, mixed>
     */
    private function validateResponse(ResponseInterface $response): array
    {
        $body  = $response->getBody()->getContents();
        $array = json_decode(json: $body, associative: true, flags: JSON_INVALID_UTF8_SUBSTITUTE);

        if ($array === null) {
            $this->logger->error('Fail to decode api response', [$body]);

            throw new RuntimeException('Unsuccessful api request');
        }

        return $array;
    }

    private function generateTorrentsList(bool $simpleRun): Generator
    {
        // Получаем и обрабатываем список раздач от клиента.
        $torrents = $this->processTorrents(
            clientTorrents: $this->requestTorrents()
        );

        if (!$simpleRun) {
            // Попытка найти ид раздачи в локальных таблицах.
            $this->tryFillTopicIdFromTopics(torrents: $torrents);
            $this->tryFillTopicIdFromTorrents(torrents: $torrents);
        }

        foreach ($torrents as $hash => $torrent) {
            yield $hash => $torrent;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function processTorrents(Generator $clientTorrents): array
    {
        $torrents = $brokenFiles = [];
        Timers::start('processing');
        foreach ($clientTorrents as $torrent) {
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
            $torrentState  = decbin((int) $torrent[1]);
            $torrentName   = (string) $torrent[2];
            $torrentHash   = strtoupper((string) $torrent[0]);
            $torrentPaused = $torrentState[2] || !$torrentState[7];

            // Проверим имя раздачи на наличие битых символов.
            if (str_contains($torrentName, '�')) {
                $brokenFiles[] = ['hash' => $torrentHash, 'name' => $torrentName];
            }

            $torrents[$torrentHash] = [
                'topic_id'      => null,
                'comment'       => null,
                'done'          => (int) $torrent[4] / 1000,
                'error'         => (bool) $torrentState[3],
                'name'          => $torrentName,
                'paused'        => $torrentPaused,
                'time_added'    => null,
                'total_size'    => (int) $torrent[3],
                'tracker_error' => null,
            ];
        }
        Timers::stash('processing');

        // Выведем список битых раздач в лог.
        if (count($brokenFiles)) {
            $this->logger->debug('Broken encoding detected.', $brokenFiles);
        }

        return $torrents;
    }

    private function requestTorrents(): Generator
    {
        Timers::start('torrents_info');
        $response = $this->makeRequest(action: '1', method: 'list');
        Timers::stash('torrents_info');

        foreach ($response['torrents'] as $torrent) {
            yield $torrent;
        }
    }

    /**
     * Изменение параметров торрента.
     *
     * @param string[] $hashes
     */
    private function setProperties(array $hashes, string $property, string $value): bool
    {
        // Экранируем значение, т.к. передавать будем GET-ом.
        $value = urlencode($value);
        // Создаём строки присвоение значения каждому хешу.
        $hashes = array_map(static fn($hash) => sprintf('%s&s=%s&v=%s', $hash, $property, $value), $hashes);

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
