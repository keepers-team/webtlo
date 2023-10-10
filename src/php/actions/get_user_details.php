<?php

try {
    include_once dirname(__FILE__) . '/../common.php';
    include_once dirname(__FILE__) . '/../classes/user_details.php';

    parse_str($_POST['cfg'], $cfg);

    if (
        empty($cfg['tracker_username'])
        || empty($cfg['tracker_password'])
        || empty($cfg['forum_url'])
    ) {
        throw new Exception();
    }

    // капча
    if (
        isset($_POST['cap_code'])
        && isset($_POST['cap_fields'])
    ) {
        $cap_code = $_POST['cap_code'];
        $cap_fields = explode(',', $_POST['cap_fields']);
        $cap_fields = [
            $cap_fields[0] => $cap_fields[1],
            $cap_fields[2] => $cap_code,
        ];
    } else {
        $cap_fields = [];
    }

    // параметры прокси
    $activate_forum = !empty($cfg['proxy_activate_forum']) ? 1 : 0;
    $activate_api = !empty($cfg['proxy_activate_api']) ? 1 : 0;
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

    // адрес форума
    $forum_schema = !empty($cfg['forum_ssl']) ? 'https' : 'http';
    $forum_url = $cfg['forum_url'] == 'custom' ? $cfg['forum_url_custom'] : $cfg['forum_url'];
    $forum_address = $forum_schema . '://' . $forum_url;

    // получаем ключи пользователя
    UserDetails::get_details(
        $forum_address,
        $cfg['tracker_username'],
        $cfg['tracker_password'],
        $cap_fields
    );

    echo json_encode(
        [
            'bt_key' => UserDetails::$bt,
            'api_key' => UserDetails::$api,
            'user_id' => UserDetails::$uid,
            'user_session' => UserDetails::$cookie,
            'captcha' => '',
            'captcha_path' => '',
            'log' => Log::get(),
        ]
    );
} catch (Exception $e) {
    Log::append($e->getMessage());
    echo json_encode(
        [
            'bt_key' => '',
            'api_key' => '',
            'user_id' => '',
            'captcha' => UserDetails::$captcha,
            'captcha_path' => UserDetails::$captcha_path,
            'log' => Log::get(),
        ]
    );
}
