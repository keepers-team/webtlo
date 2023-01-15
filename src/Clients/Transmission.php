<?php

namespace KeepersTeam\Webtlo\Clients;

/**
 * Class Transmission
 * Supported by Transmission 2.80 and later
 */
final class Transmission extends GenericTorrentClient
{
    protected static string $base = '%s://%s:%s/transmission/rpc';

    private int $rpcVersion;

    /**
     * @inheritdoc
     */
    protected function getSID(): bool
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => sprintf(self::$base, $this->scheme, $this->host, $this->port),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERPWD => $this->login . ':' . $this->password,
            CURLOPT_HEADER => true,
            CURLOPT_CONNECTTIMEOUT => $this->timeout->connection,
            CURLOPT_TIMEOUT => $this->timeout->request
        ]);
        $response = curl_exec($ch);
        if ($response === false) {
            $this->logger->error("Failed to make request", ['error' => curl_error($this->ch)]);
            return false;
        }
        $responseHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($responseHttpCode == 401) {
            $this->logger->error('Failed to authenticate', ['response' => $response]);
        } elseif (
            $responseHttpCode == 405
            || $responseHttpCode == 409
        ) {
            preg_match('|.*\r\n(X-Transmission-Session-Id: .*?)(\r\n.*)|', $response, $matches);
            if (!empty($matches)) {
                $this->sid = $matches[1];
            }
            $fields = ['method' => 'session-get'];
            $response = $this->makeRequest($fields);
            if ($response !== false) {
                $this->rpcVersion = $response['rpc-version'];
                return true;
            }
        }
        $this->logger->error('Failed to authenticate', ['response' => $response]);
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
            CURLOPT_USERPWD => $this->login . ':' . $this->password,
            CURLOPT_HTTPHEADER => [$this->sid],
            CURLOPT_POSTFIELDS => json_encode($fields),
        ]);
        curl_setopt_array($this->ch, $options);
        $maxNumberTry = 3;
        $responseNumberTry = 1;
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
            if (
                $responseHttpCode == 409
                && $responseNumberTry <= $maxNumberTry
            ) {
                $responseNumberTry++;
                preg_match('|<code>(.*)</code>|', $response, $matches);
                if (!empty($matches)) {
                    curl_setopt_array($this->ch, [CURLOPT_HTTPHEADER => [$matches[1]]]);
                    $this->sid = $matches[1];
                    continue;
                }
            }
            $response = json_decode($response, true);
            if ($response === null) {
                $this->logger->error("Failed to decode response", ['error' => json_last_error_msg()]);
                return false;
            }
            if ($response['result'] != 'success') {
                if (
                    empty($response['result'])
                    && $responseNumberTry <= $maxNumberTry
                ) {
                    $this->logger->info("Retrying request", ['retry' => $responseNumberTry]);
                    $responseNumberTry++;
                    sleep(1);
                    continue;
                }
                if (empty($response['result'])) {
                    $this->logger->error("Empty result", ['response' => $response]);
                } else {
                    $this->logger->error("Malformed response", ['response' => $response]);
                }
                return false;
            }
            return $response['arguments'];
        }
    }

    /**
     * @inheritdoc
     */
    public function getAllTorrents(): array|false
    {
        $fields = [
            'method' => 'torrent-get',
            'arguments' => [
                'fields' => [
                    'addedDate',
                    'comment',
                    'error',
                    'errorString',
                    'hashString',
                    'name',
                    'percentDone',
                    'status',
                    'totalSize'
                ]
            ]
        ];
        $response = $this->makeRequest($fields);
        if ($response === false) {
            return false;
        }
        $torrents = [];
        foreach ($response['torrents'] as $torrent) {
            $torrentHash = strtoupper($torrent['hashString']);
            $torrentPaused = $torrent['status'] == 0 ? 1 : 0;
            $torrentError = $torrent['error'] != 0 ? 1 : 0;
            $torrentTrackerError = $torrent['error'] == 2 ? $torrent['errorString'] : '';
            $torrents[$torrentHash] = [
                'comment' => $torrent['comment'],
                'done' => $torrent['percentDone'],
                'error' => $torrentError,
                'name' => $torrent['name'],
                'paused' => $torrentPaused,
                'time_added' => $torrent['addedDate'],
                'total_size' => $torrent['totalSize'],
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
        $fields = [
            'method' => 'torrent-add',
            'arguments' => [
                'metainfo' => base64_encode($torrentFile),
                'paused' => false,
            ],
        ];
        if (!empty($savePath)) {
            $fields['arguments']['download-dir'] = $savePath;
        }
        $response = $this->makeRequest($fields);
        if ($response === false) {
            return false;
        }
        if (!empty($response['torrent-duplicate'])) {
            $torrentHash = $response['torrent-duplicate']['hashString'];
            $this->logger->notice("This torrent already added", ['hash' => $torrentHash]);
        }
        return true;
    }

    /**
     * @inheritdoc
     */
    public function setLabel(array $torrentHashes, string $labelName = ''): bool
    {
        if ($this->rpcVersion < 16) {
            $this->logger->warning("Labels are not supported in rpc version of this client", ['rpc_version' => $this->rpcVersion]);
            return false;
        }
        $labelName = str_replace(',', '', $labelName);
        $fields = [
            'method' => 'torrent-set',
            'arguments' => [
                'labels' => [$labelName],
                'ids' => $torrentHashes
            ],
        ];
        return $this->makeRequest($fields);
    }

    /**
     * @inheritdoc
     */
    public function startTorrents(array $torrentHashes, bool $forceStart = false): bool
    {
        $method = $forceStart ? 'torrent-start-now' : 'torrent-start';
        $fields = [
            'method' => $method,
            'arguments' => ['ids' => $torrentHashes],
        ];
        return $this->makeRequest($fields);
    }

    /**
     * @inheritdoc
     */
    public function stopTorrents(array $torrentHashes): bool
    {
        $fields = [
            'method' => 'torrent-stop',
            'arguments' => ['ids' => $torrentHashes],
        ];
        return $this->makeRequest($fields);
    }

    /**
     * @inheritdoc
     */
    public function recheckTorrents(array $torrentHashes): bool
    {
        $fields = [
            'method' => 'torrent-verify',
            'arguments' => ['ids' => $torrentHashes],
        ];
        return $this->makeRequest($fields);
    }

    /**
     * @inheritdoc
     */
    public function removeTorrents(array $torrentHashes, bool $deleteFiles = false): bool
    {
        $fields = [
            'method' => 'torrent-remove',
            'arguments' => [
                'ids' => $torrentHashes,
                'delete-local-data' => $deleteFiles,
            ],
        ];
        return $this->makeRequest($fields);
    }
}
