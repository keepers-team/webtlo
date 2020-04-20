<?php

/**
 * Class Transmission
 * Supported by Transmission 2.80 and later
 */
class Transmission extends TorrentClient
{

    protected static $base = 'http://%s:%s/transmission/rpc';

    /**
     * получение идентификатора сессии и запись его в $this->sid
     * @return bool true в случе успеха, false в случае неудачи
     */
    protected function getSID()
    {
        $ch = curl_init();
        curl_setopt_array($ch, array(
            CURLOPT_URL => sprintf(self::$base, $this->host, $this->port),
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
        curl_close($ch);
        preg_match('|.*\r\n(X-Transmission-Session-Id: .*?)(\r\n.*)|', $response, $matches);
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
     *
     * @param $fields
     * @param array $options
     * @return bool|mixed|string
     */
    private function makeRequest($fields, $options = array())
    {
        $ch = curl_init();
        curl_setopt_array($ch, array(
            CURLOPT_URL => sprintf(self::$base, $this->host, $this->port),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERPWD => $this->login . ':' . $this->password,
            CURLOPT_HTTPHEADER => array($this->sid),
            CURLOPT_POSTFIELDS => json_encode($fields),
        ));
        curl_setopt_array($ch, $options);
        $i = 1; // номер попытки
        $n = 3; // количество попыток
        while (true) {
            $response = curl_exec($ch);
            if ($response === false) {
                Log::append('CURL ошибка: ' . curl_error($ch));
                curl_close($ch);
                return false;
            }
            $response = json_decode($response, true);
            if ($response['result'] != 'success') {
                if (empty($response['result']) && $i <= $n) {
                    Log::append('Повторная попытка ' . $i . '/' . $n . ' выполнить запрос.');
                    sleep(10);
                    $i++;
                    continue;
                }
                $error = empty($response['result']) ? 'Неизвестная ошибка' : $response['result'];
                Log::append('Error: ' . $error);
                curl_close($ch);
                return false;
            }
            curl_close($ch);
            return $response;
        }
    }

    public function getTorrents()
    {
        $request = array(
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
        $data = $this->makeRequest($request);
        if (empty($data['arguments']['torrents'])) {
            return false;
        }
        foreach ($data['arguments']['torrents'] as $torrent) {
            if (empty($torrent['error'])) {
                if ($torrent['percentDone'] == 1) {
                    $torrentStatus = $torrent['status'] == 0 ? -1 : 1;
                } else {
                    $torrentStatus = 0;
                }
            } else {
                $torrentStatus = -2;
            }
            $hash = strtoupper($torrent['hashString']);
            $torrents[$hash] = $torrentStatus;
        }
        return isset($torrents) ? $torrents : false;
    }

    public function addTorrent($torrentFilePath, $savePath = '')
    {
        $request = array(
            'method' => 'torrent-add',
            'arguments' => array(
                'metainfo' => base64_encode(file_get_contents($torrentFilePath)),
                'paused' => false,
            ),
        );
        if (!empty($savePath)) {
            $request['arguments']['download-dir'] = $savePath;
        }
        $data = $this->makeRequest($request);
        if (empty($data['arguments'])) {
            return false;
        }
        // if ( ! empty( $data['arguments']['torrent-added'] ) ) {
        //     $hash = $data['arguments']['torrent-added']['hashString']
        //     $success[] = strtoupper( $hash );
        // }
        if (!empty($data['arguments']['torrent-duplicate'])) {
            $hash = $data['arguments']['torrent-duplicate']['hashString'];
            Log::append('Warning: Эта раздача уже раздаётся в торрент-клиенте (' . $hash . ').');
        }
        // return $success;
    }

    public function setLabel($hashes, $label = '')
    {
        return 'Торрент-клиент не поддерживает установку меток.';
    }

    public function startTorrents($hashes, $force = false)
    {
        $method = $force ? 'torrent-start-now' : 'torrent-start';
        $request = array(
            'method' => $method,
            'arguments' => array(
                'ids' => $hashes,
            ),
        );
        $data = $this->makeRequest($request);
    }

    public function stopTorrents($hashes)
    {
        $request = array(
            'method' => 'torrent-stop',
            'arguments' => array(
                'ids' => $hashes,
            ),
        );
        $data = $this->makeRequest($request);
    }

    public function recheckTorrents($hashes)
    {
        $request = array(
            'method' => 'torrent-verify',
            'arguments' => array(
                'ids' => $hashes,
            ),
        );
        $data = $this->makeRequest($request);
    }

    public function removeTorrents($hashes, $deleteLocalData = false)
    {
        $request = array(
            'method' => 'torrent-remove',
            'arguments' => array(
                'ids' => $hashes,
                'delete-local-data' => $deleteLocalData,
            ),
        );
        $data = $this->makeRequest($request);
    }
}
