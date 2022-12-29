<?php

namespace KeepersTeam\Webtlo\Clients;

/**
 * Class Flood
 * Supported by flood by jesec API
 */
class Flood extends TorrentClient
{
    protected static string $base = '%s://%s:%s/%s';

    private int $responseHttpCode;
    private array $errorStates = ['/.*Couldn\'t connect.*/', '/.*error.*/', '/.*Timeout.*/', '/.*missing.*/', '/.*unknown.*/'];

    /**
     * @inheritdoc
     */
    protected function getSID(): bool
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => sprintf(self::$base, $this->scheme, $this->host, $this->port, 'api/auth/authenticate'),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POSTFIELDS => http_build_query(
                [
                    'username' => $this->login,
                    'password' => $this->password,
                ]
            ),
            CURLOPT_HEADER => true,
            CURLOPT_CONNECTTIMEOUT => 20,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_HTTPHEADER => ['Content-Type' => 'application/json', 'Accept' => 'application/json']
        ]);
        $response = curl_exec($ch);
        if ($response === false) {
            $this->logger->error("Failed to obtain session identifier", ['error' => curl_error($ch)]);
            return false;
        }
        $responseHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($responseHttpCode == 200) {
            preg_match('/Set-Cookie: ([^;]+)/', $response, $matches);
            if (!empty($matches)) {
                $this->sid = $matches[1];
                return true;
            }
        } elseif ($responseHttpCode == 401) {
            $this->logger->error('Incorrect login/password', ['response' => $response]);
        } elseif ($responseHttpCode == 422) {
            $this->logger->error('Malformed request', ['response' => $response]);
        } else {
            $this->logger->error('Failed to authenticate', ['response' => $response]);
        }
        return false;
    }

    /**
     * выполнение запроса
     * @param string $url
     * @param string|array $fields
     * @param array $options
     *
     * @return array|false
     */
    private function makeRequest(string $url, string|array $fields = '', array $options = []): array|false
    {
        curl_reset($this->ch);
        curl_setopt_array($this->ch, [
            CURLOPT_URL => sprintf(self::$base, $this->scheme, $this->host, $this->port, $url),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_COOKIE => $this->sid,
            CURLOPT_CUSTOMREQUEST => $fields == '' ? 'GET' : 'POST',
            CURLOPT_POSTFIELDS => $fields == '' ? '' : json_encode($fields, JSON_UNESCAPED_SLASHES)
        ]);
        curl_setopt($this->ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json; charset=utf-8', 'Accept: application/json']);
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
        $response = $this->makeRequest('api/torrents');
        if ($response === false) {
            return false;
        }
        $response = $response['torrents'];
        $torrents = [];
        foreach ($response as $torrent) {
            $torrentHash = $torrent['hash'];
            $torrentPaused = in_array('stopped', $torrent['status']) ? 1 : 0;
            $torrentError = 0;
            $torrentErrorMessage = '';
            foreach ($this->errorStates as $pattern) {
                if (preg_match($pattern, $torrent['message'])) {
                    $torrentError = 1;
                    $torrentErrorMessage = $torrent['message'];
                    break;
                }
            }
            $torrents[$torrentHash] = [
                'comment' => $torrent['comment'],
                'done' => $torrent['percentComplete'] / 100,
                'error' => $torrentError,
                'name' => $torrent['name'],
                'paused' => $torrentPaused,
                'time_added' => $torrent['dateAdded'],
                'total_size' => $torrent['sizeBytes'],
                'tracker_error' => $torrentErrorMessage
            ];
        }
        return $torrents;
    }

    /**
     * @inheritdoc
     */
    public function addTorrent(string $torrentFilePath, string $savePath = ''): bool
    {
        $fields = [
            'files' => [base64_encode(file_get_contents($torrentFilePath))],
            'destination' => '', # $savePath,
            'start' => true
        ];
        $response = $this->makeRequest('api/torrents/add-files', $fields);
        if (
            $response === false
            && $this->responseHttpCode == 403
        ) {
            $this->logger->error('Invalid destination', ['code' => $this->responseHttpCode]);
        }
        if (
            $response === false
            && $this->responseHttpCode == 500
        ) {
            $this->logger->error('Malformed request', ['code' => $this->responseHttpCode]);
        }
        if (
            $response === false
            && $this->responseHttpCode == 400
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
        $labelName = str_replace([',', '/', '\\'], '', $labelName);
        $fields = [
            'hashes' => $torrentHashes,
            'tags' => [$labelName]
        ];
        return $this->makeRequest('api/torrents/tags', $fields, [CURLOPT_CUSTOMREQUEST => 'PATCH']);
    }

    /**
     * @inheritdoc
     */
    public function startTorrents(array $torrentHashes, bool $forceStart = false): bool
    {
        $fields = ['hashes' => $torrentHashes];
        return $this->makeRequest('api/torrents/start', $fields);
    }

    /**
     * @inheritdoc
     */
    public function stopTorrents(array $torrentHashes): bool
    {
        $fields = ['hashes' => $torrentHashes];
        return $this->makeRequest('api/torrents/stop', $fields);
    }

    /**
     * @inheritdoc
     */
    public function removeTorrents(array $torrentHashes, bool $deleteFiles = false): bool
    {
        $deleteFiles = $deleteFiles ? 'true' : 'false';
        $fields = [
            'hashes' => $torrentHashes,
            'deleteData' => $deleteFiles
        ];
        return $this->makeRequest('api/torrents/delete', $fields);
    }

    /**
     * @inheritdoc
     */
    public function recheckTorrents(array $torrentHashes): bool
    {
        $fields = ['hashes' => $torrentHashes];
        return $this->makeRequest('api/torrents/check-hash', $fields);
    }
}
