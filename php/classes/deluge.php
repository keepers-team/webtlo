<?php

/**
 * Class Deluge
 * Supported by Deluge 1.3.6 [ plugins WebUi 0.1 and Label 0.2 ] and later
 */
class Deluge extends TorrentClient
{
    private $labels;

    protected static $base = 'http://%s:%s/json';

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
            CURLOPT_URL => sprintf(self::$base, $this->host, $this->port),
            CURLOPT_POSTFIELDS => json_encode($fields),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => 'gzip',
            CURLOPT_HEADER => true,
            CURLOPT_HTTPHEADER => array('Content-Type: application/json'),
        ));
        $response = curl_exec($ch);
        if ($response === false) {
            Log::append('CURL ошибка: ' . curl_error($ch));
            Log::append('Проверьте в настройках правильность введённого IP-адреса и порта для доступа к торрент-клиенту.');
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
        Log::append('Не удалось подключиться к веб-интерфейсу торрент-клиента.');
        Log::append('Проверьте в настройках правильность введённого логина и пароля для доступа к торрент-клиенту.');
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
        $ch = curl_init();
        curl_setopt_array($ch, array(
            CURLOPT_URL => sprintf(self::$base, $this->host, $this->port),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_COOKIE => $this->sid,
            CURLOPT_ENCODING => 'gzip',
            CURLOPT_POSTFIELDS => json_encode($fields),
            CURLOPT_HTTPHEADER => array('Content-Type: application/json'),
        ));
        curl_setopt_array($ch, $options);
        $response = curl_exec($ch);
        if ($response === false) {
            Log::append('CURL ошибка: ' . curl_error($ch));
            return false;
        }
        curl_close($ch);
        $response = json_decode($response, true);
        if ($response['error'] === null) {
            return $response['result'];
        } else {
            Log::append('Error: ' . $response['error']['message'] . ' (' . $response['error']['code'] . ')');
            return false;
        }
    }

    public function getTorrents()
    {
        $fields = array(
            'method' => 'core.get_torrents_status',
            'params' => array(
                (object) array(),
                array('paused', 'message', 'progress'),
            ),
            'id' => 9,
        );
        $result = $this->makeRequest($fields);
        if ($result === false) {
            return false;
        }
        $torrents = array();
        foreach ($result as $hash => $torrent) {
            if ($torrent['message'] == 'OK') {
                if ($torrent['progress'] == 100) {
                    $torrentStatus = $torrent['paused'] ? -1 : 1;
                } else {
                    $torrentStatus = 0;
                }
            } else {
                $torrentStatus = -2;
            }
            $hash = strtoupper($hash);
            $torrents[$hash] = $torrentStatus;
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
    private function createLabel($label)
    {
        $label = str_replace(' ', '_', $label);
        if (!preg_match('|^[aA-zZ0-9\-_]+$|', $label)) {
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
        if (in_array($label, $this->labels)) {
            return true;
        }
        $this->labels[] = $label;
        $fields = array(
            'method' => 'label.add',
            'params' => array($label),
            'id' => 3,
        );
        return $this->makeRequest($fields);
    }

    public function setLabel($hashes, $label = '')
    {
        $createdLabel = $this->createLabel($label);
        if ($createdLabel === false) {
            return false;
        }
        foreach ($hashes as $hash) {
            $fields = array(
                'method' => 'label.set_torrent',
                'params' => array(
                    strtolower($hash),
                    $label,
                ),
                'id' => 3,
            );
            $this->makeRequest($fields);
        }
    }

    public function startTorrents($hashes, $force = false)
    {
        $fields = array(
            'method' => 'core.resume_torrent',
            'params' => array(
                array_map('strtolower', $hashes),
            ),
            'id' => 4,
        );
        return $this->makeRequest($fields);
    }

    public function stopTorrents($hashes)
    {
        $fields = array(
            'method' => 'core.pause_torrent',
            'params' => array(
                array_map('strtolower', $hashes),
            ),
            'id' => 8,
        );
        return $this->makeRequest($fields);
    }

    public function removeTorrents($hashes, $deleteLocalData = false)
    {
        foreach ($hashes as $hash) {
            $fields = array(
                'method' => 'core.remove_torrent',
                'params' => array(
                    strtolower($hash),
                    $deleteLocalData,
                ),
                'id' => 6,
            );
            $this->makeRequest($fields);
        }
    }

    public function recheckTorrents($hashes)
    {
        $fields = array(
            'method' => 'core.force_recheck',
            'params' => array(
                array_map('strtolower', $hashes),
            ),
            'id' => 5,
        );
        return $this->makeRequest($fields);
    }
}
