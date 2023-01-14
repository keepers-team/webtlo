<?php

namespace KeepersTeam\Webtlo\Forum;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Header;
use GuzzleRetry\GuzzleRetryMiddleware;
use KeepersTeam\Webtlo\Config\Defaults;
use KeepersTeam\Webtlo\Config\Timeout;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Log\LoggerInterface;

class TorrentDownloader
{
    private readonly Client $client;
    private static string $torrentMime = 'application/x-bittorrent';
    private static string $url = '/forum/dl.php';
    private readonly array $defaultParams;

    // FIXME add proxy support
    public function __construct(
        private readonly LoggerInterface $logger,
        string $apiKey,
        string $userID,
        bool $addRetracker,
        string $forumURL = Defaults::forumUrl,
        Timeout $timeout = new Timeout(),
    ) {
        $this->defaultParams = [
            'keeper_user_id' => $userID,
            'keeper_api_key' => $apiKey,
            'add_retracker_url' => $addRetracker ? 1 : 0,
        ];

        $retryCallback = function (int $attemptNumber, float $delay, RequestInterface &$request, array &$options, ?ResponseInterface $response) use ($logger): void {
            $logger->warning(
                'Retrying request',
                [
                    'url' => $request->getUri()->__toString(),
                    'delay' => number_format($delay, 2),
                    'attempt' => $attemptNumber
                ]
            );
        };
        $retryOptions = [
            'max_retry_attempts' => 3,
            'retry_on_timeout' => true,
            'on_retry_callback' => $retryCallback,
        ];

        $headers = [
            'User-Agent' => Defaults::userAgent,
            'Accept' => self::$torrentMime,
            'X-WebTLO' => 'experimental'
        ];

        $stack = HandlerStack::create();
        $stack->push(GuzzleRetryMiddleware::factory($retryOptions));
        $baseUrl = sprintf("https://%s%s", $forumURL, self::$url);
        $this->client = new Client([
            'base_uri' => $baseUrl,
            'timeout' => $timeout->request,
            'connect_timeout' => $timeout->connection,
            'allow_redirects' => true,
            'headers' => $headers,
            'handler' => $stack
        ]);
        $logger->info('Created downloader', ['base' => $baseUrl]);
    }

    /**
     * Check response for correctness
     *
     * @param ResponseInterface $response
     * @return bool
     */
    private function isValid(ResponseInterface &$response): bool
    {
        $type = $response->getHeader('content-type');
        if (empty($type)) {
            $this->logger->warning('No content-type found');
            return false;
        }
        $parsed = Header::parse($type);
        if (!isset($parsed[0][0])) {
            $this->logger->warning('Broken content-type header');
            return false;
        }
        $receivedMime = $parsed[0][0];

        if ($receivedMime !== self::$torrentMime) {
            $this->logger->warning('Unknown mime', ['expected' => self::$torrentMime, 'received' => $receivedMime]);
            return false;
        }
        return true;
    }

    /**
     * Download torrent file
     *
     * @param string $infoHash Info hash for torrent
     * @return StreamInterface|false Stream with torrent body
     */
    public function download(string $infoHash): StreamInterface|false
    {
        $options = [
            'form_params' => [...$this->defaultParams, 'h' => $infoHash]
        ];
        try {
            $this->logger->info('Downloading torrent', ['hash' => $infoHash]);
            $response = $this->client->post(self::$url, $options);
        } catch (GuzzleException $e) {
            $this->logger->error('Failed to download torrent', ['hash' => $infoHash, 'error' => $e]);
            return false;
        }

        if ($this->isValid($response)) {
            return $response->getBody();
        } else {
            return false;
        }
    }
}
