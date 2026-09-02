<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\Clients;

use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\GuzzleException;
use KeepersTeam\Webtlo\Clients\Data\Torrent;
use KeepersTeam\Webtlo\Clients\Data\Torrents;
use KeepersTeam\Webtlo\Config\TorrentClientOptions;
use KeepersTeam\Webtlo\DateHelper;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Class Flood.
 *
 * Supported by flood by jesec API.
 *
 * @see https://flood-api.netlify.app
 */
final class Flood implements ClientInterface
{
    use Traits\AllowedFunctions;
    use Traits\AuthClient;
    use Traits\CheckDomain;
    use Traits\ClientTag;
    use Traits\RetryMiddleware;

    public const ACTION_CHUNK_SIZE = 500;

    /** @var string[] */
    private const trackerErrorStates = [
        '/.*Couldn\'t connect.*/',
        '/.*error.*/',
        '/.*Timeout.*/',
        '/.*missing.*/',
        '/.*unknown.*/',
    ];

    private Client    $client;
    private CookieJar $jar;

    public function __construct(
        private readonly LoggerInterface      $logger,
        private readonly TorrentClientOptions $options,
    ) {
        /** Клиент позволяет присваивать раздаче категорию при добавлении. */
        $this->categoryAddingAllowed = true;

        $this->jar = new CookieJar();

        // Параметры клиента.
        $this->client = new Client([
            'base_uri' => $this->getClientBase($this->options, 'api/'),
            'cookies'  => $this->jar,
            'handler'  => $this->getDefaultHandler(),
            // Timeout options
            ...$this->options->getTimeoutOptions(),
        ]);

        if (!$this->login()) {
            throw new RuntimeException(
                'Не удалось авторизоваться в flood api. Проверьте параметры доступа к клиенту.'
            );
        }
    }

    public function getTorrents(array $filter = []): Torrents
    {
        $response = $this->makeRequest(uri: 'torrents');

        $torrents = [];
        foreach ($response['torrents'] as $torrent) {
            $torrentHash   = strtoupper($torrent['hash']);
            $torrentPaused = in_array('stopped', $torrent['status']);

            [$torrentError, $errorMessage] = self::checkTorrentError(torrent: $torrent);

            $torrents[$torrentHash] = new Torrent(
                topicHash   : $torrentHash,
                clientHash  : $torrentHash,
                name        : (string) $torrent['name'],
                topicId     : $this->getTorrentTopicId(comment: $torrent['comment']),
                size        : (int) $torrent['sizeBytes'],
                added       : DateHelper::makeDateTime(datetime: (int) $torrent['dateAdded']),
                done        : $torrent['percentComplete'] / 100,
                paused      : $torrentPaused,
                error       : (bool) $torrentError,
                trackerError: $errorMessage ?: null,
                comment     : $torrent['comment'] ?: null,
                storagePath : $torrent['directory'] ?? null
            );

            unset($torrent, $torrentHash, $torrentPaused, $torrentError, $errorMessage);
        }

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
        $fields = [
            'files'       => [base64_encode($content)],
            'destination' => $savePath,
            'start'       => true,
        ];
        if (!empty($label)) {
            $fields['tags'] = [$this->prepareLabel(label: $label)];
        }

        return $this->sendRequest(uri: 'torrents/add-files', method: 'POST', params: $fields);
    }

    /**
     * Присвоение пустой метки (удаление метки) - не работает для qBittorrent.
     *
     * @see https://github.com/jesec/flood/issues/605
     */
    public function setLabel(array $torrentHashes, string $label = ''): bool
    {
        $extra = ['tags' => [$this->prepareLabel($label)]];

        return $this->actionTorrents(uri: 'torrents/tags', method: 'PATCH', hashes: $torrentHashes, extra: $extra);
    }

    public function startTorrents(array $torrentHashes, bool $forceStart = false): bool
    {
        return $this->actionTorrents(uri: 'torrents/start', method: 'POST', hashes: $torrentHashes);
    }

    public function stopTorrents(array $torrentHashes): bool
    {
        return $this->actionTorrents(uri: 'torrents/stop', method: 'POST', hashes: $torrentHashes);
    }

    public function removeTorrents(array $torrentHashes, bool $deleteFiles = false): bool
    {
        $extra = ['deleteData' => $deleteFiles ? 'true' : 'false'];

        return $this->actionTorrents(uri: 'torrents/delete', method: 'POST', hashes: $torrentHashes, extra: $extra);
    }

    public function recheckTorrents(array $torrentHashes): bool
    {
        return $this->actionTorrents(uri: 'torrents/check-hash', method: 'POST', hashes: $torrentHashes);
    }

    /**
     * Авторизация в торрент-клиенте.
     */
    private function login(): bool
    {
        if (!$this->authenticated) {
            try {
                if ($this->options->credentials === null) {
                    $this->logger->warning('Не указаны логин и пароль для авторизации в торрент-клиенте.');

                    return false;
                }

                $response = $this->client->post(uri: 'auth/authenticate', options: [
                    'form_params' => [
                        'username' => $this->options->credentials->username,
                        'password' => $this->options->credentials->password,
                    ],
                ]);

                // Проверяем наличие куки авторизации.
                $this->authenticated =
                    $response->getStatusCode() === 200
                    && $this->checkSID();

                return $this->authenticated;
            } catch (ClientException $e) {
                $response = $e->getResponse();

                $statusCode = $response->getStatusCode();
                if ($statusCode == 401) {
                    $this->logger->error('Incorrect login/password', ['response' => $response->getReasonPhrase()]);
                } elseif ($statusCode == 422) {
                    $this->logger->error('Malformed request', ['response' => $response->getReasonPhrase()]);
                } else {
                    $this->logger->error('Failed to authenticate', ['response' => $response->getReasonPhrase()]);
                }
            } catch (Throwable $e) {
                $this->logger->warning(
                    'Failed to authenticate',
                    ['code' => $e->getCode(), 'message' => $e->getMessage()]
                );
            }

            return false;
        }

        return true;
    }

    /**
     * @param literal-string       $uri
     * @param literal-string       $method
     * @param array<string, mixed> $params
     *
     * @throws GuzzleException
     */
    private function request(string $uri, string $method = 'GET', array $params = []): ResponseInterface
    {
        $options = ['json' => $params];
        if ($method === 'GET') {
            $options = [];
        }

        return $this->client->request(method: $method, uri: $uri, options: $options);
    }

    /**
     * @param literal-string       $uri
     * @param literal-string       $method
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    private function makeRequest(string $uri, string $method = 'GET', array $params = []): array
    {
        try {
            $response = $this->request(uri: $uri, method: $method, params: $params);
        } catch (GuzzleException $e) {
            $this->logger->error('Failed to make request', ['error' => $e->getCode(), 'message' => $e->getMessage()]);

            throw new RuntimeException('Failed to make request');
        }

        return json_decode($response->getBody()->getContents(), true);
    }

    /**
     * @param literal-string       $uri
     * @param literal-string       $method
     * @param array<string, mixed> $params
     */
    private function sendRequest(string $uri, string $method = 'GET', array $params = []): bool
    {
        try {
            $response = $this->request(uri: $uri, method: $method, params: $params);

            return $response->getStatusCode() === 200;
        } catch (ClientException $e) {
            $response = $e->getResponse();

            $statusCode = $response->getStatusCode();
            if ($statusCode === 400) {
                $this->logger->error('Malformed request', ['code' => $statusCode]);
            }
            if ($statusCode === 403) {
                $this->logger->error('Invalid destination', ['code' => $statusCode]);
            }
            if ($statusCode === 500) {
                $this->logger->error('Malformed request', ['code' => $statusCode]);
            } else {
                $this->logger->error($response->getReasonPhrase(), ['code' => $statusCode]);
            }
        } catch (Throwable $e) {
            $this->logger->warning('Failed to send request', ['code' => $e->getCode(), 'message' => $e->getMessage()]);
        }

        return false;
    }

    /**
     * Выполняет массовое действие над торрентами с разбивкой.
     *
     * @param literal-string       $uri
     * @param literal-string       $method
     * @param string[]             $hashes
     * @param array<string, mixed> $extra  Дополнительные поля, которые будут добавлены в тело запроса
     *
     * @return bool true, если все части успешно обработаны, иначе false
     */
    private function actionTorrents(string $uri, string $method, array $hashes, array $extra = []): bool
    {
        if ($hashes === []) {
            return true;
        }

        $result = true;
        foreach (array_chunk($hashes, self::ACTION_CHUNK_SIZE) as $chunk) {
            $response = $this->sendRequest(
                uri   : $uri,
                method: $method,
                params: ['hashes' => $chunk, ...$extra]
            );
            if ($response === false) {
                $result = false;
            }
        }

        return $result;
    }

    /**
     * Проверяем наличие куки авторизации.
     */
    private function checkSID(): bool
    {
        $sid = $this->jar->getCookieByName('jwt');
        if ($sid !== null) {
            $this->logger->debug('Got flood auth token', $sid->toArray());

            return true;
        }

        return false;
    }

    /**
     * Проверить наличие ошибки в статусе торрента.
     *
     * @param array<string, mixed> $torrent
     *
     * @return array{int, string}
     */
    private static function checkTorrentError(array $torrent): array
    {
        if (in_array('error', $torrent['status'])) {
            return [1, $torrent['message'] ?: 'torrent status error'];
        }

        foreach (self::trackerErrorStates as $pattern) {
            if (preg_match($pattern, $torrent['message'])) {
                return [1, $torrent['message']];
            }
        }

        return [0, ''];
    }

    private function prepareLabel(string $label): string
    {
        return str_replace([',', '/', '\\'], '', $label);
    }
}
