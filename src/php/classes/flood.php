<?php

/**
 * Class Flood
 * Supported by flood by jesec API
 */
class Flood extends TorrentClient
{
    protected static $base = '%s://%s:%s/%s';

    /** Позволяет ли клиент присваивать раздаче категорию при добавлении. */
    protected bool $categoryAddingAllowed = true;

    private $responseHttpCode;
    private $errorStates = ['/.*Couldn\'t connect.*/', '/.*error.*/', '/.*Timeout.*/', '/.*missing.*/', '/.*unknown.*/'];

    /**
     * получение идентификатора сессии и запись его в $this->sid
     * @return bool true в случе успеха, false в случае неудачи
     */
    protected function getSID()
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
            Log::append('CURL ошибка: ' . curl_error($ch));
            Log::append('Проверьте в настройках правильность введённого IP-адреса и порта для доступа к торрент-клиенту');
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
            Log::append('Error: Incorrect login/password');
        } elseif ($responseHttpCode == 422) {
            Log::append('Error: Malformed request');
        } else {
            Log::append('Не удалось подключиться к веб-интерфейсу торрент-клиента');
            Log::append('Проверьте в настройках правильность введённого логина и пароля для доступа к торрент-клиенту');
        }
        return false;
    }

    /**
     * выполнение запроса
     * @param $fields
     * @param string $url
     * @param bool $decode
     * @param array $options
     *
     * @return bool|mixed|string
     */
    private function makeRequest($url, $fields = '', $options = [])
    {
        $this->responseHttpCode = null;
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
                Log::append('CURL ошибка: ' . curl_error($this->ch));
                return false;
            }
            return $this->responseHttpCode == 200 ? json_decode($response, true) : false;
        }
    }

    public function getAllTorrents(array $filter = [])
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
                'topic_id'      => $this->getTorrentTopicId($torrent['comment']),
                'comment'       => $torrent['comment'],
                'done'          => $torrent['percentComplete'] / 100,
                'error'         => $torrentError,
                'name'          => $torrent['name'],
                'paused'        => $torrentPaused,
                'time_added'    => $torrent['dateAdded'],
                'total_size'    => $torrent['sizeBytes'],
                'tracker_error' => $torrentErrorMessage,
            ];
        }
        return $torrents;
    }

    public function addTorrent(string $torrentFilePath, string $savePath = '', string $label = '')
    {
        $fields = [
            'files'       => [base64_encode(file_get_contents($torrentFilePath))],
            'destination' => $savePath,
            'start'       => true,
        ];
        if (!empty($label)) {
            $label = $this->prepareLabel($label);
            $fields['tags'] = [$label];
        }

        $response = $this->makeRequest('api/torrents/add-files', $fields);
        if (
            $response === false
            && $this->responseHttpCode == 403
        ) {
            Log::append('Error: Invalid destination');
        }
        if (
            $response === false
            && $this->responseHttpCode == 500
        ) {
            Log::append('Error: Unknown failure');
        }
        if (
            $response === false
            && $this->responseHttpCode == 400
        ) {
            Log::append('Error: Malformed request');
        }
        return $response;
    }

    public function setLabel($torrentHashes, $labelName = '')
    {
        $labelName = $this->prepareLabel($labelName);
        $fields = [
            'hashes' => $torrentHashes,
            'tags' => [$labelName]
        ];
        return $this->makeRequest('api/torrents/tags', $fields, [CURLOPT_CUSTOMREQUEST => 'PATCH']);
    }

    private function prepareLabel(string $label): string
    {
        return str_replace([',', '/', '\\'], '', $label);
    }

    public function startTorrents($torrentHashes, $forceStart = false)
    {
        $fields = ['hashes' => $torrentHashes];
        return $this->makeRequest('api/torrents/start', $fields);
    }

    public function stopTorrents($torrentHashes)
    {
        $fields = ['hashes' => $torrentHashes];
        return $this->makeRequest('api/torrents/stop', $fields);
    }

    public function removeTorrents($torrentHashes, $deleteFiles = false)
    {
        $deleteFiles = $deleteFiles ? 'true' : 'false';
        $fields = [
            'hashes' => $torrentHashes,
            'deleteData' => $deleteFiles
        ];
        return $this->makeRequest('api/torrents/delete', $fields);
    }

    public function recheckTorrents($torrentHashes)
    {
        $fields = ['hashes' => $torrentHashes];
        return $this->makeRequest('api/torrents/check-hash', $fields);
    }
}
