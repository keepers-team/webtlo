<?php

/**
 * Class Qbittorrent
 * Supported by qBittorrent 4.1 and later
 */
class Qbittorrent extends TorrentClient
{

    protected static $base = 'http://%s:%s/%s';

    /**
     * получение идентификатора сессии и запись его в $this->sid
     * @return bool true в случе успеха, false в случае неудачи
     */
    protected function getSID()
    {
        $ch = curl_init();
        curl_setopt_array($ch, array(
            CURLOPT_URL => sprintf(self::$base, $this->host, $this->port, 'api/v2/auth/login'),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POSTFIELDS => http_build_query(
                array(
                    'username' => $this->login,
                    'password' => $this->password,
                )
            ),
            CURLOPT_HEADER => true,
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
            return true;
        }
        Log::append('Не удалось подключиться к веб-интерфейсу торрент-клиента.');
        Log::append('Проверьте в настройках правильность введённого логина и пароля для доступа к торрент-клиенту.');
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
    private function makeRequest($url, $fields = '', $decode = true, $options = array())
    {
        $ch = curl_init();
        curl_setopt_array($ch, array(
            CURLOPT_URL => sprintf(self::$base, $this->host, $this->port, $url),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_COOKIE => $this->sid,
            CURLOPT_POSTFIELDS => $fields,
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
        $data = $this->makeRequest('api/v2/torrents/info');
        if (empty($data)) {
            return false;
        }
        foreach ($data as $torrent) {
            if ($torrent['state'] != 'error') {
                if ($torrent['progress'] == 1) {
                    $torrentStatus = $torrent['state'] == 'pausedUP' ? -1 : 1;
                } else {
                    $torrentStatus = 0;
                }
            } else {
                $torrentStatus = -2;
            }
            $hash = strtoupper($torrent['hash']);
            $torrents[$hash] = $torrentStatus;
        }
        return isset($torrents) ? $torrents : array();
    }

    public function addTorrent($torrentFilePath, $savePath = '')
    {
        if (version_compare(PHP_VERSION, '5.5.0') >= 0) {
            $torrentData = new CurlFile($torrentFilePath, 'application/x-bittorrent');
        } else {
            $torrentData = '@' . $torrentFilePath;
        }
        $fields = array(
            'torrents' => $torrentData,
            'savepath' => $savePath,
        );
        $this->makeRequest('api/v2/torrents/add', $fields, false);
    }

    public function setLabel($hashes, $label = '')
    {
        $label = trim($label);
        $clientCategories = json_decode($this->getAllCategoriesFromClient(), true);
        if (!array_key_exists($label, $clientCategories)) {
            $this->addNewCategory($label);
        }

        $hashes = array_map(function ($hash) {
            return strtolower($hash);
        }, $hashes);
        $fields = http_build_query(
            array('hashes' => implode('|', $hashes), 'category' => $label),
            '',
            '&',
            PHP_QUERY_RFC3986
        );
        $this->makeRequest('api/v2/torrents/setCategory', $fields, false);
    }

    private function getAllCategoriesFromClient()
    {
        return $this->makeRequest('api/v2/torrents/categories', "", false);
    }

    private function addNewCategory($categoryName)
    {
        $fields = array(
            'category' => $categoryName,
        );
        $this->makeRequest('api/v2/torrents/createCategory', $fields, false);
    }

    public function startTorrents($hashes, $force = false)
    {
        $hashes = array_map(function ($hash) {
            return strtolower($hash);
        }, $hashes);
        $fields = 'hashes=' . implode('|', $hashes);
        $this->makeRequest('api/v2/torrents/resume', $fields, false);
    }

    public function stopTorrents($hashes)
    {
        $hashes = array_map(function ($hash) {
            return strtolower($hash);
        }, $hashes);
        $fields = 'hashes=' . implode('|', $hashes);
        $this->makeRequest('api/v2/torrents/pause', $fields, false);
    }

    public function removeTorrents($hashes, $deleteLocalData = false)
    {
        $hashes = array_map(function ($hash) {
            return strtolower($hash);
        }, $hashes);
        $action = $deleteLocalData ? '&deleteFiles=true' : '';
        $fields = 'hashes=' . implode('|', $hashes) . $action;
        $this->makeRequest('api/v2/torrents/delete', $fields, false);
    }

    public function recheckTorrents($hashes)
    {
        $hashes = array_map(function ($hash) {
            return strtolower($hash);
        }, $hashes);
        $fields = 'hashes=' . implode('|', $hashes);
        $this->makeRequest('/api/v2/torrents/recheck', $fields, false);
    }
}
