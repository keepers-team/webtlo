<?php

namespace KeepersTeam\Webtlo\Clients;

use Exception;
use SimpleXMLElement;

/**
 * Class Ktorrent
 * Supported by KTorrent 4.3.1
 */
class Ktorrent extends TorrentClient
{
    protected static string $base = '%s://%s:%s/%s';

    protected string $challenge = '';

    /**
     * @inheritdoc
     */
    public function isOnline(): bool
    {
        return $this->getChallenge();
    }

    /**
     * получение challenge
     * @return bool
     */
    protected function getChallenge(): bool
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => sprintf(self::$base, $this->scheme, $this->host, $this->port, 'login/challenge.xml'),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POSTFIELDS => http_build_query(
                [
                    'username' => $this->login,
                    'password' => $this->password,
                ]
            ),
            CURLOPT_HEADER => true,
            CURLOPT_CONNECTTIMEOUT => 20,
            CURLOPT_TIMEOUT => 20
        ]);
        $response = curl_exec($ch);
        if ($response === false) {
            $this->logger->error("Failed to obtain challenge identifier", ['error' => curl_error($ch)]);
            return false;
        }
        curl_close($ch);
        preg_match('|<challenge>(.*)</challenge>|si', $response, $matches);
        if (!empty($matches)) {
            $this->challenge = sha1($matches[1] . $this->password);
            return $this->getSID();
        }
        $this->logger->error('Failed to authenticate', ['response' => $response]);
        return false;
    }

    /**
     * @inheritdoc
     */
    protected function getSID(): bool
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => sprintf(self::$base, $this->scheme, $this->host, $this->port, 'login?page=interface.html'),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POSTFIELDS => http_build_query(
                [
                    'username' => $this->login,
                    'challenge' => $this->challenge,
                    'Login' => 'Sign in',
                ]
            ),
            CURLOPT_HEADER => true,
            CURLOPT_CONNECTTIMEOUT => 20,
            CURLOPT_TIMEOUT => 20
        ]);
        $response = curl_exec($ch);
        if ($response === false) {
            $this->logger->error("Failed to obtain session identifier", ['error' => curl_error($ch)]);
            return false;
        }
        curl_close($ch);
        preg_match('|Set-Cookie: ([^;]+)|i', $response, $matches);
        if (!empty($matches)) {
            $this->sid = $matches[1];
            return true;
        }
        $this->logger->error('Failed to authenticate', ['response' => $response]);
        return false;
    }

    /**
     * выполнение запроса
     * @param string $url
     * @param array $options
     * @return string|false
     */
    private function makeRequest(string $url, array $options = []): string|false
    {
        curl_setopt_array($this->ch, [
            CURLOPT_URL => sprintf(self::$base, $this->scheme, $this->host, $this->port, $url),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_COOKIE => $this->sid,
        ]);
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
                $this->logger->error("Failed to make request", ['error' => curl_error($this->ch)]);
                return false;
            }
            return $responseHttpCode == 200 ? $response : false;
        }
    }

    private function getTorrentsData(): array|false
    {
        $response = $this->makeRequest('data/torrents.xml');
        if ($response === false) {
            return false;
        }
        try {
            $response = new SimpleXMLElement($response);
        } catch (Exception $e) {
            $this->logger->error("Malformed xml", ['xml' => $response, 'error' => $e]);
            return false;
        }
        $response = json_decode(json_encode($response), true);
        // вывод отличается, если в клиенте только одна раздача
        if (
            isset($response['torrent'])
            && !is_array(array_shift($response['torrent']))
        ) {
            $response['torrent'] = [$response['torrent']];
        }
        return $response;
    }

    /**
     * @inheritdoc
     */
    public function getAllTorrents(): array|false
    {
        $response = $this->getTorrentsData();
        if ($response === false) {
            return false;
        }
        $torrents = [];
        if (isset($response['torrent'])) {
            foreach ($response['torrent'] as $torrent) {
                if ($torrent['status'] != 'Ошибка') {
                    if ($torrent['percentage'] == 100) {
                        $torrentStatus = $torrent['status'] == 'Пауза' ? -1 : 1;
                    } else {
                        $torrentStatus = 0;
                    }
                } else {
                    $torrentStatus = -2;
                }
                $torrentHash = strtoupper($torrent['info_hash']);
                $torrents[$torrentHash] = $torrentStatus;
            }
        }
        return $torrents;
    }

    /**
     * @inheritdoc
     */
    public function addTorrent(string $torrentFilePath, string $savePath = ''): bool
    {
        /**
         * https://cgit.kde.org/ktorrent.git/tree/plugins/webinterface/torrentposthandler.cpp#n55
         * клиент не терпит две пустых строки между заголовком запроса и его телом
         * библиотека cURL как раз формирует двойной отступ
         */
        $torrentFile = file_get_contents($torrentFilePath);
        if ($torrentFile === false) {
            $this->logger->error("Failed to upload file", ['filename' => basename($torrentFilePath)]);
            return false;
        }
        $boundary = uniqid();
        $_BR_ = chr(13) . chr(10);
        $content = '------' . $boundary . $_BR_
            . 'Content-Disposition: form-data; name="load_torrent"; filename="' . basename($torrentFile) . '"' . $_BR_
            . 'Content-Type: application/x-bittorrent' . $_BR_
            . $_BR_
            . $torrentFile . $_BR_
            . '------' . $boundary . $_BR_
            . 'Content-Disposition: form-data; name="Upload Torrent"' . $_BR_
            . $_BR_
            . 'Upload Torrent' . $_BR_
            . '------' . $boundary . '--';
        $header = [
            'Content-Type: multipart/form-data; boundary=------' . $boundary . $_BR_
            . 'Content-Length: ' . strlen($content) . $_BR_
            . 'Cookie: ' . $this->sid
        ];
        $context = stream_context_create(
            [
                'http' => [
                    'method' => 'POST',
                    'header' => $header,
                    'content' => $content
                ]
            ]
        );
        return file_get_contents(
            sprintf(self::$base, $this->scheme, $this->host, $this->port, 'torrent/load?page=interface.html'),
            false,
            $context
        );
    }

    /**
     * @inheritdoc
     */
    public function setLabel(array $torrentHashes, string $labelName = ''): bool
    {
        $this->logger->warning("Labels are not supported in this client");
        return false;
    }

    /**
     * @inheritdoc
     */
    public function startTorrents(array $torrentHashes, bool $forceStart = false): bool
    {
        $response = $this->getTorrentsData();
        if ($response === false) {
            return false;
        }
        $torrents = array_flip(array_column($response['torrent'], 'info_hash'));
        unset($response);
        $result = null;
        foreach ($torrentHashes as $torrentHash) {
            $torrentHash = strtolower($torrentHash);
            if (isset($torrents[$torrentHash])) {
                $response = $this->makeRequest('action?start=' . $torrents[$torrentHash]);
                if ($response === false) {
                    $result = false;
                }
            }
        }
        return $result;
    }

    /**
     * @inheritdoc
     */
    public function stopTorrents(array $torrentHashes): bool
    {
        $response = $this->getTorrentsData();
        if ($response === false) {
            return false;
        }
        $torrents = array_flip(array_column($response['torrent'], 'info_hash'));
        unset($response);
        $result = null;
        foreach ($torrentHashes as $torrentHash) {
            $torrentHash = strtolower($torrentHash);
            if (isset($torrents[$torrentHash])) {
                $response = $this->makeRequest('action?stop=' . $torrents[$torrentHash]);
                if ($response === false) {
                    $result = false;
                }
            }
        }
        return $result;
    }

    /**
     * @inheritdoc
     */
    public function removeTorrents(array $torrentHashes, bool $deleteFiles = false): bool
    {
        $response = $this->getTorrentsData();
        if ($response === false) {
            return false;
        }
        $torrents = array_flip(array_column($response['torrent'], 'info_hash'));
        unset($response);
        $result = null;
        foreach ($torrentHashes as $torrentHash) {
            $torrentHash = strtolower($torrentHash);
            if (isset($torrents[$torrentHash])) {
                $response = $this->makeRequest('action?remove=' . $torrents[$torrentHash]);
                if ($response === false) {
                    $result = false;
                }
            }
        }
        return $result;
    }

    /**
     * @inheritdoc
     */
    public function recheckTorrents(array $torrentHashes): bool
    {
        $this->logger->warning("Recheck is not supported in this client");
        return false;
    }
}
