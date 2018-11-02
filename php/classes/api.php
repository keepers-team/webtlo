<?php

class Api
{

    private $limit;

    protected $ch;
    protected $api_key;
    protected $api_url;
    protected $request_count = 0;

    public function __construct($api_url, $api_key = "")
    {
        Log::append('Используется зеркало для API: ' . $api_url);
        $this->api_key = $api_key;
        $this->api_url = $api_url;
        $this->init_curl();
        $this->get_limit();
    }

    private function init_curl()
    {
        $this->ch = curl_init();
        curl_setopt_array($this->ch, array(
            CURLOPT_RETURNTRANSFER => 1,
            CURLOPT_ENCODING => "gzip",
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => "Mozilla/5.0 (Windows NT 6.1) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/55.0.2883.87 Safari/537.36",
            CURLOPT_CONNECTTIMEOUT => 20,
            CURLOPT_TIMEOUT => 20,
        ));
        curl_setopt_array($this->ch, Proxy::$proxy['api_url']);
    }

    private function request_exec($url)
    {
        // таймаут запросов
        if ($this->request_count == 3) {
            sleep(1);
            $this->request_count = 0;
        }
        $this->request_count++;
        // выполнение запроса
        $n = 1; // номер попытки
        $try_number = 1; // номер попытки
        $try = 3; // кол-во попыток
        $data = array();
        curl_setopt($this->ch, CURLOPT_URL, $url);
        while (true) {
            $json = curl_exec($this->ch);
            if ($json === false) {
                $http_code = curl_getinfo($this->ch, CURLINFO_HTTP_CODE);
                if (
                    $http_code < 300
                    && $try_number <= $try
                ) {
                    Log::append("Повторная попытка $try_number/$try получить данные");
                    sleep(5);
                    $try_number++;
                    continue;
                }
                throw new Exception('CURL ошибка: ' . curl_error($this->ch) . " [$http_code]");
            }
            $data = json_decode($json, true);
            if (isset($data['error'])) {
                if (
                    $data['error']['code'] == '503'
                    && $n <= $try
                ) {
                    Log::append("Повторная попытка $n/$try получить данные");
                    sleep(20);
                    $n++;
                    continue;
                }
                if ($data['error']['code'] == '404') {
                    break;
                }
                throw new Exception('API ошибка: ' . $data['error']['text']);
            }
            break;
        }
        return $data;
    }

    // Ограничение на количество запрашиваемых данных
    private function get_limit()
    {
        $url = $this->api_url . '/v1/get_limit?api_key=' . $this->api_key;
        $data = $this->request_exec($url);
        $this->limit = empty($data['result']['limit']) ? 100 : $data['result']['limit'];
    }

    // Соответствие ID статуса раздачи его названию
    public function get_tor_status_titles()
    {
        $url = $this->api_url . '/v1/get_tor_status_titles?api_key=' . $this->api_key;
        $data = $this->request_exec($url);
        if (empty($data['result'])) {
            return false;
        }
        return $data;
    }

    // Дерево разделов
    public function get_cat_forum_tree()
    {
        $url = $this->api_url . '/v1/static/cat_forum_tree?api_key=' . $this->api_key;
        $data = $this->request_exec($url);
        if (empty($data['result'])) {
            return false;
        }
        return $data;
    }

    // Количество и вес раздач по разделам
    public function forum_size()
    {
        $url = $this->api_url . '/v1/static/forum_size?api_key=' . $this->api_key;
        $data = $this->request_exec($url);
        if (empty($data['result'])) {
            return false;
        }
        return $data;
    }

    // Данные о раздачах по ID раздела
    public function get_forum_topics_data($forum_id)
    {
        if (empty($forum_id)) {
            return false;
        }
        $url = $this->api_url . "/v1/static/pvc/f/$forum_id?api_key=" . $this->api_key;
        $data = $this->request_exec($url);
        if (empty($data['result'])) {
            return false;
        }
        return $data;
    }

    // Количество пиров по ID или HASH
    public function get_peer_stats($ids, $by = 'topic_id')
    {
        if (empty($ids)) {
            return false;
        }
        $topics = array();
        $ids = array_chunk($ids, $this->limit);
        foreach ($ids as $ids) {
            $value = implode(',', $ids);
            $url = $this->api_url . "/v1/get_peer_stats?by=$by&api_key=" . $this->api_key . '&val=' . $value;
            $data = $this->request_exec($url);
            unset($value);
            foreach ($data['result'] as $topic_id => $topic) {
                if (!empty($topic)) {
                    $topics[$topic_id] = array_combine(
                        array(
                            'seeders',
                            'leechers',
                            'seeder_last_seen',
                        ),
                        $topic
                    );
                }
            }
        }
        return $topics;
    }

    // ID темы по HASH торрента
    public function get_topic_id($hashes)
    {
        if (empty($hashes)) {
            return false;
        }
        $ids = array();
        $hashes = array_chunk($hashes, $this->limit);
        foreach ($hashes as $hashes) {
            $value = implode(',', $hashes);
            $url = $this->api_url . '/v1/get_topic_id?by=hash&api_key=' . $this->api_key . '&val=' . $value;
            $data = $this->request_exec($url);
            unset($value);
            foreach ($data['result'] as $hash => $id) {
                if (!empty($id)) {
                    $ids[$hash] = $id;
                }
            }
        }
        return $ids;
    }

    // Данные о раздаче по ID темы
    public function get_tor_topic_data($ids)
    {
        if (empty($ids)) {
            return false;
        }
        $topics = array();
        $ids = array_chunk($ids, $this->limit);
        foreach ($ids as $ids) {
            $value = implode(',', $ids);
            $url = $this->api_url . '/v1/get_tor_topic_data?by=topic_id&api_key=' . $this->api_key . '&val=' . $value;
            $data = $this->request_exec($url);
            unset($value);
            if (empty($data['result'])) {
                continue;
            }
            foreach ($data['result'] as $topic_id => $info) {
                if (is_array($info)) {
                    $topics[$topic_id] = $info;
                }
            }
        }
        return $topics;
    }

    public function __destruct()
    {
        curl_close($this->ch);
    }
}
