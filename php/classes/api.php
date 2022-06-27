<?php

/**
 * Class Api
 * Класс для работы с API форума
 */
class Api
{
    /**
     * @var CurlHandle
     */
    private $ch;

    /**
     * @var string
     */
    private $formatURL;

    /**
     * @var int
     */
    private $numberRequest = 0;

    /**
     * @var int
     */
    private $limitInRequest;

    /**
     * default constructor
     * @param string $addressApi
     * @param string $userKeyApi
     */
    public function __construct($addressApi, $userKeyApi = '')
    {
        $this->ch = curl_init();
        curl_setopt_array($this->ch, array(
            CURLOPT_RETURNTRANSFER => 1,
            CURLOPT_ENCODING => 'gzip',
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 6.1) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/55.0.2883.87 Safari/537.36',
        ));
        curl_setopt_array($this->ch, Proxy::$proxy['api']);
        Log::append('Используется зеркало для API: ' . $addressApi);
        $this->formatURL = $addressApi . '/v1/%s?api_key=' . $userKeyApi . '%s';
        $this->getLimitInRequest();
    }

    /**
     * выполнение запроса к API
     * @param string $request
     * @param array|string $params
     * @return bool|mixed|array
     */
    private function makeRequest($request, $params = '')
    {
        // таймаут запросов
        $maxNumberRequest = 3;
        if ($this->numberRequest == $maxNumberRequest) {
            $this->numberRequest = 0;
            sleep(1);
        }
        $this->numberRequest++;
        // повторные попытки
        $connectionNumberTry = 1;
        $responseNumberTry = 1;
        $maxNumberTry = 3;
        // выполнение запроса
        $params = $this->implodeParams('&', $params);
        $url = sprintf($this->formatURL, $request, $params);
        curl_setopt($this->ch, CURLOPT_URL, $url);
        while (true) {
            $response = curl_exec($this->ch);
            if ($response === false) {
                $responseHttpCode = curl_getinfo($this->ch, CURLINFO_HTTP_CODE);
                if (
                    $responseHttpCode < 300
                    && $connectionNumberTry <= $maxNumberTry
                ) {
                    Log::append('Повторная попытка ' . $connectionNumberTry . '/' . $maxNumberTry . ' подключения');
                    sleep(5);
                    $connectionNumberTry++;
                    continue;
                }
                throw new Exception('CURL ошибка: ' . curl_error($this->ch) . ' [' . $responseHttpCode . ']');
            }
            $response = json_decode($response, true);
            if (isset($response['error'])) {
                if (
                    $response['error']['code'] == '503'
                    && $responseNumberTry <= $maxNumberTry
                ) {
                    Log::append('Повторная попытка ' . $responseNumberTry . '/' . $maxNumberTry . 'получить данные');
                    sleep(20);
                    $responseNumberTry++;
                    continue;
                }
                if ($response['error']['code'] == '404') {
                    break;
                }
                throw new Exception('API ошибка: ' . $response['error']['text']);
            }
            break;
        }
        return isset($response['result']) ? $response : false;
    }

    /**
     * "склеивание" параметров в строку
     * @param string $glue
     * @param array|string $params
     * @return string
     */
    private function implodeParams($glue, $params)
    {
        $params = is_array($params) ? $params : array($params);
        return $glue . implode($glue, $params);
    }

    /**
     * ограничение на количество запрашиваемых данных
     */
    private function getLimitInRequest()
    {
        $response = $this->makeRequest('get_limit');
        $this->limitInRequest = $response === false ? 100 : (int)$response['result']['limit'];
    }

    /**
     * установка пользовательских параметров для cURL
     * @param array $options
     */
    public function setUserConnectionOptions($options)
    {
        curl_setopt_array($this->ch, $options);
    }

    /**
     * соответствие ID статуса раздачи его названию
     * @return bool|array
     */
    public function getTorrentStatusTitles()
    {
        return $this->makeRequest('get_tor_status_titles');
    }

    /**
     * дерево разделов
     * @return bool|array
     */
    public function getCategoryForumTree()
    {
        return $this->makeRequest('static/cat_forum_tree');
    }

    /**
     * количество и вес раздач по разделам
     * @return bool|array
     */
    public function getCategoryForumVolume()
    {
        return $this->makeRequest('static/forum_size');
    }

    /**
     * данные о раздачах по ID раздела
     * @param int|string $forumID
     * @return bool|array
     */
    public function getForumTopicsData($forumID)
    {
        if (empty($forumID)) {
            return false;
        }
        return $this->makeRequest('static/pvc/f/' . $forumID);
    }

    /**
     * количество пиров по ID или HASH
     * @param array $topicsValues
     * @param string $searchBy
     * @return bool|array
     */
    public function getPeerStats($topicsValues, $searchBy = 'topic_id')
    {
        if (empty($topicsValues)) {
            return false;
        }
        $topicsData = array();
        $topicsValues = array_chunk($topicsValues, $this->limitInRequest);
        foreach ($topicsValues as $topicsValues) {
            $params = array(
                'by=' . $searchBy,
                'val=' . implode(',', $topicsValues)
            );
            $response = $this->makeRequest('get_peer_stats', $params);
            if ($response === false) {
                continue;
            }
            foreach ($response['result'] as $topicID => $topicData) {
                if (!empty($topicData)) {
                    $topicsData[$topicID] = array_combine(
                        array(
                            'seeders',
                            'leechers',
                            'seeder_last_seen',
                            'keepers',
                        ),
                        $topicData
                    );
                }
            }
        }
        return $topicsData;
    }

    /**
     * ID темы по HASH торрента
     * @param array $topicsHashes
     * @return bool|array
     */
    public function getTopicID($topicsHashes)
    {
        if (empty($topicsHashes)) {
            return false;
        }
        $topicsData = array();
        $topicsHashes = array_chunk($topicsHashes, $this->limitInRequest);
        foreach ($topicsHashes as $topicsHashes) {
            $params = array(
                'by=hash',
                'val=' . implode(',', $topicsHashes)
            );
            $response = $this->makeRequest('get_topic_id', $params);
            if ($response === false) {
                continue;
            }
            foreach ($response['result'] as $topicHash => $topicID) {
                if (!empty($topicID)) {
                    $topicsData[$topicHash] = $topicID;
                }
            }
        }
        return $topicsData;
    }

    /**
     * данные о раздаче по ID темы
     * @param array $topicsValues
     * @return bool|array
     */
    public function getTorrentTopicData($topicsValues, $searchBy = 'topic_id')
    {
        if (empty($topicsValues)) {
            return false;
        }
        $topicsData = array();
        $topicsValues = array_chunk($topicsValues, $this->limitInRequest);
        foreach ($topicsValues as $topicsValues) {
            $params = array(
                'by=' . $searchBy,
                'val=' . implode(',', $topicsValues)
            );
            $response = $this->makeRequest('get_tor_topic_data', $params);
            if ($response === false) {
                continue;
            }
            foreach ($response['result'] as $topicID => $topicData) {
                if (is_array($topicData)) {
                    $topicsData[$topicID] = $topicData;
                }
            }
        }
        return $topicsData;
    }

    /**
     * список ID раздач с высоким приоритетом хранения
     * @return bool|array
     */
    public function getTopicsIDsHighPriority()
    {
        return $this->makeRequest('static/pvc/high_priority_topic_ids.json.gz');
    }

    /**
     * данные о раздачах с высоким приоритетом хранения
     * @return bool|array
     */
    public function getTopicsHighPriority()
    {
        return $this->makeRequest('static/pvc/high_priority_topics.json.gz');
    }

    /**
     * данные о хранителях
     * @return bool|array
     */
    public function getKeepersUserData()
    {
        return $this->makeRequest('static/keepers_user_data');
    }

    /**
     * @param array $userIDs
     * @return array|false
     */
    public function getUserName($userIDs)
    {
        if (empty($userIDs)) {
            return false;
        }
        $userData = array();
        $userIDs = array_chunk($userIDs, $this->limitInRequest);
        foreach ($userIDs as $userIDChunk) {
            $params = array(
                'by=user_id',
                'val=' . implode(',', $userIDChunk)
            );
            $response = $this->makeRequest('get_user_name', $params);
            if ($response === false) {
                continue;
            }
            foreach ($response['result'] as $userID => $userName) {
                $userData[$userID] = $userName;
            }
        }
        return $userData;
    }

    /**
     * default destructor
     */
    public function __destruct()
    {
        curl_close($this->ch);
    }
}
