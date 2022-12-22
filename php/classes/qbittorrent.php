<?php

/**
 * Class Qbittorrent
 * Supported by qBittorrent 4.1 and later
 */
class Qbittorrent extends TorrentClient
{
    protected static $base = '%s://%s:%s/%s';

    private $tags;
    private $responseHttpCode;
    private $errorStates = array('error', 'missingFiles', 'unknown');

    /**
     * получение идентификатора сессии и запись его в $this->sid
     * @return bool true в случе успеха, false в случае неудачи
     */
    protected function getSID()
    {
        $ch = curl_init();
        curl_setopt_array($ch, array(
            CURLOPT_URL => sprintf(self::$base, $this->scheme, $this->host, $this->port, 'api/v2/auth/login'),
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
        $responseHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($responseHttpCode == 200) {
            preg_match('|Set-Cookie: ([^;]+);|i', $response, $matches);
            if (!empty($matches)) {
                $this->sid = $matches[1];
                return true;
            }
        } elseif ($responseHttpCode == 403) {
            Log::append('Error: User\'s IP is banned for too many failed login attempts');
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
    private function makeRequest($url, $fields = '', $options = array())
    {
        $this->responseHttpCode = null;
        curl_setopt_array($this->ch, array(
            CURLOPT_URL => sprintf(self::$base, $this->scheme, $this->host, $this->port, $url),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_COOKIE => $this->sid,
            CURLOPT_POSTFIELDS => $fields,
        ));
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

    public function getAllTorrents()
    {
        $response = $this->makeRequest('api/v2/torrents/info');
        if ($response === false) {
            return false;
        }
        $torrents = array();
        foreach ($response as $torrent) {
            $clientHash = $torrent['hash'];
            $torrentHash = isset($torrent['infohash_v1']) ? $torrent['infohash_v1'] : $torrent['hash'];
            $torrentHash = strtoupper($torrentHash);
            $torrentPaused = preg_match('/^paused/', $torrent['state']) ? 1 : 0;
            $torrentError = in_array($torrent['state'], $this->errorStates) ? 1 : 0;
            $torrents[$torrentHash] = array(
                'comment' => '',
                'done' => $torrent['progress'],
                'error' => $torrentError,
                'name' => $torrent['name'],
                'paused' => $torrentPaused,
                'time_added' => $torrent['added_on'],
                'total_size' => $torrent['total_size'],
                'client_hash' => $clientHash,
                'tracker_error' => ''
            );

            // получение ошибок трекера
            if (empty($torrent['tracker'])) {
                $torrentTrackers = $this->getTrackers($clientHash);
                if ($torrentTrackers !== false) {
                    foreach ($torrentTrackers as $torrentTracker) {
                        if ($torrentTracker['status'] == 4) {
                            $torrents[$torrentHash]['tracker_error'] = $torrentTracker['msg'];
                            break;
                        }
                    }
                }
            }

            // получение ссылки на раздачу
            $properties = $this->getProperties($clientHash);
            if ($properties !== false) {
                $torrents[$torrentHash]['comment'] = $properties['comment'];
            }
        }

        return $torrents;
    }

    public function getTrackers($torrentHash)
    {
        $torrent_trackers = array();
        $trackers = $this->makeRequest(
            'api/v2/torrents/trackers',
            array('hash' => strtolower($torrentHash))
        );
        if ($trackers === false) {
            return false;
        }
        foreach ($trackers as $tracker) {
            if (!preg_match('/\*\*.*\*\*/', $tracker['url'])) {
                $torrent_trackers[] = $tracker;
            }
        }
        return $torrent_trackers;
    }

    public function getProperties($torrentHash)
    {
        $properties = $this->makeRequest(
            'api/v2/torrents/properties',
            array('hash' => strtolower($torrentHash))
        );
        if ($properties === false) {
            return false;
        }
        return $properties;
    }

    public function addTorrent($torrentFilePath, $savePath = '')
    {
        if (version_compare(PHP_VERSION, '5.5.0') >= 0) {
            $torrentFile = new CurlFile($torrentFilePath, 'application/x-bittorrent');
        } else {
            $torrentFile = '@' . $torrentFilePath;
        }
        $fields = array(
            'torrents' => $torrentFile,
            'savepath' => $savePath,
        );
        $response = $this->makeRequest('api/v2/torrents/add', $fields);
        if (
            $response === false
            && $this->responseHttpCode == 415
        ) {
            Log::append('Error: Torrent file is not valid');
        }
        return $response;
    }

    public function setLabel($torrentHashes, $labelName = '')
    {
        if ($this->tags === null) {
            $this->tags = $this->makeRequest('api/v2/torrents/tags');
            if ($this->tags === false) {
                return false;
            }
        }
        if (
            is_array($this->tags)
            && !array_key_exists($labelName, $this->tags)
        ) {
            $this->createTag($labelName);
            $this->tags[$labelName] = array();
        }
        $fields = http_build_query(
            array(
                'hashes' => implode('|', array_map('strtolower', $torrentHashes)),
                'tags' => $labelName
            ),
            '',
            '&',
            PHP_QUERY_RFC3986
        );
        $response = $this->makeRequest('api/v2/torrents/addTags', $fields);
        if (
            $response === false
            && $this->responseHttpCode == 409
        ) {
            Log::append('Error: Tag does not exist');
        }
        return $response;
    }

    public function createTag($tagName, $savePath = '')
    {
        $fields = array(
            'tags' => $tagName,
        );
        $response = $this->makeRequest('api/v2/torrents/createTags', $fields);
        if ($response === false) {
            if ($this->responseHttpCode == 400) {
                Log::append('Error: Tag name is empty');
            } elseif ($this->responseHttpCode == 409) {
                Log::append('Error: Tag name is invalid');
            }
        }
        return $response;
    }

    public function startTorrents($torrentHashes, $forceStart = false)
    {
        $fields = array('hashes' => implode('|', array_map('strtolower', $torrentHashes)));
        return $this->makeRequest('api/v2/torrents/resume', $fields);
    }

    public function stopTorrents($torrentHashes)
    {
        $fields = array('hashes' => implode('|', array_map('strtolower', $torrentHashes)));
        return $this->makeRequest('api/v2/torrents/pause', $fields);
    }

    public function removeTorrents($torrentHashes, $deleteFiles = false)
    {
        $deleteFiles = $deleteFiles ? 'true' : 'false';
        $fields = array(
            'hashes' => implode('|', array_map('strtolower', $torrentHashes)),
            'deleteFiles' => $deleteFiles
        );
        return $this->makeRequest('api/v2/torrents/delete', $fields);
    }

    public function recheckTorrents($torrentHashes)
    {
        $fields = array('hashes' => implode('|', array_map('strtolower', $torrentHashes)));
        return $this->makeRequest('/api/v2/torrents/recheck', $fields);
    }
}
