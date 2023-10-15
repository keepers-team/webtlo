<?php

use KeepersTeam\Webtlo\Settings;
use KeepersTeam\Webtlo\TIniFileEx;

try {
    include_once dirname(__FILE__) . '/../common.php';

    $request = json_decode(file_get_contents('php://input'), true);

    // парсим настройки
    $cfg = [];
    if (isset($request['cfg'])) {
        parse_str($request['cfg'], $cfg);
    }

    $forums  = $request['forums'] ?? [];
    $clients = $request['tor_clients'] ?? [];


    // TODO container auto-wire.
    $ini      = new TIniFileEx();
    $settings = new Settings($ini);

    // Записываем настройки.
    $settings->update($cfg, $forums, $clients);

    // Сделаем копию конфига, убрав приватные данные.
    $ini->copyFile('config_public.ini');
    $private_options = [
        'torrent-tracker' => [
            'login',
            'password',
            'user_id',
            'user_session',
            'bt_key',
            'api_key',
        ],
    ];
    foreach ($private_options as $section => $keys) {
        foreach ($keys as $key) {
            $ini->write($section, $key, '');
        }
    }
    $ini->updateFile();

    echo Log::get();
} catch (Exception $e) {
    Log::append($e->getMessage());
    echo Log::get();
}
