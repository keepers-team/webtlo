<?php

namespace KeepersTeam\Webtlo\Clients;

use CURLFile;

/**
 * Class Qbittorrent
 * Supported by qBittorrent 4.1 and later
 */
class Qbittorrent extends TorrentClient
{
    protected static string $base = '%s://%s:%s/%s';

    private array|false $categories;
    private int $responseHttpCode;
    private array $errorStates = ['error', 'missingFiles', 'unknown'];

    /**
     * @inheritdoc
     */
    protected function getSID(): bool
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => sprintf(self::$base, $this->scheme, $this->host, $this->port, 'api/v2/auth/login'),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POSTFIELDS => http_build_query(
                [
                    'username' => $this->login,
                    'password' => $this->password,
                ]
            ),
            CURLOPT_HEADER => true,
            CURLOPT_CONNECTTIMEOUT => 20,
            CURLOPT_TIMEOUT => 20
        ]);
        $response = curl_exec($ch);
        if ($response === false) {
            $this->logger->error("Failed to obtain session identifier", ['error' => curl_error($ch)]);
            return false;
        }
        $responseHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($responseHttpCode == 200) {
            preg_match('|Set-Cookie: ([^;]+);|i', $response, $matches);
            if (!empty($matches)) {
                $this->sid = $matches[1];
                return true;
            }
        } elseif ($responseHttpCode == 403) {
            $this->logger->error('Error: User\'s IP is banned for too many failed login attempts', ['response' => $response]);
        } else {
            $this->logger->error('Failed to authenticate', ['response' => $response]);
        }
        return false;
    }

    /**
     * выполнение запроса
     * @param string $url
     * @param array|string $fields
     *
     * @return array|false
     */
    private function makeRequest(string $url, array|string $fields = ''): array|false
    {
        $options = [];
        curl_setopt_array($this->ch, [
            CURLOPT_URL => sprintf(self::$base, $this->scheme, $this->host, $this->port, $url),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_COOKIE => $this->sid,
            CURLOPT_POSTFIELDS => $fields,
        ]);
        curl_setopt_array($this->ch, $options);
        $maxNumberTry = 3;
        $connectionNumberTry = 1;
        while (true) {
            $response = curl_exec($this->ch);
            $this->responseHttpCode = curl_getinfo($this->ch, CURLINFO_HTTP_CODE);
            if ($response === false) {
                if (
                    $this->responseHttpCode < 300
                    && $connectionNumberTry <= $maxNumberTry
                ) {
                    $connectionNumberTry++;
                    sleep(1);
                    continue;
                }
                $this->logger->error("Failed to make request", ['error' => curl_error($this->ch)]);
                return false;
            }
            return $this->responseHttpCode == 200 ? json_decode($response, true) : false;
        }
    }

    /**
     * @inheritdoc
     */
    public function getAllTorrents(): array|false
    {
        $response = $this->makeRequest('api/v2/torrents/info');
        if ($response === false) {
            return false;
        }
        $torrents = [];
        foreach ($response as $torrent) {
            $clientHash = $torrent['hash'];
            $torrentHash = $torrent['infohash_v1'] ?? $torrent['hash'];
            $torrentHash = strtoupper($torrentHash);
            $torrentPaused = str_starts_with($torrent['state'], 'paused') ? 1 : 0;
            $torrentError = in_array($torrent['state'], $this->errorStates) ? 1 : 0;
            $torrents[$torrentHash] = [
                'comment' => '',
                'done' => $torrent['progress'],
                'error' => $torrentError,
                'name' => $torrent['name'],
                'paused' => $torrentPaused,
                'time_added' => $torrent['added_on'],
                'total_size' => $torrent['total_size'],
                'client_hash' => $clientHash,
                'tracker_error' => ''
            ];

            // получение ошибок трекера
            if (empty($torrent['tracker'])) {
                $torrentTrackers = $this->getTrackers($clientHash);
                if ($torrentTrackers !== false) {
                    foreach ($torrentTrackers as $torrentTracker) {
                        if ($torrentTracker['status'] == 4) {
                            $torrents[$torrentHash]['tracker_error'] = $torrentTracker['msg'];
                            break;
                        }
                    }
                }
            }

            // получение ссылки на раздачу
            $properties = $this->getProperties($clientHash);
            if ($properties !== false) {
                $torrents[$torrentHash]['comment'] = $properties['comment'];
            }
        }

        return $torrents;
    }

    private function getTrackers(string $torrentHash): array|false
    {
        $torrent_trackers = [];
        $trackers = $this->makeRequest(
            'api/v2/torrents/trackers',
            ['hash' => strtolower($torrentHash)]
        );
        if ($trackers === false) {
            return false;
        }
        foreach ($trackers as $tracker) {
            if (!preg_match('/\*\*.*\*\*/', $tracker['url'])) {
                $torrent_trackers[] = $tracker;
            }
        }
        return $torrent_trackers;
    }

    private function getProperties(string $torrentHash): array|false
    {
        $properties = $this->makeRequest(
            'api/v2/torrents/properties',
            ['hash' => strtolower($torrentHash)]
        );
        if ($properties === false) {
            return false;
        }
        return $properties;
    }

    /**
     * @inheritdoc
     */
    public function addTorrent(string $torrentFilePath, string $savePath = ''): bool
    {
        $torrentFile = new CurlFile($torrentFilePath, 'application/x-bittorrent');
        $fields = [
            'torrents' => $torrentFile,
            'savepath' => $savePath,
        ];
        $response = $this->makeRequest('api/v2/torrents/add', $fields);
        if (
            $response === false
            && $this->responseHttpCode == 415
        ) {
            $this->logger->error('Malformed request', ['code' => $this->responseHttpCode]);
        }
        return $response;
    }

    /**
     * @inheritdoc
     */
    public function setLabel(array $torrentHashes, string $labelName = ''): bool
    {
        if ($this->categories === false) {
            $this->categories = $this->makeRequest('api/v2/torrents/categories');
            if ($this->categories === false) {
                return false;
            }
        }
        if (
            is_array($this->categories)
            && !array_key_exists($labelName, $this->categories)
        ) {
            $this->createCategory($labelName);
            $this->categories[$labelName] = [];
        }
        $fields = http_build_query(
            [
                'hashes' => implode('|', array_map('strtolower', $torrentHashes)),
                'category' => $labelName
            ],
            '',
            '&',
            PHP_QUERY_RFC3986
        );
        $response = $this->makeRequest('api/v2/torrents/setCategory', $fields);
        if (
            $response === false
            && $this->responseHttpCode == 409
        ) {
            $this->logger->error('Category name does not exist', ['name' => $labelName]);
        }
        return $response;
    }

    private function createCategory(string $categoryName): void
    {
        $fields = [
            'category' => $categoryName,
            'savePath' => ''
        ];
        $response = $this->makeRequest('api/v2/torrents/createCategory', $fields);
        if ($response === false) {
            if ($this->responseHttpCode == 400) {
                $this->logger->error('Category name is empty');
            } elseif ($this->responseHttpCode == 409) {
                $this->logger->error('Category name is invalid', ['name' => $categoryName]);
            }
        }
    }

    /**
     * @inheritdoc
     */
    public function startTorrents(array $torrentHashes, bool $forceStart = false): bool
    {
        $fields = ['hashes' => implode('|', array_map('strtolower', $torrentHashes))];
        return $this->makeRequest('api/v2/torrents/resume', $fields);
    }

    /**
     * @inheritdoc
     */
    public function stopTorrents(array $torrentHashes): bool
    {
        $fields = ['hashes' => implode('|', array_map('strtolower', $torrentHashes))];
        return $this->makeRequest('api/v2/torrents/pause', $fields);
    }

    /**
     * @inheritdoc
     */
    public function removeTorrents(array $torrentHashes, bool $deleteFiles = false): bool
    {
        $deleteFiles = $deleteFiles ? 'true' : 'false';
        $fields = [
            'hashes' => implode('|', array_map('strtolower', $torrentHashes)),
            'deleteFiles' => $deleteFiles
        ];
        return $this->makeRequest('api/v2/torrents/delete', $fields);
    }

    /**
     * @inheritdoc
     */
    public function recheckTorrents(array $torrentHashes): bool
    {
        $fields = ['hashes' => implode('|', array_map('strtolower', $torrentHashes))];
        return $this->makeRequest('/api/v2/torrents/recheck', $fields);
    }
}
