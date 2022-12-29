<?php

namespace KeepersTeam\Webtlo\Clients;

use CURLFile;

/**
 * Class Utorrent
 * Supported by uTorrent 1.8.2 and later
 */
class Utorrent extends TorrentClient
{
    protected static string $base = '%s://%s:%s/gui/%s';

    private string $guid;
    private string $token;

    /**
     * @inheritdoc
     */
    protected function getSID(): bool
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => sprintf(self::$base, $this->scheme, $this->host, $this->port, 'token.html'),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERPWD => $this->login . ':' . $this->password,
            CURLOPT_HEADER => true,
            CURLOPT_CONNECTTIMEOUT => 20,
            CURLOPT_TIMEOUT => 20
        ]);
        $response = curl_exec($ch);
        if ($response === false) {
            $this->logger->error("Failed to obtain session identifier", ['error' => curl_error($ch)]);
            return false;
        }
        $responseInfo = curl_getinfo($ch);
        curl_close($ch);
        $headers = substr($response, 0, $responseInfo['header_size']);
        preg_match('|Set-Cookie: GUID=([^;]+);|i', $headers, $headersMatches);
        if (!empty($headersMatches)) {
            $this->guid = $headersMatches[1];
        }
        preg_match('|<div id=\'token\'.+>(.*)</div>|', $response, $responseMatches);
        if (!empty($responseMatches)) {
            $this->token = $responseMatches[1];
            return true;
        }
        $this->logger->error('Failed to authenticate', ['response' => $response]);
        return false;
    }

    /**
     * выполнение запроса к торрент-клиенту
     * @param string $url
     * @param array|string $fields
     * @return array|false
     */
    private function makeRequest(string $url, array|string $fields = ''): array|false
    {
        $options = [];
        $url = preg_replace('|^\?|', '?token=' . $this->token . '&', $url);
        curl_setopt_array($this->ch, [
            CURLOPT_URL => sprintf(self::$base, $this->scheme, $this->host, $this->port, $url),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERPWD => $this->login . ':' . $this->password,
            CURLOPT_COOKIE => 'GUID=' . $this->guid,
            CURLOPT_POSTFIELDS => $fields,
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
            $response = json_decode($response, true);
            if ($response === null) {
                $this->logger->error("Failed to decode response", ['error' => json_last_error_msg()]);
                return false;
            }
            if (isset($response['error'])) {
                $this->logger->error("Malformed response", ['response' => $response]);
                return false;
            }
            return $response;
        }
    }

    /**
     * @inheritdoc
     */
    public function getAllTorrents(): array|false
    {
        $response = $this->makeRequest('?list=1');
        if ($response === false) {
            return false;
        }
        $torrents = [];
        foreach ($response['torrents'] as $torrent) {
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
            $torrentState = decbin($torrent[1]);
            $torrentHash = strtoupper($torrent[0]);
            $torrentPaused = $torrentState[2] || !$torrentState[7] ? 1 : 0;
            $torrents[$torrentHash] = [
                'comment' => '',
                'done' => $torrent[4] / 1000,
                'error' => $torrentState[3],
                'name' => $torrent[2],
                'paused' => $torrentPaused,
                'time_added' => '',
                'total_size' => $torrent[3],
                'tracker_error' => ''
            ];
        }
        return $torrents;
    }

    /**
     * @inheritdoc
     */
    public function addTorrent(string $torrentFilePath, string $savePath = ''): bool
    {
        $this->setSetting('dir_active_download_flag', true);
        if (!empty($savePath)) {
            $this->setSetting('dir_active_download', urlencode($savePath));
            sleep(1);
        }
        $torrentFile = new CurlFile($torrentFilePath, 'application/x-bittorrent');
        return $this->makeRequest('?action=add-file', ['torrent_file' => $torrentFile]);
    }

    /**
     * изменение свойств торрента
     * @param $hash
     * @param $property
     * @param $value
     */
    private function setProperties(array $hashes, string $property, string $value): array|false
    {
        $request = preg_replace('|^(.*)$|', 'hash=$0&s=' . $property . '&v=' . urlencode($value), $hashes);
        $request = implode('&', $request);
        return $this->makeRequest('?action=setprops&' . $request);
    }

    /**
     * изменение настроек
     * @param string $setting
     * @param string $value
     * @return array|false
     */
    private function setSetting(string $setting, string $value): array|false
    {
        return $this->makeRequest('?action=setsetting&s=' . $setting . '&v=' . $value);
    }

    /**
     * "склеивание" параметров в строку
     * @param string $glue
     * @param array|string $params
     * @return string
     */
    private function implodeParams(string $glue, array|string $params): string
    {
        $params = is_array($params) ? $params : [$params];
        return $glue . implode($glue, $params);
    }

    /**
     * @inheritdoc
     */
    public function setLabel(array $torrentHashes, string $labelName = ''): bool
    {
        return $this->setProperties($torrentHashes, 'label', $labelName);
    }

    /**
     * @inheritdoc
     */
    public function startTorrents(array $torrentHashes, bool $forceStart = false): bool
    {
        $action = $forceStart ? 'forcestart' : 'start';
        return $this->makeRequest('?action=' . $action . $this->implodeParams('&hash=', $torrentHashes));
    }

    /**
     * @inheritdoc
     */
    public function recheckTorrents(array $torrentHashes): bool
    {
        return $this->makeRequest('?action=recheck' . $this->implodeParams('&hash=', $torrentHashes));
    }

    /**
     * @inheritdoc
     */
    public function stopTorrents(array $torrentHashes): bool
    {
        return $this->makeRequest('?action=stop' . $this->implodeParams('&hash=', $torrentHashes));
    }

    /**
     * @inheritdoc
     */
    public function removeTorrents(array $torrentHashes, bool $deleteFiles = false): bool
    {
        $action = $deleteFiles ? 'removedata' : 'remove';
        return $this->makeRequest('?action=' . $action . $this->implodeParams('&hash=', $torrentHashes));
    }
}
