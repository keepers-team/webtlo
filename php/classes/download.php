<?php

class TorrentDownload
{

    /**
     * @var CurlHandle
     */
    private $ch;

    /**
     * @var int
     */
    private $numberRequest = 0;

    /**
     * default constructor
     * @param string $forumURL
     */
    public function __construct($forumURL)
    {
        $this->ch = curl_init();
        curl_setopt_array($this->ch, array(
            CURLOPT_URL => $forumURL . '/forum/dl.php',
            CURLOPT_RETURNTRANSFER => 1,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 6.1) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/55.0.2883.87 Safari/537.36',
        ));
        curl_setopt_array($this->ch, Proxy::$proxy['forum']);
        Log::append('Используется зеркало для форума: ' . $forumURL);
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
     * скачивание торрент-файла
     * @param string $userKeyApi
     * @param string $userID
     * @param string $topicID
     * @param int|bool $addRetracker
     * @return bool|resource
     */
    public function getTorrentFile($userKeyApi, $userID, $topicID, $addRetrackerURL = 0)
    {
        $params = array(
            'keeper_user_id' => $userID,
            'keeper_api_key' => $userKeyApi,
            't' => $topicID,
            'add_retracker_url' => $addRetrackerURL,
        );
        curl_setopt_array($this->ch, array(
            CURLOPT_POSTFIELDS => http_build_query($params),
        ));
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
        while (true) {
            // выходим после 3-х попыток
            if (
                $connectionNumberTry > $maxNumberTry
                || $responseNumberTry > $maxNumberTry
            ) {
                Log::append('Не удалось скачать торрент-файл для ' . $topicID);
                return false;
            }
            // выполняем запрос
            $response = curl_exec($this->ch);
            // повторные попытки
            if ($response === false) {
                $responseHttpCode = curl_getinfo($this->ch, CURLINFO_HTTP_CODE);
                Log::append('CURL ошибка: ' . curl_error($this->ch) . ' (раздача ' . $topicID . ') [' . $responseHttpCode . ']');
                if (
                    $responseHttpCode < 300
                    && $responseNumberTry <= $maxNumberTry
                ) {
                    Log::append('Повторная попытка ' . $responseNumberTry . '/' . $maxNumberTry . ' получить данные');
                    sleep(5);
                    $responseNumberTry++;
                    continue;
                }
                return false;
            }
            // проверка "торрент не зарегистрирован" и т.д.
            preg_match('|<center.*>(.*)</center>|si', mb_convert_encoding($response, 'UTF-8', 'Windows-1251'), $accessError);
            if (!empty($accessError)) {
                preg_match('|<title>(.*)</title>|si', mb_convert_encoding($response, 'UTF-8', 'Windows-1251'), $errorText);
                $errorText = empty($errorText) ? $accessError[1] : $errorText[1];
                Log::append('Error: ' . $errorText . ' (' . $topicID . ')');
                return false;
            }
            // проверка "ошибка 503" и т.д.
            preg_match('|<title>(.*)</title>|si', mb_convert_encoding($response, 'UTF-8', 'Windows-1251'), $connectionError);
            if (!empty($connectionError)) {
                Log::append('Error: ' . $connectionError[1] . ' (' . $topicID . ')');
                Log::append('Повторная попытка ' . $connectionNumberTry . '/' . $maxNumberTry . ' скачать торрент-файл (' . $topicID . ')');
                sleep(40);
                $connectionNumberTry++;
                continue;
            }
            break;
        }
        return $response;
    }

    /**
     * default destructor
     */
    public function __destruct()
    {
        curl_close($this->ch);
    }
}
