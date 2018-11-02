<?php

try {

    include_once dirname(__FILE__) . '/../common.php';
    include_once dirname(__FILE__) . '/../classes/user_details.php';

    parse_str($_POST['cfg'], $cfg);

    if (
        empty($cfg['tracker_username'])
        || empty($cfg['tracker_password'])
    ) {
        throw new Exception();
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

    // получаем ключи пользователя
    UserDetails::get_details(
        $cfg['forum_url'],
        $cfg['tracker_username'],
        $cfg['tracker_password']
    );

    echo json_encode(
        array(
            'bt_key' => UserDetails::$bt,
            'api_key' => UserDetails::$api,
            'user_id' => UserDetails::$uid,
            'log' => Log::get(),
        )
    );

} catch (Exception $e) {

    Log::append($e->getMessage());
    echo json_encode(
        array(
            'bt_key' => '',
            'api_key' => '',
            'user_id' => '',
            'log' => Log::get(),
        )
    );

}
