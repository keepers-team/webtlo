<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\External\Forum;

use GuzzleHttp\Exception\GuzzleException;
use KeepersTeam\Webtlo\Config\ApiCredentials;
use Psr\Http\Message\StreamInterface;

trait TorrentDownload
{
    private ApiCredentials $apiCredentials;

    public function setApiCredentials(ApiCredentials $apiCredentials): void
    {
        $apiCredentials->validate();

        $this->apiCredentials = $apiCredentials;
    }

    /**
     * Download torrent file.
     *
     * @param string $infoHash Info hash for torrent
     *
     * @return ?StreamInterface Stream with torrent body
     */
    public function downloadTorrent(string $infoHash, bool $addRetracker = false): ?StreamInterface
    {
        if (!isset($this->apiCredentials)) {
            $this->logger->warning('Загрузка торрент-файла невозможна. Отсутствуют ключи доступа к API.');

            return null;
        }

        $options = [
            'form_params' => [
                'keeper_user_id'    => $this->apiCredentials->userId,
                'keeper_api_key'    => $this->apiCredentials->apiKey,
                'add_retracker_url' => $addRetracker ? 1 : 0,
                'h'                 => $infoHash,
            ],
        ];

        try {
            $this->logger->debug('Downloading torrent', ['hash' => $infoHash]);
            $response = $this->client->post(self::torrentUrl, $options);
        } catch (GuzzleException $e) {
            $this->logger->error('Failed to download torrent', ['hash' => $infoHash, 'error' => $e]);

            return null;
        }

        if (self::isValidMime(logger: $this->logger, response: $response, expectedMime: self::$torrentMime)) {
            return $response->getBody();
        }

        return null;
    }
}
