<?php

/**
 * Class Utorrent
 * Supported by uTorrent 1.8.2 and later
 */
class Utorrent extends TorrentClient
{

    protected static $base = 'http://%s:%s/gui/%s';

    protected $token;
    protected $guid;

    /**
     * получение токена
     * @return bool
     */
    protected function getSID()
    {
        $ch = curl_init();
        curl_setopt_array($ch, array(
            CURLOPT_URL => sprintf(self::$base, $this->host, $this->port, 'token.html'),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERPWD => $this->login . ':' . $this->password,
            CURLOPT_HEADER => true,
        ));
        $response = curl_exec($ch);
        if ($response === false) {
            Log::append('CURL ошибка: ' . curl_error($ch));
            Log::append('Проверьте в настройках правильность введённого IP-адреса и порта для доступа к торрент-клиенту.');
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
        Log::append('Проверьте в настройках правильность введённого логина и пароля для доступа к торрент-клиенту.');
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
        $ch = curl_init();
        curl_setopt_array($ch, array(
            CURLOPT_URL => sprintf(self::$base, $this->host, $this->port, $url),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERPWD => $this->login . ':' . $this->password,
            CURLOPT_COOKIE => 'GUID=' . $this->guid,
            CURLOPT_POSTFIELDS => $fields
        ));
        curl_setopt_array($ch, $options);
        $response = curl_exec($ch);
        if ($response === false) {
            Log::append('CURL ошибка: ' . curl_error($ch));
            return false;
        }
        curl_close($ch);
        $response = json_decode($response, true);
        if (isset($response['error'])) {
            Log::append('Error: ' . $response['error']);
            return false;
        }
        return $response;
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
            // 0 - Started, 2 - Paused, 3 - Error, 4 - Checked, 7 - Loaded, 100% Downloads
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

    public function addTorrent($torrentFilePath, $savePath = '')
    {
        $this->setSetting('dir_active_download_flag', true);
        if (!empty($savePath)) {
            $this->setSetting('dir_active_download', urlencode($savePath));
        }
        if (version_compare(PHP_VERSION, '5.5.0') >= 0) {
            $torrentData = new CurlFile($torrentFilePath, 'application/x-bittorrent');
        } else {
            $torrentData = '@' . $torrentFilePath;
        }
        return $this->makeRequest('?action=add-file', array('torrent_file' => $torrentData));
    }

    /**
     * изменение свойств торрента
     * @param $hash
     * @param $property
     * @param $value
     */
    public function setProperties($hash, $property, $value)
    {
        $request = preg_replace('|^(.*)$|', 'hash=$0&s=' . $property . '&v=' . urlencode($value), $hash);
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

    public function setLabel($hash, $label = '')
    {
        return $this->setProperties($hash, 'label', $label);
    }

    public function startTorrents($hashes, $force = false)
    {
        $action = $force ? 'forcestart' : 'start';
        return $this->makeRequest('?action=' . $action . $this->implodeParams('&hash=', $hashes));
    }

    public function recheckTorrents($hashes)
    {
        return $this->makeRequest('?action=recheck' . $this->implodeParams('&hash=', $hashes));
    }

    public function stopTorrents($hashes)
    {
        return $this->makeRequest('?action=stop' . $this->implodeParams('&hash=', $hashes));
    }

    public function removeTorrents($hashes, $deleteLocalData = false)
    {
        $action = $deleteLocalData ? 'removedata' : 'remove';
        return $this->makeRequest('?action=' . $action . $this->implodeParams('&hash=', $hashes));
    }
}
