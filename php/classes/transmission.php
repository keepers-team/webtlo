<?php

/**
 * Class Transmission
 * Supported by Transmission 2.80 and later
 */
class Transmission extends TorrentClient
{
    protected static $base = '%s://%s:%s/transmission/rpc';

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
        } elseif ($responseHttpCode == 405) {
            $fields = array('method' => 'session-get');
            $response = $this->makeRequest($fields);
            if ($response !== false) {
                return true;
            }
        } elseif ($responseHttpCode == 409) {
            preg_match('|.*\r\n(X-Transmission-Session-Id: .*?)(\r\n.*)|', $response, $matches);
            if (!empty($matches)) {
                $this->sid = $matches[1];
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
        $ch = curl_init();
        curl_setopt_array($ch, array(
            CURLOPT_URL => sprintf(self::$base, $this->scheme, $this->host, $this->port),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERPWD => $this->login . ':' . $this->password,
            CURLOPT_HTTPHEADER => array($this->sid),
            CURLOPT_POSTFIELDS => json_encode($fields),
        ));
        curl_setopt_array($ch, $options);
        $responseNumberTry = 1;
        $maxNumberTry = 3;
        while (true) {
            $response = curl_exec($ch);
            if ($response === false) {
                Log::append('CURL ошибка: ' . curl_error($ch));
                return false;
            }
            $responseHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $response = json_decode($response, true);
            curl_close($ch);
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
            if (empty($torrent['error'])) {
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
            $fields['download-dir'] = $savePath;
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
        Log::append('Error: Торрент-клиент не поддерживает установку меток');
        return false;
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
