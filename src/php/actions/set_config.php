<?php

require __DIR__ . '/../../vendor/autoload.php';

use KeepersTeam\Webtlo\App;
use KeepersTeam\Webtlo\Legacy\Log;
use KeepersTeam\Webtlo\Settings;
use KeepersTeam\Webtlo\TIniFileEx;

try {
    App::init();

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
