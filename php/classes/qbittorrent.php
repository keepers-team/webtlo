<?php

use KeepersTeam\Webtlo\Module\Topics;

/**
 * Class Qbittorrent
 * Supported by qBittorrent 4.1 and later
 * https://github.com/qbittorrent/qBittorrent/wiki/WebUI-API-(qBittorrent-4.1)
 */
class Qbittorrent extends TorrentClient
{
    protected static $base = '%s://%s:%s/%s';

    /** Пауза при добавлении раздач в клиент, мс. */
    protected int $torrentAddingSleep = 100000;

    /** Позволяет ли клиент присваивать раздаче категорию при добавлении. */
    protected bool $categoryAddingAllowed = true;

    private $categories;
    private $responseHttpCode;
    private $errorStates = ['error', 'missingFiles', 'unknown'];

    /**
     * получение идентификатора сессии и запись его в $this->sid
     * @return bool true в случе успеха, false в случае неудачи
     */
    protected function getSID()
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => sprintf(self::$base, $this->scheme, $this->host, $this->port, 'api/v2/auth/login'),
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
    private function makeRequest($url, $fields = '', $options = [])
    {
        $this->responseHttpCode = null;
        curl_setopt_array($this->ch, [
            CURLOPT_URL => sprintf(self::$base, $this->scheme, $this->host, $this->port, $url),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_COOKIE => $this->sid,
            CURLOPT_POSTFIELDS => $fields,
        ]);
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

    public function getAllTorrents(array $filter = [])
    {
        /** Получить просто список раздач без дополнительных действий */
        $simpleRun = $filter['simple'] ?? false;

        Timers::start('torrents_info');
        $response = $this->makeRequest('api/v2/torrents/info');
        if ($response === false) {
            return false;
        }
        Timers::stash('torrents_info');

        $torrents = [];
        Timers::start('processing');
        foreach ($response as $torrent) {
            $clientHash    = $torrent['hash'];
            $torrentHash   = strtoupper($torrent['infohash_v1'] ?? $torrent['hash']);
            $torrentPaused = str_starts_with($torrent['state'], 'paused') ? 1 : 0;
            $torrentError  = in_array($torrent['state'], $this->errorStates) ? 1 : 0;

            $torrents[$torrentHash] = [
                'topic_id'    => null,
                'comment'     => '',
                'done'        => $torrent['progress'],
                'error'       => $torrentError,
                'name'        => $torrent['name'],
                'paused'      => $torrentPaused,
                'time_added'  => $torrent['added_on'],
                'total_size'  => $torrent['total_size'],
                'client_hash' => $clientHash,
                'tracker_error' => ''
            ];

            if (!$simpleRun) {
                // Получение ошибок трекера.
                // Для раздач на паузе, нет рабочих трекеров и смысла их проверять тоже нет.
                if (!$torrentPaused && empty($torrent['tracker'])) {
                    $torrentTrackers = $this->getTrackers($clientHash);
                    if ($torrentTrackers !== false) {
                        foreach ($torrentTrackers as $torrentTracker) {
                            if ($torrentTracker['status'] == 4) {
                                $torrents[$torrentHash]['tracker_error'] = $torrentTracker['msg'];
                                break;
                            }
                        }
                    }
                    unset($torrentTrackers);
                }
            }

            unset($clientHash, $torrentHash, $torrentPaused, $torrentError);
        }
        Timers::stash('processing');

        if (!$simpleRun) {
            Timers::start('db_search');
            // Пробуем найти раздачи в локальной БД.
            $topics = Topics::getTopicsIdsByHashes(array_keys($torrents));
            if (count($topics)) {
                $torrents = array_replace_recursive($torrents, $topics);
            }
            unset($topics);
            Timers::stash('db_search');

            // Для раздач, у которых нет ид раздачи, вытаскиваем комментарий
            $emptyTopics = array_filter($torrents, fn ($el) => empty($el['topic_id']));
            if (count($emptyTopics)) {
                Timers::start('comment_search');
                foreach ($emptyTopics as $torrentHash => $torrent) {
                    // получение ссылки на раздачу
                    $properties = $this->getProperties($torrent['client_hash']);
                    if ($properties !== false) {
                        $torrents[$torrentHash]['topic_id'] = $this->getTorrentTopicId($properties['comment']);
                        $torrents[$torrentHash]['comment']  = $properties['comment'];
                    }
                    unset($torrentHash, $torrent, $properties);
                }
                Timers::stash('comment_search');
            }
            unset($emptyTopics);
        }

        Log::append(json_encode(Timers::getStash(), true));

        return $torrents;
    }

    public function getTrackers($torrentHash)
    {
        $torrent_trackers = [];
        $trackers = $this->makeRequest(
            'api/v2/torrents/trackers',
            ['hash' => strtolower($torrentHash)]
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
            ['hash' => strtolower($torrentHash)]
        );
        if ($properties === false) {
            return false;
        }
        return $properties;
    }

    public function addTorrent(string $torrentFilePath, string $savePath = '', string $label = '')
    {
        if (version_compare(PHP_VERSION, '5.5.0') >= 0) {
            $torrentFile = new CurlFile($torrentFilePath, 'application/x-bittorrent');
        } else {
            $torrentFile = '@' . $torrentFilePath;
        }
        $fields = [
            'torrents' => $torrentFile,
            'savepath' => $savePath,
        ];
        if (!empty($label)) {
            $this->checkLabelExists($label);
            $fields['category'] = $label;
        }
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
        $this->checkLabelExists($labelName);
        $fields = http_build_query(
            [
                'hashes' => implode('|', array_map('strtolower', $torrentHashes)),
                'category' => $labelName
            ],
            '',
            '&',
            PHP_QUERY_RFC3986
        );
        $response = $this->makeRequest('api/v2/torrents/setCategory', $fields);
        if (
            $response === false
            && $this->responseHttpCode == 409
        ) {
            Log::append('Error: Category name does not exist');
        }
        return $response;
    }

    private function checkLabelExists(string $labelName = ''): void
    {
        if (empty($labelName)) {
            return;
        }

        if ($this->categories === null) {
            $this->categories = $this->makeRequest('api/v2/torrents/categories');
            if ($this->categories === false) {
                return;
            }
        }
        if (
            is_array($this->categories)
            && !array_key_exists($labelName, $this->categories)
        ) {
            $this->createCategory($labelName);
            $this->categories[$labelName] = [];
        }
    }

    public function createCategory($categoryName, $savePath = '')
    {
        $fields = [
            'category' => $categoryName,
            'savePath' => $savePath
        ];
        $response = $this->makeRequest('api/v2/torrents/createCategory', $fields);
        if ($response === false) {
            if ($this->responseHttpCode == 400) {
                Log::append('Error: Category name is empty');
            } elseif ($this->responseHttpCode == 409) {
                Log::append('Error: Category name is invalid');
            }
        }
        return $response;
    }

    public function startTorrents($torrentHashes, $forceStart = false)
    {
        $fields = ['hashes' => implode('|', array_map('strtolower', $torrentHashes))];
        return $this->makeRequest('api/v2/torrents/resume', $fields);
    }

    public function stopTorrents($torrentHashes)
    {
        $fields = ['hashes' => implode('|', array_map('strtolower', $torrentHashes))];
        return $this->makeRequest('api/v2/torrents/pause', $fields);
    }

    public function removeTorrents($torrentHashes, $deleteFiles = false)
    {
        $deleteFiles = $deleteFiles ? 'true' : 'false';
        $fields = [
            'hashes' => implode('|', array_map('strtolower', $torrentHashes)),
            'deleteFiles' => $deleteFiles
        ];
        return $this->makeRequest('api/v2/torrents/delete', $fields);
    }

    public function recheckTorrents($torrentHashes)
    {
        $fields = ['hashes' => implode('|', array_map('strtolower', $torrentHashes))];
        return $this->makeRequest('/api/v2/torrents/recheck', $fields);
    }
}
