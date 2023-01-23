<?php

namespace KeepersTeam\Webtlo\Forum;

use GuzzleHttp\Exception\GuzzleException;
use KeepersTeam\Webtlo\Config\Defaults;
use KeepersTeam\Webtlo\Config\Proxy;
use KeepersTeam\Webtlo\Config\Timeout;
use Psr\Http\Message\StreamInterface;
use Psr\Log\LoggerInterface;

class TorrentDownloader extends WebClient
{
    private static string $url = '/forum/dl.php';
    private readonly array $defaultParams;

    public function __construct(
        string $apiKey,
        string $userID,
        bool $addRetracker,
        LoggerInterface $logger,
        ?Proxy $proxy = null,
        string $forumURL = Defaults::forumUrl,
        Timeout $timeout = new Timeout(),
    ) {
        parent::__construct(
            logger: $logger,
            baseURL: $forumURL,
            proxy: $proxy,
            timeout: $timeout
        );
        $this->defaultParams = [
            'keeper_user_id' => $userID,
            'keeper_api_key' => $apiKey,
            'add_retracker_url' => $addRetracker ? 1 : 0,
        ];
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

        if ($this->isValidMime($response, self::torrentMime)) {
            return $response->getBody();
        } else {
            return false;
        }
    }
}
