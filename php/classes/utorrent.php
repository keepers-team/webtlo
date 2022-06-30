<?php

/**
 * Class Utorrent
 * Supported by uTorrent 1.8.2 and later
 */
class Utorrent extends TorrentClient
{
    protected static $base = '%s://%s:%s/gui/%s';

    protected $guid;
    protected $token;

    /**
     * получение токена
     * @return bool
     */
    protected function getSID()
    {
        $ch = curl_init();
        curl_setopt_array($ch, array(
            CURLOPT_URL => sprintf(self::$base, $this->scheme, $this->host, $this->port, 'token.html'),
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
        $responseInfo = curl_getinfo($ch);
        curl_close($ch);
        $headers = substr($response, 0, $responseInfo['header_size']);
        preg_match('|Set-Cookie: GUID=([^;]+);|i', $headers, $headersMatches);
        if (!empty($headersMatches)) {
            $this->guid = $headersMatches[1];
        }
        preg_match('|<div id=\'token\'.+>(.*)<\/div>|', $response, $responseMatches);
        if (!empty($responseMatches)) {
            $this->token = $responseMatches[1];
            return true;
        }
        Log::append('Не удалось подключиться к веб-интерфейсу торрент-клиента.');
        Log::append('Проверьте в настройках правильность введённого логина и пароля для доступа к торрент-клиенту');
        return false;
    }

    /**
     * выполнение запроса к торрент-клиенту
     * @param $request
     * @param bool $decode
     * @param array $options
     * @return bool|mixed|string
     */
    private function makeRequest($url, $fields = '', $options = array())
    {
        $url = preg_replace('|^\?|', '?token=' . $this->token . '&', $url);
        curl_setopt_array($this->ch, array(
            CURLOPT_URL => sprintf(self::$base, $this->scheme, $this->host, $this->port, $url),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERPWD => $this->login . ':' . $this->password,
            CURLOPT_COOKIE => 'GUID=' . $this->guid,
            CURLOPT_POSTFIELDS => $fields,
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
            if ($response === null) {
                Log::append('Error: ' . json_last_error_msg());
                return false;
            }
            if (isset($response['error'])) {
                Log::append('Error: ' . $response['error']);
                return false;
            }
            return $response;
        }
    }

    public function getTorrents()
    {
        $response = $this->makeRequest('?list=1');
        if ($response === false) {
            return false;
        }
        $torrents = array();
        foreach ($response['torrents'] as $torrent) {
            $torrentState = decbin($torrent[1]);
            /*
                0 - loaded
                1 - queued
                2 - paused
                3 - error
                4 - checked
                5 - start after check
                6 - checking
                7 - started
            */
            if (!$torrentState[3]) {
                if (
                    $torrentState[0]
                    && $torrentState[4]
                    && $torrent[4] == 1000
                ) {
                    $torrentStatus = !$torrentState[2] && $torrentState[7] ? 1 : -1;
                } else {
                    $torrentStatus = 0;
                }
            } else {
                $torrentStatus = -2;
            }
            $torrents[$torrent[0]] = $torrentStatus;
        }
        return $torrents;
    }

    public function getAllTorrents()
    {
        $response = $this->makeRequest('?list=1');
        if ($response === false) {
            return false;
        }
        $torrents = array();
        foreach ($response['torrents'] as $torrent) {
            $torrentState = decbin($torrent[1]);
            $torrentHash = strtoupper($torrent[0]);
            $torrentPaused = $torrentState[2] || !$torrentState[7] ? 1 : 0;
            $torrents[$torrentHash] = array(
                'comment' => '',
                'done' => $torrent[4] / 1000,
                'error' => $torrentState[3],
                'name' => $torrent[2],
                'paused' => $torrentPaused,
                'time_added' => '',
                'total_size' => $torrent[3],
                'tracker_error' => ''
            );
        }
        return $torrents;
    }

    public function addTorrent($torrentFilePath, $savePath = '')
    {
        $this->setSetting('dir_active_download_flag', true);
        if (!empty($savePath)) {
            $this->setSetting('dir_active_download', urlencode($savePath));
        }
        if (version_compare(PHP_VERSION, '5.5.0') >= 0) {
            $torrentFile = new CurlFile($torrentFilePath, 'application/x-bittorrent');
        } else {
            $torrentFile = '@' . $torrentFilePath;
        }
        return $this->makeRequest('?action=add-file', array('torrent_file' => $torrentFile));
    }

    /**
     * изменение свойств торрента
     * @param $hash
     * @param $property
     * @param $value
     */
    public function setProperties($hashes, $property, $value)
    {
        $request = preg_replace('|^(.*)$|', 'hash=$0&s=' . $property . '&v=' . urlencode($value), $hashes);
        $request = implode('&', $request);
        return $this->makeRequest('?action=setprops&' . $request);
    }

    /**
     * изменение настроек
     * @param $setting
     * @param $value
     */
    public function setSetting($setting, $value)
    {
        return $this->makeRequest('?action=setsetting&s=' . $setting . '&v=' . $value);
    }

    /**
     * "склеивание" параметров в строку
     * @param $glue
     * @param $params
     * @return string
     */
    private function implodeParams($glue, $params)
    {
        $params = is_array($params) ? $params : array($params);
        return $glue . implode($glue, $params);
    }

    public function setLabel($torrentHashes, $labelName = '')
    {
        return $this->setProperties($torrentHashes, 'label', $labelName);
    }

    public function startTorrents($torrentHashes, $forceStart = false)
    {
        $action = $forceStart ? 'forcestart' : 'start';
        return $this->makeRequest('?action=' . $action . $this->implodeParams('&hash=', $torrentHashes));
    }

    public function recheckTorrents($torrentHashes)
    {
        return $this->makeRequest('?action=recheck' . $this->implodeParams('&hash=', $torrentHashes));
    }

    public function stopTorrents($torrentHashes)
    {
        return $this->makeRequest('?action=stop' . $this->implodeParams('&hash=', $torrentHashes));
    }

    public function removeTorrents($torrentHashes, $deleteFiles = false)
    {
        $action = $deleteFiles ? 'removedata' : 'remove';
        return $this->makeRequest('?action=' . $action . $this->implodeParams('&hash=', $torrentHashes));
    }
}
