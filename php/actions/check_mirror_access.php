<?php

include_once dirname(__FILE__) . '/../common.php';

// проверяемый url
if (isset($_POST['url'])) {
    $url = $_POST['url'];
}

// тип url
if (isset($_POST['url_type'])) {
    $url_type = $_POST['url_type'];
}

if (
    empty($url)
    || empty($url_type)
) {
    return false;
}

// парсим настройки
if (isset($_POST['cfg'])) {
    parse_str($_POST['cfg'], $cfg);
}

// параметры прокси
$activate_forum = isset($cfg['proxy_activate_forum']) ? 1 : 0;
$activate_api = isset($cfg['proxy_activate_api']) ? 1 : 0;
$proxy_address = $cfg['proxy_hostname'] . ':' . $cfg['proxy_port'];
$proxy_auth = $cfg['proxy_login'] . ':' . $cfg['proxy_paswd'];

// устанавливаем прокси
Proxy::options(
    $activate_forum,
    $activate_api,
    $cfg['proxy_type'],
    $proxy_address,
    $proxy_auth
);

$ch = curl_init();

curl_setopt_array($ch, array(
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_SSL_VERIFYHOST => 2,
    CURLOPT_HEADER => true,
    CURLOPT_NOBODY => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_MAXREDIRS => 2,
    CURLOPT_USERAGENT => "Mozilla/5.0 (Windows NT 6.1) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/55.0.2883.87 Safari/537.36",
    CURLOPT_CONNECTTIMEOUT => 20,
    CURLOPT_TIMEOUT => 20,
    CURLOPT_URL => $url,
));

curl_setopt_array($ch, Proxy::$proxy[$url_type]);

// номер попытки
$try_number = 1;

// выполняем запрос
while (true) {
    $data = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if (
        $try_number <= 3
        && (
            $data === false
            || $http_code != 200
        )
    ) {
        $try_number++;
        sleep(1);
        continue;
    }
    break;
}

curl_close($ch);

// отправляем ответ
echo $http_code == 200 ? '1' : '0';
