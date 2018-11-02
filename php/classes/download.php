<?php

class Download
{

    protected $ch;
    protected $api_key;
    protected $user_id;
    protected $forum_url;

    public function __construct($forum_url, $api_key, $user_id)
    {
        $this->api_key = $api_key;
        $this->user_id = $user_id;
        $this->forum_url = $forum_url;
        $this->init_curl();
    }

    private function init_curl()
    {
        $this->ch = curl_init();
        curl_setopt_array($this->ch, array(
            CURLOPT_URL => $this->forum_url . '/forum/dl.php',
            CURLOPT_RETURNTRANSFER => 1,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => "Mozilla/5.0 (Windows NT 6.1) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/55.0.2883.87 Safari/537.36",
            CURLOPT_CONNECTTIMEOUT => 20,
            CURLOPT_TIMEOUT => 20,
        ));
        curl_setopt_array($this->ch, Proxy::$proxy['forum_url']);
    }

    public function get_torrent_file($topic_id, $add_retracker = 0)
    {
        curl_setopt_array($this->ch, array(
            CURLOPT_POSTFIELDS => http_build_query(
                array(
                    'keeper_user_id' => $this->user_id,
                    'keeper_api_key' => $this->api_key,
                    't' => $topic_id,
                    'add_retracker_url' => $add_retracker,
                )
            ),
        ));
        $n = 1; // номер попытки
        $try_number = 1; // номер попытки
        $try = 3; // кол-во попыток
        while (true) {
            // выходим после 3-х попыток
            if (
                $n > $try
                || $try_number > $try
            ) {
                Log::append("Не удалось скачать торрент-файл для $topic_id");
                return false;
            }
            // выполняем запрос
            $data = curl_exec($this->ch);
            // повторные попытки
            if ($data === false) {
                $http_code = curl_getinfo($this->ch, CURLINFO_HTTP_CODE);
                Log::append('CURL ошибка: ' . curl_error($this->ch) . " (раздача $topic_id) [$http_code]");
                if (
                    $http_code < 300
                    && $try_number <= $try
                ) {
                    Log::append("Повторная попытка $try_number/$try получить данные");
                    sleep(5);
                    $try_number++;
                    continue;
                }
                return false;
            }
            // проверка "торрент не зарегистрирован" и т.д.
            preg_match('|<center.*>(.*)</center>|si', mb_convert_encoding($data, 'UTF-8', 'Windows-1251'), $forbidden);
            if (!empty($forbidden)) {
                preg_match('|<title>(.*)</title>|si', mb_convert_encoding($data, 'UTF-8', 'Windows-1251'), $title);
                $error = empty($title) ? $forbidden[1] : $title[1];
                Log::append("Error: $error ($topic_id).");
                return false;
            }
            // проверка "ошибка 503" и т.д.
            preg_match('|<title>(.*)</title>|si', mb_convert_encoding($data, 'UTF-8', 'Windows-1251'), $error);
            if (!empty($error)) {
                Log::append("Error: $error[1] ($topic_id).");
                Log::append("Повторная попытка $n/$try скачать торрент-файл ($topic_id).");
                sleep(40);
                $n++;
                continue;
            }
            break;
        }
        return $data;
    }

    public function __destruct()
    {
        curl_close($this->ch);
    }

}
