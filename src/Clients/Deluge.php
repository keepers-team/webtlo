<?php

namespace KeepersTeam\Webtlo\Clients;

/**
 * Class Deluge
 * Supported by Deluge 2.1.1 [ plugins WebUi 0.2 and Label 0.3 ] and later
 */
class Deluge extends TorrentClient
{
    protected static string $base = '%s://%s:%s/json';

    private array|false $labels = false;

    /**
     * @inheritdoc
     */
    protected function getSID(): bool
    {
        $ch = curl_init();
        $fields = [
            'method' => 'auth.login',
            'params' => [$this->password],
            'id' => 7
        ];
        curl_setopt_array($ch, [
            CURLOPT_URL => sprintf(self::$base, $this->scheme, $this->host, $this->port),
            CURLOPT_POSTFIELDS => json_encode($fields),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => 'gzip',
            CURLOPT_HEADER => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_CONNECTTIMEOUT => 20,
            CURLOPT_TIMEOUT => 20
        ]);
        $response = curl_exec($ch);
        if ($response === false) {
            $this->logger->error("Failed to obtain session identifier", ['error' => curl_error($ch)]);
            return false;
        }
        curl_close($ch);
        preg_match('|Set-Cookie: ([^;]+);|i', $response, $matches);
        if (!empty($matches)) {
            $this->sid = $matches[1];
            $webUIIsConnected = $this->makeRequest(
                [
                    'method' => 'web.connected',
                    'params' => [],
                    'id' => 7,
                ]
            );
            if (!$webUIIsConnected) {
                $firstHost = $this->makeRequest(
                    [
                        'method' => 'web.get_hosts',
                        'params' => [],
                        'id' => 7,
                    ]
                );
                $firstHostStatus = $this->makeRequest(
                    [
                        'method' => 'web.get_host_status',
                        'params' => [$firstHost[0][0]],
                        'id' => 7,
                    ]
                );
                if (in_array('Offline', $firstHostStatus)) {
                    return false;
                } elseif (in_array('Online', $firstHostStatus)) {
                    $response = $this->makeRequest(
                        [
                            'method' => 'web.connect',
                            'params' => [$firstHost[0][0]],
                            'id' => 7,
                        ]
                    );
                    return !($response === false);
                }
            }
            return true;
        }
        $this->logger->error("Failed to authenticate", ['response' => $response]);
        return false;
    }

    /**
     * выполнение запроса
     *
     * @param array $fields
     * @return array|false
     */
    private function makeRequest(array $fields): array|false
    {
        $options = [];
        curl_setopt_array($this->ch, [
            CURLOPT_URL => sprintf(self::$base, $this->scheme, $this->host, $this->port),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_COOKIE => $this->sid,
            CURLOPT_ENCODING => 'gzip',
            CURLOPT_POSTFIELDS => json_encode($fields),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        ]);
        curl_setopt_array($this->ch, $options);
        $maxNumberTry = 3;
        $connectionNumberTry = 1;
        while (true) {
            $response = curl_exec($this->ch);
            $responseHttpCode = curl_getinfo($this->ch, CURLINFO_HTTP_CODE);
            if ($response === false) {
                if (
                    $responseHttpCode < 300
                    && $connectionNumberTry <= $maxNumberTry
                ) {
                    $connectionNumberTry++;
                    sleep(1);
                    continue;
                }
                $this->logger->error("Failed to make request", ['error' => curl_error($this->ch)]);
                return false;
            }
            $response = json_decode($response, true);
            if ($response['error'] === null) {
                return $response['result'];
            } else {
                $this->logger->error("Client returns error", ['code' => $response['error']['code'], 'message' => $response['error']['message']]);
                return false;
            }
        }
    }

    /**
     * @inheritdoc
     */
    public function getAllTorrents(): array|false
    {
        $fields = [
            'method' => 'core.get_torrents_status',
            'params' => [
                (object)[],
                [
                    'comment',
                    'message',
                    'name',
                    'paused',
                    'progress',
                    'time_added',
                    'total_size',
                    'tracker_status'
                ],
            ],
            'id' => 9,
        ];
        $response = $this->makeRequest($fields);
        if ($response === false) {
            return false;
        }
        $torrents = [];
        foreach ($response as $torrentHash => $torrent) {
            $torrentHash = strtoupper($torrentHash);
            $torrentPaused = $torrent['paused'] == 1 ? 1 : 0;
            $torrentError = $torrent['message'] != 'OK' ? 1 : 0;
            preg_match('/.*Error: (.*)/', $torrent['tracker_status'], $matches);
            $torrentTrackerError = $matches[1] ?? '';
            $torrents[$torrentHash] = [
                'comment' => $torrent['comment'],
                'done' => $torrent['progress'] / 100,
                'error' => $torrentError,
                'name' => $torrent['name'],
                'paused' => $torrentPaused,
                'time_added' => $torrent['time_added'],
                'total_size' => $torrent['total_size'],
                'tracker_error' => $torrentTrackerError
            ];
        }
        return $torrents;
    }

    /**
     * @inheritdoc
     */
    public function addTorrent(string $torrentFilePath, string $savePath = ''): bool
    {
        $torrentFile = file_get_contents($torrentFilePath);
        if ($torrentFile === false) {
            $this->logger->error("Failed to upload file", ['filename' => basename($torrentFilePath)]);
            return false;
        }
        $torrentOptions = empty($savePath) ? [] : ['download_location' => $savePath];
        $fields = [
            'method' => 'core.add_torrent_file',
            'params' => [
                basename($torrentFilePath),
                base64_encode($torrentFile),
                $torrentOptions,
            ],
            'id' => 1,
        ];
        return $this->makeRequest($fields);
    }

    /**
     * включение плагинов
     *
     * @param string $pluginName
     * @return bool|array
     */
    public function enablePlugin(string $pluginName): bool|array
    {
        $fields = [
            'method' => 'core.enable_plugin',
            'params' => [$pluginName],
            'id' => 2,
        ];
        return $this->makeRequest($fields);
    }

    /**
     * добавить метку
     *
     * @param string $labelName
     * @return bool|array
     */
    private function createLabel(string $labelName): bool|array
    {
        if ($this->labels === false) {
            $enablePlugin = $this->enablePlugin('Label');
            if ($enablePlugin === false) {
                return false;
            }
            $fields = [
                'method' => 'label.get_labels',
                'params' => [],
                'id' => 3,
            ];
            $this->labels = $this->makeRequest($fields);
        }
        if ($this->labels === false) {
            return false;
        }
        if (in_array($labelName, array_map('strtolower', $this->labels))) {
            return true;
        }
        $this->labels[] = $labelName;
        $fields = [
            'method' => 'label.add',
            'params' => [$labelName],
            'id' => 3,
        ];
        return $this->makeRequest($fields);
    }

    /**
     * @inheritdoc
     */
    public function setLabel(array $torrentHashes, string $labelName = ''): bool
    {
        $labelName = str_replace(' ', '_', $labelName);
        if (!preg_match('|^[A-z0-9\-]+$|', $labelName)) {
            $this->logger->error("Found forbidden symbols in label", ['label_name' => $labelName]);
            return false;
        }
        $labelName = strtolower($labelName);
        $createdLabel = $this->createLabel($labelName);
        if ($createdLabel === false) {
            return false;
        }
        $result = null;
        foreach ($torrentHashes as $torrentHash) {
            $fields = [
                'method' => 'label.set_torrent',
                'params' => [
                    strtolower($torrentHash),
                    $labelName,
                ],
                'id' => 3,
            ];
            $response = $this->makeRequest($fields);
            if ($response === false) {
                $result = false;
            }
        }
        return $result;
    }

    /**
     * @inheritdoc
     */
    public function startTorrents(array $torrentHashes, bool $forceStart = false): bool
    {
        $fields = [
            'method' => 'core.resume_torrent',
            'params' => [
                array_map('strtolower', $torrentHashes)
            ],
            'id' => 4,
        ];
        return $this->makeRequest($fields);
    }

    /**
     * @inheritdoc
     */
    public function stopTorrents(array $torrentHashes): bool
    {
        $fields = [
            'method' => 'core.pause_torrent',
            'params' => [
                array_map('strtolower', $torrentHashes)
            ],
            'id' => 8,
        ];
        return $this->makeRequest($fields);
    }

    /**
     * @inheritdoc
     */
    public function removeTorrents(array $torrentHashes, bool $deleteFiles = false): bool
    {
        $result = null;
        foreach ($torrentHashes as $torrentHash) {
            $fields = [
                'method' => 'core.remove_torrent',
                'params' => [
                    strtolower($torrentHash),
                    $deleteFiles,
                ],
                'id' => 6,
            ];
            $response = $this->makeRequest($fields);
            if ($response === false) {
                $result = false;
            }
        }
        return $result;
    }

    /**
     * @inheritdoc
     */
    public function recheckTorrents(array $torrentHashes): bool
    {
        $fields = [
            'method' => 'core.force_recheck',
            'params' => [
                array_map('strtolower', $torrentHashes)
            ],
            'id' => 5,
        ];
        return $this->makeRequest($fields);
    }
}
