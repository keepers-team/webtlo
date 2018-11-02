<?php

try {

    include_once dirname(__FILE__) . '/../common.php';

    // парсим настройки
    if (isset($_POST['cfg'])) {
        parse_str($_POST['cfg'], $cfg);
    }

    if (isset($_POST['forums'])) {
        $forums = $_POST['forums'];
    }

    if (isset($_POST['tor_clients'])) {
        $tor_clients = $_POST['tor_clients'];
    }

    $ini = new TIniFileEx();

    // торрент-клиенты
    $q = 0;
    if (
        isset($tor_clients)
        && is_array($tor_clients)
    ) {
        foreach ($tor_clients as $id => $tor_client) {
            $q++;
            $ini->write("torrent-client-$q", 'id', $id);
            if (isset($tor_client['cm'])) {
                $ini->write("torrent-client-$q", 'comment', empty($tor_client['cm']) ? $id : $tor_client['cm']);
            }
            if (isset($tor_client['cl'])) {
                $ini->write("torrent-client-$q", 'client', $tor_client['cl']);
            }
            if (isset($tor_client['ht'])) {
                $ini->write("torrent-client-$q", 'hostname', $tor_client['ht']);
            }
            if (isset($tor_client['pt'])) {
                $ini->write("torrent-client-$q", 'port', $tor_client['pt']);
            }
            if (isset($tor_client['lg'])) {
                $ini->write("torrent-client-$q", 'login', $tor_client['lg']);
            }
            if (isset($tor_client['pw'])) {
                $ini->write("torrent-client-$q", 'password', $tor_client['pw']);
            }
        }
    }
    $ini->write('other', 'qt', $q); // кол-во торрент-клиентов

    // регулировка раздач
    if (
        isset($cfg['peers'])
        && is_numeric($cfg['peers'])
    ) {
        $ini->write('topics_control', 'peers', $cfg['peers']);
    }
    $ini->write('topics_control', 'leechers', isset($cfg['leechers']) ? 1 : 0);
    $ini->write('topics_control', 'no_leechers', isset($cfg['no_leechers']) ? 1 : 0);

    // прокси
    $ini->write('proxy', 'activate_forum', isset($cfg['proxy_activate_forum']) ? 1 : 0);
    $ini->write('proxy', 'activate_api', isset($cfg['proxy_activate_api']) ? 1 : 0);
    if (isset($cfg['proxy_type'])) {
        $ini->write('proxy', 'type', $cfg['proxy_type']);
    }
    if (isset($cfg['proxy_hostname'])) {
        $ini->write('proxy', 'hostname', $cfg['proxy_hostname']);
    }
    if (isset($cfg['proxy_port'])) {
        $ini->write('proxy', 'port', $cfg['proxy_port']);
    }
    if (isset($cfg['proxy_login'])) {
        $ini->write('proxy', 'login', $cfg['proxy_login']);
    }
    if (isset($cfg['proxy_paswd'])) {
        $ini->write('proxy', 'password', $cfg['proxy_paswd']);
    }

    // подразделы
    if (
        isset($forums)
        && is_array($forums)
    ) {
        foreach ($forums as $forum) {
            if (isset($forum['na'])) {
                $ini->write($forum['id'], 'title', $forum['na']);
            }
            if (isset($forum['cl'])) {
                $ini->write($forum['id'], 'client', empty($forum['cl']) ? '' : $forum['cl']);
            }
            if (isset($forum['lb'])) {
                $ini->write($forum['id'], 'label', $forum['lb']);
            }
            if (isset($forum['fd'])) {
                $ini->write($forum['id'], 'data-folder', $forum['fd']);
            }
            if (isset($forum['sub_folder'])) {
                $ini->write($forum['id'], 'data-sub-folder', $forum['sub_folder']);
            }
            if (isset($forum['ln'])) {
                $ini->write($forum['id'], 'link', $forum['ln']);
            }
            if (isset($forum['hide_topics'])) {
                $ini->write($forum['id'], 'hide-topics', $forum['hide_topics']);
            }
        }
        $ini->write('sections', 'subsections', implode(',', array_keys($forums)));
    }

    // кураторы
    if (isset($cfg['dir_torrents'])) {
        $ini->write('curators', 'dir_torrents', $cfg['dir_torrents']);
    }
    if (isset($cfg['passkey'])) {
        $ini->write('curators', 'user_passkey', $cfg['passkey']);
    }
    $ini->write('curators', 'tor_for_user', isset($cfg['tor_for_user']) ? 1 : 0);

    // форум / api
    if (isset($cfg['api_url'])) {
        $ini->write('torrent-tracker', 'api_url', $cfg['api_url']);
    }
    if (isset($cfg['forum_url'])) {
        $ini->write('torrent-tracker', 'forum_url', $cfg['forum_url']);
    }
    if (isset($cfg['tracker_username'])) {
        $ini->write('torrent-tracker', 'login', $cfg['tracker_username']);
    }
    if (isset($cfg['tracker_password'])) {
        $ini->write('torrent-tracker', 'password', $cfg['tracker_password']);
    }
    if (isset($cfg['user_id'])) {
        $ini->write('torrent-tracker', 'user_id', $cfg['user_id']);
    }
    if (isset($cfg['bt_key'])) {
        $ini->write('torrent-tracker', 'bt_key', $cfg['bt_key']);
    }
    if (isset($cfg['api_key'])) {
        $ini->write('torrent-tracker', 'api_key', $cfg['api_key']);
    }

    // загрузка торрент-файлов
    if (isset($cfg['savedir'])) {
        $ini->write('download', 'savedir', $cfg['savedir']);
    }
    $ini->write('download', 'savesubdir', isset($cfg['savesubdir']) ? 1 : 0);
    $ini->write('download', 'retracker', isset($cfg['retracker']) ? 1 : 0);

    // фильтрация раздач
    if (
        isset($cfg['rule_topics'])
        && is_numeric($cfg['rule_topics'])
    ) {
        $ini->write('sections', 'rule_topics', $cfg['rule_topics']);
    }
    if (
        isset($cfg['rule_date_release'])
        && is_numeric($cfg['rule_date_release'])
    ) {
        $ini->write('sections', 'rule_date_release', $cfg['rule_date_release']);
    }
    if (
        isset($cfg['avg_seeders_period'])
        && is_numeric($cfg['avg_seeders_period'])
    ) {
        $ini->write('sections', 'avg_seeders_period', $cfg['avg_seeders_period']);
    }
    $ini->write('sections', 'avg_seeders', isset($cfg['avg_seeders']) ? 1 : 0);

    // обновление файла с настройками
    $ini->updateFile();

    echo Log::get();

} catch (Exception $e) {

    Log::append($e->getMessage());
    echo Log::get();

}
