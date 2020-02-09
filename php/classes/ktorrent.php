<?php

/**
 * Class Ktorrent
 * Supported by KTorrent 4.3.1 and later
 */
class Ktorrent extends TorrentClient
{

    protected static $base = 'http://%s:%s/%s';

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
            CURLOPT_URL => sprintf(self::$base, $this->host, $this->port, 'login/challenge.xml'),
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
        preg_match('|<challenge>(.*)</challenge>|sei', $response, $matches);
        if (!empty($matches)) {
            $this->challenge = sha1($matches[1] . $this->password);
            return $this->getSID();
        }
        Log::append('Не удалось подключиться к веб-интерфейсу торрент-клиента.');
        Log::append('Проверьте в настройках правильность введённого логина и пароля для доступа к торрент-клиенту.');
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
            CURLOPT_URL => sprintf(
                self::$base,
                $this->host,
                $this->port,
                'login?page=interface.html'
            ),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POSTFIELDS => http_build_query(
                array(
                    'username' => $this->login,
                    'challenge' => $this->challenge,
                    'Login' => 'Sign in',
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
        preg_match('|Set-Cookie: ([^;]+)|i', $response, $matches);
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
     * @param $url
     * @param bool $decode
     * @param array $options
     * @param bool $xml
     * @return bool|false|mixed|string
     */
    private function makeRequest($url, $decode = true, $options = array(), $xml = false)
    {
        $ch = curl_init();
        curl_setopt_array($ch, array(
            CURLOPT_URL => sprintf(self::$base, $this->host, $this->port, $url),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_COOKIE => $this->sid,
        ));
        curl_setopt_array($ch, $options);
        $response = curl_exec($ch);
        if ($response === false) {
            Log::append('CURL ошибка: ' . curl_error($ch));
            return false;
        }
        curl_close($ch);
        if ($xml) {
            $response = new SimpleXMLElement($response);
            $response = json_encode($response);
        }
        return $decode ? json_decode($response, true) : $response;
    }

    public function getTorrents($full = false)
    {
        $data = $this->makeRequest(
            'data/torrents.xml',
            true,
            array(CURLOPT_POST => false),
            true
        );
        if (empty($data['torrent'])) {
            return false;
        }
        // вывод отличается, если в клиенте только одна раздача
        if ($full) {
            return $data;
        }
        foreach ($data['torrent'] as $torrent) {
            if ($torrent['status'] != 'Ошибка') {
                if ($torrent['percentage'] == 100) {
                    $status = $torrent['status'] == 'Пауза' ? -1 : 1;
                } else {
                    $status = 0;
                }
                $hash = strtoupper($torrent['info_hash']);
                $torrents[$hash] = $status;
            }
        }
        return isset($torrents) ? $torrents : array();
    }

    public function addTorrent($filename, $savePath = '')
    {
        $this->makeRequest('action?load_torrent=' . $filename, false); // 200 OK
    }

    public function setLabel($hashes, $label = '')
    {
        return 'Торрент-клиент не поддерживает установку меток.';
    }

    /**
     * запустить все (unused)
     */
    public function startAllTorrents()
    {
        $this->makeRequest('action?startall=true');
    }

    public function startTorrents($hashes, $force = false)
    {
        $torrents = $this->getTorrents(true);
        if ($torrents === false) {
            return false;
        }
        $hashesFromClient = array_flip(
            array_column_common($torrents['torrent'], 'info_hash')
        );
        unset($torrents);
        foreach ($hashes as $hash) {
            if (isset($hashesFromClient[strtolower($hash)])) {
                $this->makeRequest('action?start=' . $hashesFromClient[strtolower($hash)]);
            }
        }
    }

    public function stopTorrents($hashes)
    {
        $torrents = $this->getTorrents(true);
        if ($torrents === false) {
            return false;
        }
        $hashesFromClient = array_flip(
            array_column_common($torrents['torrent'], 'info_hash')
        );
        unset($torrents);
        foreach ($hashes as $hash) {
            if (isset($hashesFromClient[strtolower($hash)])) {
                $this->makeRequest('action?stop=' . $hashesFromClient[strtolower($hash)]);
            }
        }
    }

    public function removeTorrents($hashes, $deleteLocalData = false)
    {
        $torrents = $this->getTorrents(true);
        if ($torrents === false) {
            return false;
        }
        $hashesFromClient = array_flip(
            array_column_common($torrents['torrent'], 'info_hash')
        );
        unset($torrents);
        foreach ($hashes as $hash) {
            if (isset($hashesFromClient[strtolower($hash)])) {
                $this->makeRequest('action?remove=' . $hashesFromClient[strtolower($hash)]);
            }
        }
    }

    public function recheckTorrents($hashes)
    {
        return 'Торрент-клиент не поддерживает проверку локальных данных.';
    }
}
