<?php

/**
 * Class Ktorrent
 * Supported by KTorrent 4.3.1
 */
class Ktorrent extends TorrentClient
{
    protected static $base = '%s://%s:%s/%s';

    protected $challenge;

    public function isOnline()
    {
        return $this->getChallenge();
    }

    /**
     * получение challenge
     * @return bool
     */
    protected function getChallenge()
    {
        $ch = curl_init();
        curl_setopt_array($ch, array(
            CURLOPT_URL => sprintf(self::$base, $this->scheme, $this->host, $this->port, 'login/challenge.xml'),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POSTFIELDS => http_build_query(
                array(
                    'username' => $this->login,
                    'password' => $this->password,
                )
            ),
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
        curl_close($ch);
        preg_match('|<challenge>(.*)</challenge>|sei', $response, $matches);
        if (!empty($matches)) {
            $this->challenge = sha1($matches[1] . $this->password);
            return $this->getSID();
        }
        Log::append('Не удалось подключиться к веб-интерфейсу торрент-клиента');
        Log::append('Проверьте в настройках правильность введённого логина и пароля для доступа к торрент-клиенту');
        return false;
    }

    /**
     * получение идентификатора сессии и запись его в $this->sid
     * @return bool true в случе успеха, false в случае неудачи
     */
    protected function getSID()
    {
        $ch = curl_init();
        curl_setopt_array($ch, array(
            CURLOPT_URL => sprintf(self::$base, $this->scheme, $this->host, $this->port, 'login?page=interface.html'),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POSTFIELDS => http_build_query(
                array(
                    'username' => $this->login,
                    'challenge' => $this->challenge,
                    'Login' => 'Sign in',
                )
            ),
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
        curl_close($ch);
        preg_match('|Set-Cookie: ([^;]+)|i', $response, $matches);
        if (!empty($matches)) {
            $this->sid = $matches[1];
            return true;
        }
        Log::append('Не удалось подключиться к веб-интерфейсу торрент-клиента.');
        Log::append('Проверьте в настройках правильность введённого логина и пароля для доступа к торрент-клиенту');
        return false;
    }

    /**
     * выполнение запроса
     * @param $url
     * @param array $options
     * @return bool|mixed
     */
    private function makeRequest($url, $options = array())
    {
        curl_setopt_array($this->ch, array(
            CURLOPT_URL => sprintf(self::$base, $this->scheme, $this->host, $this->port, $url),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_COOKIE => $this->sid,
        ));
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
                Log::append('CURL ошибка: ' . curl_error($this->ch));
                return false;
            }
            return $responseHttpCode == 200 ? $response : false;
        }
    }

    private function getTorrentsData()
    {
        $response = $this->makeRequest('data/torrents.xml');
        if ($response === false) {
            return false;
        }
        $response = new SimpleXMLElement($response);
        $response = json_decode(json_encode($response), true);
        // вывод отличается, если в клиенте только одна раздача
        if (
            isset($response['torrent'])
            && !is_array(array_shift($response['torrent']))
        ) {
            $response['torrent'] = array($response['torrent']);
        }
        return $response;
    }

    public function getTorrents()
    {
        $response = $this->getTorrentsData();
        if ($response === false) {
            return false;
        }
        $torrents = array();
        if (isset($response['torrent'])) {
            foreach ($response['torrent'] as $torrent) {
                if ($torrent['status'] != 'Ошибка') {
                    if ($torrent['percentage'] == 100) {
                        $torrentStatus = $torrent['status'] == 'Пауза' ? -1 : 1;
                    } else {
                        $torrentStatus = 0;
                    }
                } else {
                    $torrentStatus = -2;
                }
                $torrentHash = strtoupper($torrent['info_hash']);
                $torrents[$torrentHash] = $torrentStatus;
            }
        }
        return $torrents;
    }

    public function getAllTorrents()
    {
        return array();
    }

    public function addTorrent($torrentFilePath, $savePath = '')
    {
        /**
         * https://cgit.kde.org/ktorrent.git/tree/plugins/webinterface/torrentposthandler.cpp#n55
         * клиент не терпит две пустых строки между заголовком запроса и его телом
         * библиотека cURL как раз формирует двойной отступ
         */
        $torrentFile = file_get_contents($torrentFilePath);
        if ($torrentFile === false) {
            Log::append('Error: не удалось загрузить файл ' . basename($torrentFilePath));
            return false;
        }
        $boundary = uniqid();
        $content = '------' . $boundary . _BR_
            . 'Content-Disposition: form-data; name="load_torrent"; filename="' . basename($torrentFile) . '"' . _BR_
            . 'Content-Type: application/x-bittorrent' . _BR_
            . _BR_
            . $torrentFile . _BR_
            . '------' . $boundary . _BR_
            . 'Content-Disposition: form-data; name="Upload Torrent"' . _BR_
            . _BR_
            . 'Upload Torrent' . _BR_
            . '------' . $boundary . '--';
        $header = array(
            'Content-Type: multipart/form-data; boundary=------' . $boundary . _BR_
                . 'Content-Length: ' . strlen($content) . _BR_
                . 'Cookie: ' . $this->sid
        );
        $context = stream_context_create(
            array(
                'http' => array(
                    'method' => 'POST',
                    'header' => $header,
                    'content' => $content
                )
            )
        );
        return file_get_contents(
            sprintf(self::$base, $this->scheme, $this->host, $this->port, 'torrent/load?page=interface.html'),
            false,
            $context
        );
    }

    public function setLabel($torrentHashes, $labelName = '')
    {
        Log::append('Торрент-клиент не поддерживает установку меток');
        return false;
    }

    public function startTorrents($torrentHashes, $forceStart = false)
    {
        $response = $this->getTorrentsData();
        if ($response === false) {
            return false;
        }
        $torrents = array_flip(array_column_common($response['torrent'], 'info_hash'));
        unset($response);
        $result = null;
        foreach ($torrentHashes as $torrentHash) {
            $torrentHash = strtolower($torrentHash);
            if (isset($torrents[$torrentHash])) {
                $response = $this->makeRequest('action?start=' . $torrents[$torrentHash]);
                if ($response === false) {
                    $result = false;
                }
            }
        }
        return $result;
    }

    public function stopTorrents($torrentHashes)
    {
        $response = $this->getTorrentsData();
        if ($response === false) {
            return false;
        }
        $torrents = array_flip(array_column_common($response['torrent'], 'info_hash'));
        unset($response);
        $result = null;
        foreach ($torrentHashes as $torrentHash) {
            $torrentHash = strtolower($torrentHash);
            if (isset($torrents[$torrentHash])) {
                $response = $this->makeRequest('action?stop=' . $torrents[$torrentHash]);
                if ($response === false) {
                    $result = false;
                }
            }
        }
        return $result;
    }

    public function removeTorrents($torrentHashes, $deleteFiles = false)
    {
        $response = $this->getTorrentsData();
        if ($response === false) {
            return false;
        }
        $torrents = array_flip(array_column_common($response['torrent'], 'info_hash'));
        unset($response);
        $result = null;
        foreach ($torrentHashes as $torrentHash) {
            $torrentHash = strtolower($torrentHash);
            if (isset($torrents[$torrentHash])) {
                $response = $this->makeRequest('action?remove=' . $torrents[$torrentHash]);
                if ($response === false) {
                    $result = false;
                }
            }
        }
        return $result;
    }

    public function recheckTorrents($torrentHashes)
    {
        Log::append('Торрент-клиент не поддерживает проверку локальных данных');
        return false;
    }
}
