<?php

/**
 * Class Rtorrent
 * Supported by rTorrent 0.9.7 and later
 */
class Rtorrent extends TorrentClient
{
    protected static $base = '%s://%s/RPC2';

    /**
     * получение имени сеанса
     * @return bool true в случе успеха, false в случае неудачи
     */
    protected function getSID()
    {
        return $this->makeRequest('session.name') ? true : false;
    }

    /**
     * выполнение запроса
     * @param $command
     * @param $params
     * @return bool|mixed
     */
    public function makeRequest($command, $params = '')
    {
        $request = xmlrpc_encode_request($command, $params, array('escaping' => 'markup', 'encoding' => 'UTF-8'));
        $header = array(
            'Content-type: text/xml',
            'Content-length: ' . strlen($request)
        );
        curl_setopt_array($this->ch, array(
            CURLOPT_URL => sprintf(self::$base, $this->scheme, $this->host),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $header,
            CURLOPT_POSTFIELDS => $request,
        ));
        $maxNumberTry = 3;
        $connectionNumberTry = 1;
        while (true) {
            $response = curl_exec($this->ch);
            $responseHttpCode = curl_getinfo($this->ch, CURLINFO_HTTP_CODE);
            if (curl_errno($this->ch)) {
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
            $response = xmlrpc_decode(str_replace('i8>', 'i4>', $response));
            if (is_array($response)) {
                foreach ($response as $keyName => $responseData) {
                    if (is_array($responseData)) {
                        if (array_key_exists('faultCode', $responseData)) {
                            $faultString = $responseData['faultString'];
                            break;
                        }
                    } elseif ($keyName == 'faultString') {
                        $faultString = $responseData;
                        break;
                    }
                }
            }
            if (isset($faultString)) {
                Log::append('Error: ' . $faultString);
                return false;
            }
            // return 0 on success
            return $response;
        }
    }

    public function getTorrents()
    {
        $response = $this->makeRequest(
            'd.multicall2',
            array('', 'main', 'd.hash=', 'd.state=', 'd.complete=', 'd.message=')
        );
        if ($response === false) {
            return false;
        }
        $torrents = array();
        foreach ($response as $torrent) {
            if (empty($torrent[3])) {
                if ($torrent[2]) {
                    $torrentStatus = $torrent[1] ? 1 : -1;
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
        $response = $this->makeRequest(
            'd.multicall2',
            array(
                '',
                'main',
                'd.complete=',
                'd.custom2=',
                'd.hash=',
                'd.message=',
                'd.name=',
                'd.size_bytes=',
                'd.state=',
                'd.timestamp.started='
            )
        );
        if ($response === false) {
            return false;
        }
        $torrents = array();
        foreach ($response as $torrent) {
            $torrentHash = strtoupper($torrent[2]);
            $torrentComment = str_replace('VRS24mrker', '', rawurldecode($torrent[1]));
            $torrentError = !empty($torrent[3]) ? 1 : 0;
            $torrentTrackerError = '';
            preg_match('/Tracker: \[([^"]*"*([^"]*)"*)\]/', $torrent[3], $matches);
            if (!empty($matches)) {
                $torrentTrackerError = empty($matches[2]) ? $matches[1] : $matches[2];
            }
            $torrents[$torrentHash] = array(
                'comment' => $torrentComment,
                'done' => $torrent[0],
                'error' => $torrentError,
                'name' => $torrent[4],
                'paused' => (int) !$torrent[6],
                'time_added' => $torrent[7],
                'total_size' => $torrent[5],
                'tracker_error' => $torrentTrackerError
            );
        }
        return $torrents;
    }

    public function addTorrent($torrentFilePath, $savePath = '')
    {
        $makeDirectory = array('', 'mkdir', '-p', '--', $savePath);
        if (empty($savePath)) {
            $savePath = '$directory.default=';
            $makeDirectory = array('', 'true');
        }
        $torrentFile = file_get_contents($torrentFilePath, false, stream_context_create());
        if ($torrentFile === false) {
            Log::append('Error: не удалось загрузить файл ' . basename($torrentFilePath));
            return false;
        }
        preg_match('|publisher-url[0-9]*:(https?\:\/\/[^\?]*\?t=[0-9]*)|', $torrentFile, $matches);
        if (isset($matches[1])) {
            $torrentComment = 'VRS24mrker' . rawurlencode($matches[1]);
        } else {
            $torrentComment = 'VRS24mrker';
        }
        xmlrpc_set_type($torrentFile, 'base64');
        return $this->makeRequest(
            'system.multicall',
            array(
                array(
                    array(
                        'methodName' => 'execute2',
                        'params' => $makeDirectory
                    ),
                    array(
                        'methodName' => 'load.raw_start',
                        'params' => array(
                            '',
                            $torrentFile,
                            'd.delete_tied=',
                            'd.directory.set=' . addcslashes($savePath, ' '),
                            'd.custom2.set=' . $torrentComment
                        )
                    )
                )
            )
        );
    }

    public function setLabel($torrentHashes, $labelName = '')
    {
        if (empty($labelName)) {
            return false;
        }
        $result = null;
        $labelName = rawurlencode($labelName);
        foreach ($torrentHashes as $torrentHash) {
            $response = $this->makeRequest('d.custom1.set', array($torrentHash, $labelName));
            if ($response === false) {
                $result = false;
            }
        }
        return $result;
    }

    public function startTorrents($torrentHashes, $forceStart = false)
    {
        $result = null;
        foreach ($torrentHashes as $torrentHash) {
            $response = $this->makeRequest('d.start', $torrentHash);
            if ($response === false) {
                $result = false;
            }
        }
        return $result;
    }

    public function stopTorrents($torrentHashes)
    {
        $result = null;
        foreach ($torrentHashes as $torrentHash) {
            $response = $this->makeRequest('d.stop', $torrentHash);
            if ($response === false) {
                $result = false;
            }
        }
        return $result;
    }

    public function removeTorrents($torrentHashes, $deleteFiles = false)
    {
        $result = null;
        foreach ($torrentHashes as $torrentHash) {
            $executeDeleteFiles = array('', 'true');
            if ($deleteFiles) {
                $dataPath = $this->makeRequest('d.data_path', $torrentHash);
                if (!empty($dataPath)) {
                    $executeDeleteFiles = array('', 'rm', '-rf', '--', $dataPath);
                }
            }
            $response = $this->makeRequest(
                'system.multicall',
                array(
                    array(
                        array(
                            'methodName' => 'd.custom5.set',
                            'params' => array($torrentHash, '1'),
                        ),
                        array(
                            'methodName' => 'd.delete_tied',
                            'params' => array($torrentHash),
                        ),
                        array(
                            'methodName' => 'd.erase',
                            'params' => array($torrentHash)
                        ),
                        array(
                            'methodName' => 'execute2',
                            'params' => $executeDeleteFiles
                        )
                    )
                )
            );
            if ($response === false) {
                $result = false;
            }
        }
        return $result;
    }

    public function recheckTorrents($torrentHashes)
    {
        $result = null;
        foreach ($torrentHashes as $torrentHash) {
            $response = $this->makeRequest('d.check_hash', $torrentHash);
            if ($response === false) {
                $result = false;
            }
        }
        return $result;
    }
}
