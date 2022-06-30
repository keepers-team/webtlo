<?php

/**
 * Class Transmission
 * Supported by Transmission 2.80 and later
 */
class Transmission extends TorrentClient
{
    protected static $base = '%s://%s:%s/transmission/rpc';

    private $rpcVersion;

    /**
     * получение идентификатора сессии и запись его в $this->sid
     * @return bool true в случе успеха, false в случае неудачи
     */
    protected function getSID()
    {
        $ch = curl_init();
        curl_setopt_array($ch, array(
            CURLOPT_URL => sprintf(self::$base, $this->scheme, $this->host, $this->port),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERPWD => $this->login . ':' . $this->password,
            CURLOPT_HEADER => true,
            CURLOPT_CONNECTTIMEOUT => 20,
            CURLOPT_TIMEOUT => 20
        ));
        $response = curl_exec($ch);
        if ($response === false) {
            Log::append('CURL ошибка: ' . curl_error($ch));
            Log::append('Проверьте в настройках правильность введённого IP-адреса и порта для доступа к торрент-клиенту');
            return false;
        }
        $responseHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($responseHttpCode == 401) {
            Log::append('Error: Не удалось авторизоваться в веб-интерфейсе торрент-клиента');
            Log::append('Проверьте в настройках правильность введённого логина и пароля для доступа к торрент-клиенту');
        } elseif (
            $responseHttpCode == 405
            || $responseHttpCode == 409
        ) {
            preg_match('|.*\r\n(X-Transmission-Session-Id: .*?)(\r\n.*)|', $response, $matches);
            if (!empty($matches)) {
                $this->sid = $matches[1];
            }
            $fields = array('method' => 'session-get');
            $response = $this->makeRequest($fields);
            if ($response !== false) {
                $this->rpcVersion = $response['rpc-version'];
                return true;
            }
        }
        Log::append('Error: Не удалось подключиться к веб-интерфейсу торрент-клиента');
        return false;
    }

    /**
     * выполнение запроса
     *
     * @param $fields
     * @param array $options
     * @return bool|mixed|string
     */
    private function makeRequest($fields, $options = array())
    {
        curl_setopt_array($this->ch, array(
            CURLOPT_URL => sprintf(self::$base, $this->scheme, $this->host, $this->port),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERPWD => $this->login . ':' . $this->password,
            CURLOPT_HTTPHEADER => array($this->sid),
            CURLOPT_POSTFIELDS => json_encode($fields),
        ));
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
                Log::append('CURL ошибка: ' . curl_error($this->ch));
                return false;
            }
            if (
                $responseHttpCode == 409
                && $responseNumberTry <= $maxNumberTry
            ) {
                $responseNumberTry++;
                preg_match('|<code>(.*)</code>|', $response, $matches);
                if (!empty($matches)) {
                    curl_setopt_array($this->ch, array(CURLOPT_HTTPHEADER => array($matches[1])));
                    $this->sid = $matches[1];
                    continue;
                }
            }
            $response = json_decode($response, true);
            if ($response === null) {
                Log::append('Error: ' . json_last_error_msg());
                return false;
            }
            if ($response['result'] != 'success') {
                if (
                    empty($response['result'])
                    && $responseNumberTry <= $maxNumberTry
                ) {
                    Log::append('Повторная попытка ' . $responseNumberTry . '/' . $maxNumberTry . ' выполнить запрос');
                    $responseNumberTry++;
                    sleep(10);
                    continue;
                }
                if (empty($response['result'])) {
                    Log::append('Error: Неизвестная ошибка (' . $responseHttpCode . ')');
                } else {
                    Log::append('Error: ' . $response['result']);
                }
                return false;
            }
            return $response['arguments'];
        }
    }

    public function getTorrents()
    {
        $fields = array(
            'method' => 'torrent-get',
            'arguments' => array(
                'fields' => array(
                    'hashString',
                    'status',
                    'error',
                    'percentDone',
                ),
            ),
        );
        $response = $this->makeRequest($fields);
        if ($response === false) {
            return false;
        }
        $torrents = array();
        foreach ($response['torrents'] as $torrent) {
            if ($torrent['error'] == 0) {
                if ($torrent['percentDone'] == 1) {
                    $torrentStatus = $torrent['status'] == 0 ? -1 : 1;
                } else {
                    $torrentStatus = 0;
                }
            } else {
                $torrentStatus = -2;
            }
            $torrentHash = strtoupper($torrent['hashString']);
            $torrents[$torrentHash] = $torrentStatus;
        }
        return $torrents;
    }

    public function getAllTorrents()
    {
        $fields = array(
            'method' => 'torrent-get',
            'arguments' => array(
                'fields' => array(
                    'addedDate',
                    'comment',
                    'error',
                    'errorString',
                    'hashString',
                    'name',
                    'percentDone',
                    'status',
                    'totalSize'
                )
            )
        );
        $response = $this->makeRequest($fields);
        if ($response === false) {
            return false;
        }
        $torrents = array();
        foreach ($response['torrents'] as $torrent) {
            $torrentHash = strtoupper($torrent['hashString']);
            $torrentPaused = $torrent['status'] == 0 ? 1 : 0;
            $torrentError = $torrent['error'] != 0 ? 1 : 0;
            $torrentTrackerError = $torrent['error'] == 2 ? $torrent['errorString'] : '';
            $torrents[$torrentHash] = array(
                'comment' => $torrent['comment'],
                'done' => $torrent['percentDone'],
                'error' => $torrentError,
                'name' => $torrent['name'],
                'paused' => $torrentPaused,
                'time_added' => $torrent['addedDate'],
                'total_size' => $torrent['totalSize'],
                'tracker_error' => $torrentTrackerError
            );
        }
        return $torrents;
    }

    public function addTorrent($torrentFilePath, $savePath = '')
    {
        $torrentFile = file_get_contents($torrentFilePath);
        if ($torrentFile === false) {
            Log::append('Error: не удалось загрузить файл ' . basename($torrentFilePath));
            return false;
        }
        $fields = array(
            'method' => 'torrent-add',
            'arguments' => array(
                'metainfo' => base64_encode($torrentFile),
                'paused' => false,
            ),
        );
        if (!empty($savePath)) {
            $fields['arguments']['download-dir'] = $savePath;
        }
        $response = $this->makeRequest($fields);
        if ($response === false) {
            return false;
        }
        if (!empty($response['torrent-added'])) {
            $torrentHash = $response['torrent-added']['hashString'];
        } elseif (!empty($response['torrent-duplicate'])) {
            $torrentHash = $response['torrent-duplicate']['hashString'];
            Log::append('Notice: Эта раздача уже раздаётся в торрент-клиенте (' . $torrentHash . ')');
        }
        return $torrentHash;
    }

    public function setLabel($torrentHashes, $labelName = '')
    {
        if ($this->rpcVersion < 16) {
            Log::append('Error: Торрент-клиент не поддерживает установку меток');
            return false;
        }
        $labelName = str_replace(',', '', $labelName);
        $fields = array(
            'method' => 'torrent-set',
            'arguments' => array(
                'labels' => array($labelName),
                'ids' => $torrentHashes
            ),
        );
        return $this->makeRequest($fields);
    }

    public function startTorrents($torrentHashes, $forceStart = false)
    {
        $method = $forceStart ? 'torrent-start-now' : 'torrent-start';
        $fields = array(
            'method' => $method,
            'arguments' => array('ids' => $torrentHashes),
        );
        return $this->makeRequest($fields);
    }

    public function stopTorrents($torrentHashes)
    {
        $fields = array(
            'method' => 'torrent-stop',
            'arguments' => array('ids' => $torrentHashes),
        );
        return $this->makeRequest($fields);
    }

    public function recheckTorrents($torrentHashes)
    {
        $fields = array(
            'method' => 'torrent-verify',
            'arguments' => array('ids' => $torrentHashes),
        );
        return $this->makeRequest($fields);
    }

    public function removeTorrents($torrentHashes, $deleteFiles = false)
    {
        $fields = array(
            'method' => 'torrent-remove',
            'arguments' => array(
                'ids' => $torrentHashes,
                'delete-local-data' => $deleteFiles,
            ),
        );
        return $this->makeRequest($fields);
    }
}
