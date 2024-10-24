<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\Clients;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleRetry\GuzzleRetryMiddleware;
use KeepersTeam\Webtlo\Clients\Data\Torrent;
use KeepersTeam\Webtlo\Clients\Data\Torrents;
use KeepersTeam\Webtlo\Config\TorrentClientOptions;
use KeepersTeam\Webtlo\Helper;
use KeepersTeam\Webtlo\Timers;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Class Transmission
 * Supported by Transmission 2.80 and later
 * https://github.com/transmission/transmission/blob/main/docs/rpc-spec.md
 */
final class Transmission implements ClientInterface
{
    use Traits\AllowedFunctions;
    use Traits\AuthClient;
    use Traits\CheckDomain;
    use Traits\RetryMiddleware;

    private const TOKEN = 'X-Transmission-Session-Id';

    private bool $authenticated = false;

    /**
     * Заголовки для хранения ключа авторизации.
     *
     * @var array<string, string>
     */
    private array $headers = [];

    /** Позволяет ли клиент присваивать раздаче категорию при добавлении. */
    protected bool $categoryAddingAllowed = true;

    private int $rpcVersion = 0;

    private Client $client;

    public function __construct(
        private readonly LoggerInterface      $logger,
        private readonly TorrentClientOptions $options
    ) {
        // Обработчик для получения токена авторизации.
        $authMiddleware = GuzzleRetryMiddleware::factory([
            'max_retry_attempts' => 1,
            'on_retry_callback'  => $this->getLoginHandler($this->headers),
            'retry_on_status'    => [405, 409],
        ]);

        $stack = $this->getDefaultHandler($authMiddleware);

        // Параметры клиента.
        $clientOptions = [
            'base_uri' => $this->getClientBase($this->options, 'transmission/rpc'),
            'handler'  => $stack,
            // Timeout options
            ...$this->options->getTimeoutOptions(),
            // Auth options
            ...$this->options->getBasicAuth(),
        ];

        $this->client = new Client($clientOptions);

        if (!$this->login()) {
            throw new RuntimeException(
                'Не удалось авторизоваться в transmission api. Проверьте параметры доступа к клиенту.'
            );
        }
    }

    public function getTorrents(array $filter = []): Torrents
    {
        $fields = [
            'fields' => [
                'hashString',
                'name',
                'addedDate',
                'comment',
                'status',
                'totalSize',
                'files',
                'percentDone',
                'downloadDir',
                'error',
                'errorString',
            ],
        ];
        Timers::start('torrents_info');
        $response = $this->makeRequest('torrent-get', $fields);
        Timers::stash('torrents_info');

        $torrents = [];
        Timers::start('processing');
        foreach ($response['torrents'] as $torrent) {
            $torrentHash  = strtoupper($torrent['hashString']);
            $trackerError = (int) $torrent['error'] === 2 ? $torrent['errorString'] : null;

            $progress = $torrent['percentDone'];
            // Если торрент скачан полностью, проверив выбраны ли все файлы раздачи.
            if (1 === (int) $progress && count($torrent['files']) > 1) {
                $progress = array_sum(array_column($torrent['files'], 'bytesCompleted')) / (int) $torrent['totalSize'];
            }

            $torrents[$torrentHash] = new Torrent(
                topicHash   : $torrentHash,
                clientHash  : $torrentHash,
                name        : (string) $torrent['name'],
                topicId     : $this->getTorrentTopicId($torrent['comment']),
                size        : (int) $torrent['totalSize'],
                added       : Helper::makeDateTime((int) $torrent['addedDate']),
                done        : $progress,
                paused      : (int) $torrent['status'] === 0,
                error       : (int) $torrent['error'] !== 0,
                trackerError: $trackerError,
                comment     : $torrent['comment'] ?: null,
                storagePath : $torrent['downloadDir'] ?? null
            );

            unset($torrent, $torrentHash, $trackerError, $progress);
        }
        Timers::stash('processing');

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
            'metainfo' => base64_encode($content),
            'paused'   => false,
        ];
        if (!empty($savePath)) {
            $fields['download-dir'] = $savePath;
        }
        if (!empty($label)) {
            $fields['labels'] = [$this->prepareLabel($label)];
        }

        $result = $this->makeRequest('torrent-add', $fields);
        if (!empty($result['torrent-duplicate'])) {
            $torrentHash = $result['torrent-duplicate']['hashString'];
            $this->logger->notice('This torrent already added', ['hash' => $torrentHash]);
        }

        return true;
    }

    public function setLabel(array $torrentHashes, string $label = ''): bool
    {
        if ($this->rpcVersion < 16) {
            $this->logger->warning(
                'Labels are not supported in rpc version of this client',
                ['rpc_version' => $this->rpcVersion]
            );

            return false;
        }

        $fields = [
            'labels' => [$this->prepareLabel($label)],
            'ids'    => $torrentHashes,
        ];

        return $this->sendRequest('torrent-set', $fields);
    }

    public function startTorrents(array $torrentHashes, bool $forceStart = false): bool
    {
        $method = $forceStart ? 'torrent-start-now' : 'torrent-start';
        $fields = [
            'ids' => $torrentHashes,
        ];

        return $this->sendRequest($method, $fields);
    }

    public function stopTorrents(array $torrentHashes): bool
    {
        $fields = [
            'ids' => $torrentHashes,
        ];

        return $this->sendRequest('torrent-stop', $fields);
    }

    public function recheckTorrents(array $torrentHashes): bool
    {
        $fields = [
            'ids' => $torrentHashes,
        ];

        return $this->sendRequest('torrent-verify', $fields);
    }

    public function removeTorrents(array $torrentHashes, bool $deleteFiles = false): bool
    {
        $fields = [
            'ids'               => $torrentHashes,
            'delete-local-data' => $deleteFiles,
        ];

        return $this->sendRequest('torrent-remove', $fields);
    }

    /**
     * Авторизация в торрент-клиенте.
     */
    private function login(): bool
    {
        if (!$this->authenticated) {
            try {
                $response = $this->request('session-get');
                $result   = $this->validateResponse($response);

                // Если получили ответ, значит авторизация успешна.
                if (!empty($result['rpc-version'])) {
                    $this->rpcVersion    = $result['rpc-version'];
                    $this->authenticated = true;
                }

                // Записываем токен авторизации (на версии 2.94 его нет, должен быть записан автоматически в getLoginHandler).
                if (!empty($result['session-id'])) {
                    $this->headers[self::TOKEN] = $result['session-id'];
                }

                return $this->authenticated;
            } catch (ClientException $e) {
                $response = $e->getResponse();

                $statusCode = $response->getStatusCode();
                if (401 === $statusCode) {
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
     * @param string               $method
     * @param array<string, mixed> $options
     * @return ResponseInterface
     * @throws GuzzleException
     */
    private function request(string $method, array $options = []): ResponseInterface
    {
        $params = [
            'method'    => $method,
            'arguments' => $options,
        ];

        $options = [
            'headers' => $this->headers,
            'body'    => json_encode($params),
        ];

        return $this->client->post('', $options);
    }

    /**
     * @param string               $method
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function makeRequest(string $method, array $params = []): array
    {
        try {
            $response = $this->request($method, $params);
        } catch (GuzzleException $e) {
            $this->logger->error('Failed to make request', ['code' => $e->getCode(), 'message' => $e->getMessage()]);
            throw new RuntimeException('Failed to make request');
        }

        return $this->validateResponse($response);
    }

    /**
     * @param string               $method
     * @param array<string, mixed> $params
     * @return bool
     */
    private function sendRequest(string $method, array $params = []): bool
    {
        try {
            $response = $this->request($method, $params);

            return $response->getStatusCode() === 200;
        } catch (Throwable $e) {
            $this->logger->warning('Failed to send request', ['code' => $e->getCode(), 'message' => $e->getMessage()]);
        }

        return false;
    }

    /**
     * @param ResponseInterface $response
     * @return array<string, mixed>
     */
    private function validateResponse(ResponseInterface $response): array
    {
        $body  = $response->getBody()->getContents();
        $array = json_decode($body, true);

        if (null === $array) {
            $this->logger->error('Fail to decode api response', [$body]);

            throw new RuntimeException('Unsuccessful api request');
        }

        if ('success' !== $array['result']) {
            $this->logger->error('Unsuccessful api request', $array);
            throw new RuntimeException('Unsuccessful api request');
        }

        return (array) $array['arguments'];
    }

    /**
     * @param array<string, mixed> $headers
     * @return callable
     */
    private function getLoginHandler(array &$headers): callable
    {
        $logger    = $this->logger;
        $tokenName = self::TOKEN;

        return static function(
            int               $attempt,
            float             $delay,
            RequestInterface  &$request,
            array             $options,
            ResponseInterface $response,
        ) use ($logger, &$headers, $tokenName): void {
            if ($response->hasHeader($tokenName)) {
                $sid = $response->getHeaderLine($tokenName);

                // Дописываем токен авторизации в текущий запрос.
                $request = $request->withAddedHeader($tokenName, $sid);

                // Записываем токен авторизации в заголовки.
                $headers[$tokenName] = $sid;
                $logger->debug('Got transmission auth token', [$sid]);
            }
        };
    }

    private function prepareLabel(string $label): string
    {
        return (string) str_replace(',', '', $label);
    }
}
