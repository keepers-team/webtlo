<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\Clients;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use KeepersTeam\Webtlo\Clients\Data\Torrents;
use KeepersTeam\Webtlo\Config\TorrentClientOptions;
use KeepersTeam\Webtlo\Clients\Data\Torrent;
use KeepersTeam\Webtlo\Helper;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Class Rtorrent
 * Supported by rTorrent 0.9.7 and later
 * https://rtorrent-docs.readthedocs.io/en/latest/scripting.html
 * https://php.watch/versions/8.0/xmlrpc#alternatives
 * https://github.com/gggeek/polyfill-xmlrpc
 */
final class Rtorrent implements ClientInterface
{
    use Traits\AuthClient;
    use Traits\AllowedFunctions;
    use Traits\CheckDomain;
    use Traits\RetryMiddleware;

    private const MultiCallCount = 32;

    /** Позволяет ли клиент присваивать раздаче категорию при добавлении. */
    protected bool $categoryAddingAllowed = true;

    private Client $client;

    public function __construct(
        private readonly LoggerInterface      $logger,
        private readonly TorrentClientOptions $options
    ) {
        // Параметры клиента.
        $clientOptions = [
            'base_uri' => $this->getClientBase($this->options, 'RPC2'),
            'handler'  => $this->getDefaultHandler(),
            'headers'  => [
                'Content-Type' => 'text/xml',
            ],
            // Timeout options
            ...$this->options->getTimeoutOptions(),
            // Auth options
            ...$this->options->getBasicAuth(),
        ];

        $this->client = new Client($clientOptions);

        if (!$this->login()) {
            throw new RuntimeException(
                'Не удалось авторизоваться в rtorrent api. Проверьте параметры доступа к клиенту.'
            );
        }
    }

    public function getTorrents(array $filter = []): Torrents
    {
        $response = $this->makeRequest(
            'd.multicall2',
            [
                '',
                'main',
                'd.hash=',              // [0] хеш раздачи
                'd.complete=',          // [1] скачивание завершено
                'd.custom2=',           // [2] комментарий
                'd.message=',           // [3] сообщение трекера
                'd.name=',              // [4] название
                'd.size_bytes=',        // [5] размер раздачи
                'd.state=',             // [6] состояние раздачи, 0 - остановлена
                'd.timestamp.started=', // [7] добавление раздачи в клиент
                'd.left_bytes=',        // [8] байт не скачано
                'd.is_multi_file=',     // [9] несколько файлов в раздаче
                'd.base_filename=',     // [10] название файла или каталога
                'd.directory=',         // [11] полный путь хранения раздачи
            ]
        );

        $torrents = [];
        foreach ($response as $torrent) {
            $torrentHash    = strtoupper((string) $torrent[0]);
            $torrentComment = str_replace('VRS24mrker', '', rawurldecode((string) $torrent[2]));

            $trackerError = null;
            preg_match('/Tracker: \[([^"]*"*([^"]*)"*)]/', (string) $torrent[3], $matches);
            if (!empty($matches)) {
                $trackerError = empty($matches[2]) ? $matches[1] : $matches[2];
            }

            // Если "осталось скачать" == 0, то выводим статус.
            // Если "осталось скачать" != 0, то делим остаток на общий размер.
            $progress = $torrent[8] === 0 ? $torrent[1] : ($torrent[5] - $torrent[8]) / $torrent[5];

            // Если файл один, то просто путь. Если файлов несколько, то dirname.
            $storagePath = !$torrent[9] ? $torrent[11] : dirname($torrent[11]);

            $torrents[$torrentHash] = new Torrent(
                topicHash   : $torrentHash,
                clientHash  : $torrentHash,
                name        : (string) $torrent[4],
                topicId     : $this->getTorrentTopicId($torrentComment),
                size        : (int) $torrent[5],
                added       : Helper::makeDateTime((int) $torrent[7]),
                done        : $progress,
                paused      : !$torrent[6],
                error       : !empty($torrent[3]),
                trackerError: $trackerError ?: null,
                comment     : $torrentComment ?: null,
                storagePath : $storagePath ?? null
            );

            unset($torrent, $torrentHash, $torrentComment, $trackerError);
            unset($progress, $storagePath);
        }

        return new Torrents($torrents);
    }

    public function addTorrent(string $torrentFilePath, string $savePath = '', string $label = ''): bool
    {
        $contents = file_get_contents($torrentFilePath, false, stream_context_create());
        if ($contents === false) {
            $this->logger->error('Failed to upload file', ['filename' => basename($torrentFilePath)]);

            return false;
        }

        return $this->addTorrentContent($contents, $savePath, $label);
    }

    public function addTorrentContent(string $content, string $savePath = '', string $label = ''): bool
    {
        $makeDirectory = ['', 'mkdir', '-p', '--', $savePath];
        if (empty($savePath)) {
            $savePath      = '$directory.default=';
            $makeDirectory = ['', 'true'];
        }

        $torrentComment = 'VRS24mrker';
        preg_match('|publisher-url[0-9]*:(https?://[^?]*\?t=[0-9]*)|', $content, $matches);
        if (isset($matches[1])) {
            $torrentComment .= rawurlencode($matches[1]);
        }

        xmlrpc_set_type($content, 'base64');

        $callsChain = [
            [
                'methodName' => 'execute2',
                'params'     => $makeDirectory,
            ],
            [
                'methodName' => 'load.raw_start',
                'params'     => [
                    '',
                    $content,
                    'd.delete_tied=',
                    'd.directory.set=' . addcslashes($savePath, ' '),
                    'd.custom1.set=' . rawurlencode($label),
                    'd.custom2.set=' . $torrentComment,
                ],
            ],
        ];

        return $this->makeMultiCall($callsChain);
    }

    public function setLabel(array $torrentHashes, string $label = ''): bool
    {
        $calls = $this->prepareHashesCalls('d.custom1.set', $torrentHashes, [$label]);

        return $this->actionTorrents($calls);
    }

    public function startTorrents(array $torrentHashes, bool $forceStart = false): bool
    {
        $calls = $this->prepareHashesCalls('d.start', $torrentHashes);

        return $this->actionTorrents($calls);
    }

    public function stopTorrents(array $torrentHashes): bool
    {
        $calls = $this->prepareHashesCalls('d.stop', $torrentHashes);

        return $this->actionTorrents($calls);
    }

    public function removeTorrents(array $torrentHashes, bool $deleteFiles = false): bool
    {
        $result = true;
        foreach ($torrentHashes as $torrentHash) {
            $executeDeleteFiles = ['', 'true'];
            if ($deleteFiles) {
                $dataPath = $this->makeRequest('d.data_path', [$torrentHash]);
                if (!empty($dataPath)) {
                    $executeDeleteFiles = ['', 'rm', '-rf', '--', $dataPath];
                }
            }

            $chainCalls = [
                [
                    'methodName' => 'd.custom5.set',
                    'params'     => [$torrentHash, '1'],
                ],
                [
                    'methodName' => 'd.delete_tied',
                    'params'     => [$torrentHash],
                ],
                [
                    'methodName' => 'd.erase',
                    'params'     => [$torrentHash],
                ],
                [
                    'methodName' => 'execute2',
                    'params'     => $executeDeleteFiles,
                ],
            ];

            $response = $this->makeMultiCall($chainCalls);
            if ($response === false) {
                $result = false;
            }
        }

        return $result;
    }

    public function recheckTorrents(array $torrentHashes): bool
    {
        $calls = $this->prepareHashesCalls('d.check_hash', $torrentHashes);

        return $this->actionTorrents($calls);
    }

    /**
     * Авторизация в торрент-клиенте.
     */
    private function login(): bool
    {
        if (!$this->authenticated) {
            try {
                $xml = $this->xmpRequestEncode('session.name');

                $response = $this->client->post('', ['body' => $xml]);

                $result = $this->xmlResponseDecode($response->getBody()->getContents());

                // Проверяем статус авторизации.
                $this->authenticated =
                    200 === $response->getStatusCode()
                    && !empty($result);

                return $this->authenticated;
            } catch (GuzzleException $e) {
                if ($e->getCode() === 401) {
                    $this->logger->error(
                        'Failed to authenticate',
                        ['code' => $e->getCode(), 'message' => $e->getMessage()]
                    );
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
     * @param string            $command
     * @param array<int, mixed> $params
     * @return ResponseInterface
     * @throws GuzzleException
     */
    private function request(string $command, array $params = []): ResponseInterface
    {
        $options = ['body' => $this->xmpRequestEncode($command, $params)];

        return $this->client->post(uri: '', options: $options);
    }

    /**
     * @param string            $command
     * @param array<int, mixed> $params
     * @return array<int, mixed>
     */
    private function makeRequest(string $command, array $params = []): array
    {
        try {
            $response = $this->request(command: $command, params: $params);
        } catch (GuzzleException $e) {
            $this->logger->error('Failed to make request', ['error' => $e->getCode(), 'message' => $e->getMessage()]);

            throw new RuntimeException('Failed to make request');
        }

        $content = $response->getBody()->getContents();

        return (array) $this->xmlResponseDecode($content);
    }

    /**
     * @param string            $command
     * @param array<int, mixed> $params
     * @return bool
     */
    private function sendRequest(string $command, array $params = []): bool
    {
        try {
            $response = $this->request(command: $command, params: $params);

            if ($response->getStatusCode() !== 200) {
                return false;
            }

            $content = $response->getBody()->getContents();
            $result  = $this->xmlResponseDecode($content);

            return !empty($result);
        } catch (Throwable $e) {
            $this->logger->warning('Failed to send request', ['code' => $e->getCode(), 'message' => $e->getMessage()]);
        }

        return false;
    }

    /**
     * Высов списка команд по очереди.
     *
     * @param array<string, mixed>[] $callsChain
     * @return bool
     */
    private function makeMultiCall(array $callsChain): bool
    {
        return $this->sendRequest('system.multicall', [$callsChain]);
    }

    /**
     * @param string            $method
     * @param string[]          $torrentHashes
     * @param array<int, mixed> $params
     * @return array<array<string, mixed>>
     */
    private function prepareHashesCalls(string $method, array $torrentHashes, array $params = []): array
    {
        $callback = fn($hash) => ['methodName' => $method, 'params' => [$hash, ...$params]];

        return array_map($callback, $torrentHashes);
    }

    /**
     * @param array<array<string, mixed>> $calls
     * @return bool
     */
    private function actionTorrents(array $calls): bool
    {
        // Разделяем необходимые запросы на группы.
        $callsChunks = array_chunk(
            $calls,
            self::MultiCallCount
        );

        $result = true;
        foreach ($callsChunks as $callsChain) {
            $response = $this->makeMultiCall($callsChain);
            if ($response === false) {
                $result = false;
            }
        }

        return $result;
    }

    /**
     * @param string            $command
     * @param array<int, mixed> $params
     * @return string
     */
    private function xmpRequestEncode(string $command, array $params = []): string
    {
        return xmlrpc_encode_request($command, $params, ['escaping' => 'markup', 'encoding' => 'UTF-8']);
    }

    private function xmlResponseDecode(string $response): mixed
    {
        return xmlrpc_decode(str_replace('i8>', 'i4>', $response), 'UTF-8');
    }
}
