<?php

/**
 * Class Rtorrent
 * Supported by rTorrent 0.9.x and later
 * Added by: advers222@ya.ru
 */
class Rtorrent extends TorrentClient
{
    // предлагается вводить ссылку полностью в веб интерфейсе
    // потому что при нескольких клиентах вполне может меняться последняя часть (RPC2)
    // http://localhost/RPC2
    protected static $base = 'http://%s';

    /**
     * получение идентификатора сессии и запись его в $this->sid
     * @return bool true в случе успеха, false в случае неудачи
     */
    protected function getSID()
    {
        return $this->makeRequest('get_name') ? true : false;
    }

    /**
     * выполнение запроса
     * @param $cmd
     * @param null $param
     * @return bool|mixed
     */
    public function makeRequest($command, $params = null)
    {
        // XML RPC запрос
        $request = xmlrpc_encode_request($command, $params);
        $header = array(
            'Content-type: text/xml',
            'Content-length: ' . strlen($request)
        );
        $ch = curl_init();
        curl_setopt_array($ch, array(
            CURLOPT_URL => sprintf(self::$base, $this->host),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $header,
            CURLOPT_POSTFIELDS => $request,
        ));
        $response = curl_exec($ch);
        if (curl_errno($ch)) {
            Log::append('CURL ошибка: ' . curl_error($ch));
            return false;
        }
        curl_close($ch);
        // Грязный хак для приведения ответа XML RPC к понятному для PHP
        return xmlrpc_decode(str_replace('i8>', 'i4>', $response));
    }

    public function getTorrents()
    {
        $data = $this->makeRequest(
            'd.multicall',
            array(
                'main',
                'd.get_hash=',
                'd.get_state=',
                'd.get_complete=',
            )
        );
        if (empty($data)) {
            return false;
        }
        // ответ в формате array(HASH, STATE active/stopped, COMPLETED)
        foreach ($data as $torrent) {
            // $status:
            //        0 - Не скачано
            //        1 - Скачано и активно
            //        -1 - Скачано и остановлено
            if ($torrent[2]) {
                $status = $torrent[1] ? 1 : -1;
            } else {
                $status = 0;
            }
            $torrents[$torrent[0]] = $status;
        }
        return isset($torrents) ? $torrents : array();
    }

    public function addTorrent($torrentFilePath, $savePath = '', $label = '')
    {
        $result = $this->makeRequest('load_start', $torrentFilePath); // === false
    }

    public function setLabel($hashes, $label = '')
    {
        foreach ($hashes as $hash) {
            $result = $this->makeRequest('d.set_custom1', array($hash, $label)); // === false
        }
    }

    public function startTorrents($hashes, $force = false)
    {
        foreach ($hashes as $hash) {
            $result = $this->makeRequest('d.start', $hash); // === false
        }
    }

    /**
     * пауза раздач (unused)
     * @param $hashes
     */
    public function pauseTorrents($hashes)
    {
        foreach ($hashes as $hash) {
            $result = $this->makeRequest('d.pause', $hash); // === false
        }
    }

    public function recheckTorrents($hashes)
    {
        foreach ($hashes as $hash) {
            $result = $this->makeRequest('d.check_hash', $hash); // === false
        }
    }

    public function stopTorrents($hashes)
    {
        foreach ($hashes as $hash) {
            $result = $this->makeRequest('d.stop', $hash); // === false
        }
    }

    public function removeTorrents($hashes, $data = false)
    {
        Log::append('Удаление раздачи не реализовано.');
    }
}
