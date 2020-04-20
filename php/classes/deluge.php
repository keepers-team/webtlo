<?php

/**
 * Class Deluge
 * Supported by Deluge 1.3.6 [ plugins WebUi 0.1 and Label 0.2 ] and later
 */
class Deluge extends TorrentClient
{

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
            'id' => 2
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
            if (!$webUIIsConnected['result']) {
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
                        'params' => array($firstHost['result'][0][0]),
                        'id' => 7,
                    )
                );
                if ($firstHostStatus['result'][3] === 'Offline') {
                    Log::append('Deluge daemon сейчас недоступен');
                    return false;
                } elseif ($firstHostStatus['result'][3] === 'Online') {
                    $response = $this->makeRequest(
                        array(
                            'method' => 'web.connect',
                            'params' => array($firstHost['result'][0][0]),
                            'id' => 7,
                        )
                    );
                    if ($response['error'] === null) {
                        Log::append('Подключение Deluge webUI к Deluge daemon прошло успешно');
                        return true;
                    } else {
                        Log::append('Подключение Deluge webUI к Deluge daemon не удалось');
                        return false;
                    }
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
    private function makeRequest($fields, $decode = true, $options = array())
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
        return $decode ? json_decode($response, true) : $response;
    }

    public function getTorrents()
    {
        $fields = array(
            'method' => 'web.update_ui',
            'params' => array(
                array(
                    'paused',
                    'message',
                    'progress',
                ),
                (object)array(),
            ),
            'id' => 9,
        );
        $data = $this->makeRequest($fields);
        if (empty($data['result']['torrents'])) {
            return false;
        }
        foreach ($data['result']['torrents'] as $hash => $torrent) {
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
        return isset($torrents) ? $torrents : array();
    }

    public function addTorrent($filename, $savePath = '')
    {
        $localPath = $this->downloadTorrent($filename);
        if (empty($localPath)) {
            return false;
        }
        $fields = array(
            'method' => 'web.add_torrents',
            'params' => array(
                array(
                    array(
                        'path' => $localPath,
                        'options' => array('download_location' => $savePath),
                    ),
                ),
            ),
            'id' => 1,
        );
        $data = $this->makeRequest($fields);
        // return $data['result'] == 1 ? true : false;
    }

    /**
     * загрузить торрент локально
     *
     * @param $filename
     * @return mixed
     */
    public function downloadTorrent($filename)
    {
        $fields = array(
            'method' => 'web.download_torrent_from_url',
            'params' => array(
                $filename,
            ),
            'id' => 2,
        );
        $data = $this->makeRequest($fields);
        return $data['result']; // return localpath
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
            'params' => array(
                $pluginName,
            ),
            'id' => 3,
        );
        $data = $this->makeRequest($fields);
    }

    /**
     * добавить метку
     *
     * @param string $label
     * @return bool
     */
    public function addLabel($label)
    {
        // не знаю как по-другому вытащить список уже имеющихся label
        $fields = array(
            'method' => 'core.get_filter_tree',
            'params' => array(),
            'id' => 3,
        );
        $filters = $this->makeRequest($fields);
        $labels = array_column_common($filters['result']['label'], 0);
        if (in_array($label, $labels)) {
            return false;
        }
        $fields = array(
            'method' => 'label.add',
            'params' => array(
                $label,
            ),
            'id' => 3,
        );
        $data = $this->makeRequest($fields);
    }

    public function setLabel($hashes, $label = '')
    {
        $label = str_replace(' ', '_', $label);
        if (!preg_match('|^[aA-zZ0-9\-_]+$|', $label)) {
            Log::append('В названии метки присутствуют недопустимые символы.');
            return 'В названии метки присутствуют недопустимые символы.';
        }
        $this->enablePlugin('Label');
        $this->addLabel($label);
        foreach ($hashes as $hash) {
            $fields = array(
                'method' => 'label.set_torrent',
                'params' => array(
                    strtolower($hash),
                    $label,
                ),
                'id' => 1,
            );
            $data = $this->makeRequest($fields);
        }
    }

    /**
     * запустить все (unused)
     */
    public function startAllTorrents()
    {
        $fields = array(
            'method' => 'core.resume_all_torrents',
            'params' => array(),
            'id' => 7,
        );
        $data = $this->makeRequest($fields);
    }

    public function startTorrents($hashes, $force = false)
    {
        $fields = array(
            'method' => 'core.resume_torrent',
            'params' => array(
                array_map('strtolower', $hashes),
            ),
            'id' => 7,
        );
        $data = $this->makeRequest($fields);
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
        $data = $this->makeRequest($fields);
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
            $data = $this->makeRequest($fields);
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
        $data = $this->makeRequest($fields);
    }
}
