<?php

/**
 * Class Deluge
 * Supported by Deluge 1.3.6 [ plugins WebUi 0.1 and Label 0.2 ] and later
 */
class Deluge extends TorrentClient
{
    protected static $base = '%s://%s:%s/json';

    private $labels;

    /**
     * получение идентификатора сессии и запись его в $this->sid
     * @return bool true в случе успеха, false в случае неудачи
     */
    protected function getSID()
    {
        $ch = curl_init();
        $fields = array(
            'method' => 'auth.login',
            'params' => array($this->password),
            'id' => 7
        );
        curl_setopt_array($ch, array(
            CURLOPT_URL => sprintf(self::$base, $this->scheme, $this->host, $this->port),
            CURLOPT_POSTFIELDS => json_encode($fields),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => 'gzip',
            CURLOPT_HEADER => true,
            CURLOPT_HTTPHEADER => array('Content-Type: application/json'),
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
        preg_match('|Set-Cookie: ([^;]+);|i', $response, $matches);
        if (!empty($matches)) {
            $this->sid = $matches[1];
            $webUIIsConnected = $this->makeRequest(
                array(
                    'method' => 'web.connected',
                    'params' => array(),
                    'id' => 7,
                )
            );
            if (!$webUIIsConnected) {
                $firstHost = $this->makeRequest(
                    array(
                        'method' => 'web.get_hosts',
                        'params' => array(),
                        'id' => 7,
                    )
                );
                $firstHostStatus = $this->makeRequest(
                    array(
                        'method' => 'web.get_host_status',
                        'params' => array($firstHost[0][0]),
                        'id' => 7,
                    )
                );
                if (in_array('Offline', $firstHostStatus)) {
                    return false;
                } elseif (in_array('Online', $firstHostStatus)) {
                    $response = $this->makeRequest(
                        array(
                            'method' => 'web.connect',
                            'params' => array($firstHost[0][0]),
                            'id' => 7,
                        )
                    );
                    return $response === false ? false : true;
                }
            }
            return true;
        }
        Log::append('Не удалось подключиться к веб-интерфейсу торрент-клиента');
        Log::append('Проверьте в настройках правильность введённого логина и пароля для доступа к торрент-клиенту');
        return false;
    }

    /**
     * выполнение запроса
     *
     * @param $fields
     * @param bool $decode
     * @param array $options
     * @return bool|mixed|string
     */
    private function makeRequest($fields, $options = array())
    {
        curl_setopt_array($this->ch, array(
            CURLOPT_URL => sprintf(self::$base, $this->scheme, $this->host, $this->port),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_COOKIE => $this->sid,
            CURLOPT_ENCODING => 'gzip',
            CURLOPT_POSTFIELDS => json_encode($fields),
            CURLOPT_HTTPHEADER => array('Content-Type: application/json'),
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
            $response = json_decode($response, true);
            if ($response['error'] === null) {
                return $response['result'];
            } else {
                Log::append('Error: ' . $response['error']['message'] . ' (' . $response['error']['code'] . ')');
                return false;
            }
        }
    }

    public function getTorrents()
    {
        $fields = array(
            'method' => 'core.get_torrents_status',
            'params' => array(
                (object) array(),
                array(
                    'message',
                    'paused',
                    'progress',
                    'tracker_status'
                ),
            ),
            'id' => 9,
        );
        $response = $this->makeRequest($fields);
        if ($response === false) {
            return false;
        }
        $torrents = array();
        foreach ($response as $hashString => $torrent) {
            preg_match('/.*Error: (.*)/', $torrent['tracker_status'], $matches);
            if (
                $torrent['message' == 'OK']
                && !isset($matches[1])
            ) {
                if ($torrent['progress'] == 100) {
                    $torrentStatus = $torrent['paused'] ? -1 : 1;
                } else {
                    $torrentStatus = 0;
                }
            } else {
                $torrentStatus = -2;
            }
            $torrentHash = strtoupper($hashString);
            $torrents[$torrentHash] = $torrentStatus;
        }
        return $torrents;
    }

    public function getAllTorrents()
    {
        $fields = array(
            'method' => 'core.get_torrents_status',
            'params' => array(
                (object) array(),
                array(
                    'comment',
                    'message',
                    'name',
                    'paused',
                    'progress',
                    'time_added',
                    'total_size',
                    'tracker_status'
                ),
            ),
            'id' => 9,
        );
        $response = $this->makeRequest($fields);
        if ($response === false) {
            return false;
        }
        $torrents = array();
        foreach ($response as $torrentHash => $torrent) {
            $torrentHash = strtoupper($torrentHash);
            $torrentPaused = $torrent['paused'] == 1 ? 1 : 0;
            $torrentError = 'message' != 'OK' ? 1 : 0;
            preg_match('/.*Error: (.*)/', $torrent['tracker_status'], $matches);
            $torrentTrackerError = isset($matches[1]) ? $matches[1] : '';
            $torrents[$torrentHash] = array(
                'comment' => $torrent['comment'],
                'done' => $torrent['progress'] / 100,
                'error' => $torrentError,
                'name' => $torrent['name'],
                'paused' => $torrentPaused,
                'time_added' => $torrent['time_added'],
                'total_size' => $torrent['total_size'],
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
        $torrentOptions = empty($savePath) ? array() : array('download_location' => $savePath);
        $fields = array(
            'method' => 'core.add_torrent_file',
            'params' => array(
                basename($torrentFilePath),
                base64_encode($torrentFile),
                $torrentOptions,
            ),
            'id' => 1,
        );
        return $this->makeRequest($fields);
    }

    /**
     * включение плагинов
     *
     * @param string $name
     */
    public function enablePlugin($pluginName)
    {
        $fields = array(
            'method' => 'core.enable_plugin',
            'params' => array($pluginName),
            'id' => 2,
        );
        return $this->makeRequest($fields);
    }

    /**
     * добавить метку
     *
     * @param string $label
     * @return bool
     */
    private function createLabel($labelName)
    {
        $labelName = str_replace(' ', '_', $labelName);
        if (!preg_match('|^[aA-zZ0-9\-_]+$|', $labelName)) {
            Log::append('Error: В названии метки присутствуют недопустимые символы');
            return false;
        }
        if ($this->labels === null) {
            $enablePlugin = $this->enablePlugin('Label');
            if ($enablePlugin === false) {
                return false;
            }
            $fields = array(
                'method' => 'label.get_labels',
                'params' => array(),
                'id' => 3,
            );
            $this->labels = $this->makeRequest($fields);
        }
        if ($this->labels === false) {
            return false;
        }
        if (in_array($labelName, $this->labels)) {
            return true;
        }
        $this->labels[] = $labelName;
        $fields = array(
            'method' => 'label.add',
            'params' => array($labelName),
            'id' => 3,
        );
        return $this->makeRequest($fields);
    }

    public function setLabel($torrentHashes, $labelName = '')
    {
        $createdLabel = $this->createLabel($labelName);
        if ($createdLabel === false) {
            return false;
        }
        $result = null;
        foreach ($torrentHashes as $torrentHash) {
            $fields = array(
                'method' => 'label.set_torrent',
                'params' => array(
                    strtolower($torrentHash),
                    $labelName,
                ),
                'id' => 3,
            );
            $response = $this->makeRequest($fields);
            if ($response === false) {
                $result = false;
            }
        }
        return $result;
    }

    public function startTorrents($torrentHashes, $forceStart = false)
    {
        $fields = array(
            'method' => 'core.resume_torrent',
            'params' => array(
                array_map('strtolower', $torrentHashes)
            ),
            'id' => 4,
        );
        return $this->makeRequest($fields);
    }

    public function stopTorrents($torrentHashes)
    {
        $fields = array(
            'method' => 'core.pause_torrent',
            'params' => array(
                array_map('strtolower', $torrentHashes)
            ),
            'id' => 8,
        );
        return $this->makeRequest($fields);
    }

    public function removeTorrents($torrentHashes, $deleteFiles = false)
    {
        $result = null;
        foreach ($torrentHashes as $torrentHash) {
            $fields = array(
                'method' => 'core.remove_torrent',
                'params' => array(
                    strtolower($torrentHash),
                    $deleteFiles,
                ),
                'id' => 6,
            );
            $response = $this->makeRequest($fields);
            if ($response === false) {
                $result = false;
            }
        }
        return $result;
    }

    public function recheckTorrents($torrentHashes)
    {
        $fields = array(
            'method' => 'core.force_recheck',
            'params' => array(
                array_map('strtolower', $torrentHashes)
            ),
            'id' => 5,
        );
        return $this->makeRequest($fields);
    }
}
