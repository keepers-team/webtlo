<?php

try {
    include_once dirname(__FILE__) . '/../common.php';

    $request = json_decode(file_get_contents('php://input'), true);

    // парсим настройки
    if (isset($request['cfg'])) {
        parse_str($request['cfg'], $cfg);
    }

    if (isset($request['forums'])) {
        $forums = $request['forums'];
    }

    if (isset($request['tor_clients'])) {
        $torrentClients = $request['tor_clients'];
    }

    $ini = new TIniFileEx();

    // торрент-клиенты
    $torrentClientNumber = 0;
    $excludeClientsIDs = [];
    if (
        isset($torrentClients)
        && is_array($torrentClients)
    ) {
        foreach ($torrentClients as $torrentClientID => $torrentClientData) {
            $torrentClientNumber++;
            $torrentClientSection = 'torrent-client-' . $torrentClientNumber;
            $ini->write($torrentClientSection, 'id', $torrentClientID);
            if (isset($torrentClientData['comment'])) {
                $ini->write($torrentClientSection, 'comment', trim($torrentClientData['comment']));
            }
            if (isset($torrentClientData['type'])) {
                $ini->write($torrentClientSection, 'client', $torrentClientData['type']);
            }
            if (isset($torrentClientData['hostname'])) {
                $ini->write($torrentClientSection, 'hostname', trim($torrentClientData['hostname']));
            }
            if (isset($torrentClientData['port'])) {
                $ini->write($torrentClientSection, 'port', trim($torrentClientData['port']));
            }
            if (isset($torrentClientData['login'])) {
                $ini->write($torrentClientSection, 'login', trim($torrentClientData['login']));
            }
            if (isset($torrentClientData['password'])) {
                $ini->write($torrentClientSection, 'password', trim($torrentClientData['password']));
            }
            $ini->write($torrentClientSection, 'ssl', $torrentClientData['ssl']);
            if (isset($torrentClientData['control_peers'])) {
                $ini->write($torrentClientSection, 'control_peers', trim($torrentClientData['control_peers']));
            }
            if (isset($torrentClientData['exclude'])) {
                if ($torrentClientData['exclude']) {
                    $excludeClientsIDs[] = $torrentClientID;
                }
                $ini->write($torrentClientSection, 'exclude', $torrentClientData['exclude']);
            }
        }
    }
    $ini->write('other', 'qt', $torrentClientNumber); // кол-во торрент-клиентов

    // регулировка раздач
    if (
        isset($cfg['peers'])
        && is_numeric($cfg['peers'])
    ) {
        $ini->write('topics_control', 'peers', $cfg['peers']);
    }
    $ini->write('topics_control', 'keepers', isset($cfg['keepers']) ? (int)$cfg['keepers'] : 0);
    $ini->write('topics_control', 'leechers', isset($cfg['leechers']) ? 1 : 0);
    $ini->write('topics_control', 'no_leechers', isset($cfg['no_leechers']) ? 1 : 0);
    $ini->write('topics_control', 'unadded_subsections', isset($cfg['unadded_subsections']) ? 1 : 0);

    // прокси
    $ini->write('proxy', 'activate_forum', isset($cfg['proxy_activate_forum']) ? 1 : 0);
    $ini->write('proxy', 'activate_api', isset($cfg['proxy_activate_api']) ? 1 : 0);
    if (isset($cfg['proxy_type'])) {
        $ini->write('proxy', 'type', $cfg['proxy_type']);
    }
    if (isset($cfg['proxy_hostname'])) {
        $ini->write('proxy', 'hostname', trim($cfg['proxy_hostname']));
    }
    if (isset($cfg['proxy_port'])) {
        $ini->write('proxy', 'port', trim($cfg['proxy_port']));
    }
    if (isset($cfg['proxy_login'])) {
        $ini->write('proxy', 'login', trim($cfg['proxy_login']));
    }
    if (isset($cfg['proxy_paswd'])) {
        $ini->write('proxy', 'password', trim($cfg['proxy_paswd']));
    }

    // подразделы
    $ini->write('sections', 'subsections', '');
    $excludeForumsIDs = [];
    if (
        isset($forums)
        && is_array($forums)
    ) {
        foreach ($forums as $forumID => $forumData) {
            if (isset($forumData['title'])) {
                $ini->write($forumID, 'title', trim($forumData['title']));
            }
            if (isset($forumData['client'])) {
                $ini->write($forumID, 'client', $forumData['client']);
            }
            if (isset($forumData['label'])) {
                $ini->write($forumID, 'label', trim($forumData['label']));
            }
            if (isset($forumData['savepath'])) {
                $ini->write($forumID, 'data-folder', trim($forumData['savepath']));
            }
            if (isset($forumData['subdirectory'])) {
                $ini->write($forumID, 'data-sub-folder', $forumData['subdirectory']);
            }
            if (isset($forumData['hide'])) {
                $ini->write($forumID, 'hide-topics', $forumData['hide']);
            }
            if (isset($forumData['control_peers'])) {
                $ini->write($forumID, 'control-peers', $forumData['control_peers']);
            }
            if (isset($forumData['exclude'])) {
                if ($forumData['exclude']) {
                    $excludeForumsIDs[] = $forumID;
                }
                $ini->write($forumID, 'exclude', $forumData['exclude']);
            }
        }
        $forumsIDs = implode(',', array_keys($forums));
        $ini->write('sections', 'subsections', $forumsIDs);
    }

    // кураторы
    if (isset($cfg['dir_torrents'])) {
        $ini->write('curators', 'dir_torrents', trim($cfg['dir_torrents']));
    }
    if (isset($cfg['passkey'])) {
        $ini->write('curators', 'user_passkey', trim($cfg['passkey']));
    }
    $ini->write('curators', 'tor_for_user', isset($cfg['tor_for_user']) ? 1 : 0);

    // форум / api
    if (isset($cfg['api_url'])) {
        $ini->write('torrent-tracker', 'api_url', trim($cfg['api_url']));
    }
    if (isset($cfg['api_url_custom'])) {
        $ini->write('torrent-tracker', 'api_url_custom', trim($cfg['api_url_custom']));
    }
    if (isset($cfg['forum_url'])) {
        $ini->write('torrent-tracker', 'forum_url', trim($cfg['forum_url']));
    }
    if (isset($cfg['forum_url_custom'])) {
        $ini->write('torrent-tracker', 'forum_url_custom', trim($cfg['forum_url_custom']));
    }
    if (isset($cfg['tracker_username'])) {
        $ini->write('torrent-tracker', 'login', trim($cfg['tracker_username']));
    }
    if (isset($cfg['tracker_password'])) {
        $ini->write('torrent-tracker', 'password', trim($cfg['tracker_password']));
    }
    if (isset($cfg['user_id'])) {
        $ini->write('torrent-tracker', 'user_id', trim($cfg['user_id']));
    }
    if (isset($cfg['bt_key'])) {
        $ini->write('torrent-tracker', 'bt_key', trim($cfg['bt_key']));
    }
    if (isset($cfg['api_key'])) {
        $ini->write('torrent-tracker', 'api_key', trim($cfg['api_key']));
    }
    $ini->write('torrent-tracker', 'api_ssl', isset($cfg['api_ssl']) ? 1 : 0);
    $ini->write('torrent-tracker', 'forum_ssl', isset($cfg['forum_ssl']) ? 1 : 0);

    // загрузка торрент-файлов
    if (isset($cfg['savedir'])) {
        $ini->write('download', 'savedir', trim($cfg['savedir']));
    }
    $ini->write('download', 'savesubdir', isset($cfg['savesubdir']) ? 1 : 0);
    $ini->write('download', 'retracker', isset($cfg['retracker']) ? 1 : 0);

    // фильтрация раздач
    if (
        isset($cfg['rule_topics'])
        && is_numeric($cfg['rule_topics'])
    ) {
        $ini->write('sections', 'rule_topics', trim($cfg['rule_topics']));
    }
    if (
        isset($cfg['rule_date_release'])
        && is_numeric($cfg['rule_date_release'])
    ) {
        $ini->write('sections', 'rule_date_release', trim($cfg['rule_date_release']));
    }
    if (
        isset($cfg['avg_seeders_period'])
        && is_numeric($cfg['avg_seeders_period'])
    ) {
        $ini->write('sections', 'avg_seeders_period', trim($cfg['avg_seeders_period']));
    }
    if (
        isset($cfg['avg_seeders_period_outdated'])
        && is_numeric($cfg['avg_seeders_period_outdated'])
    ) {
        $ini->write('sections', 'avg_seeders_period_outdated', trim($cfg['avg_seeders_period_outdated']));
    }
    $ini->write('sections', 'avg_seeders', isset($cfg['avg_seeders']) ? 1 : 0);
    $ini->write('sections', 'enable_auto_apply_filter', isset($cfg['enable_auto_apply_filter']) ? 1 : 0);
    $ini->write('sections', 'exclude_self_keep', isset($cfg['exclude_self_keep']) ? 1 : 0);


    // Очистка своих сообщений на форуме
    $ini->write('reports', 'auto_clear_messages', isset($cfg['auto_clear_messages']) ? 1 : 0);

    // Исключаемые из отчётов торрент-клиенты
    $excludeClientsIDs = array_unique($excludeClientsIDs);
    sort($excludeClientsIDs);
    $ini->write('reports', 'exclude_clients_ids', implode(',', $excludeClientsIDs));
    // Исключаемые из отчётов подразделы
    $excludeForumsIDs = array_unique($excludeForumsIDs);
    sort($excludeForumsIDs);
    $ini->write('reports', 'exclude_forums_ids', implode(',', $excludeForumsIDs));

    // автоматизация
    $ini->write('automation', 'update',  isset($cfg['automation_update'])  ? 1 : 0);
    $ini->write('automation', 'reports', isset($cfg['automation_reports']) ? 1 : 0);
    $ini->write('automation', 'control', isset($cfg['automation_control']) ? 1 : 0);

    // Обновление список раздач
    $ini->write('update', 'priority', isset($cfg['update_priority']) ? 1 : 0);
    $ini->write('update', 'untracked', isset($cfg['update_untracked']) ? 1 : 0);
    $ini->write('update', 'unregistered', isset($cfg['update_unregistered']) ? 1 : 0);

    // обновление файла с настройками
    $ini->updateFile();

    // Сделаем копию конфига, убрав приватные данные.
    $ini->copyFile('config_public.ini');
    $private_options = [
        'torrent-tracker' => [
            'login',
            'password',
            'user_id',
            'bt_key',
            'api_key',
        ]
    ];
    foreach($private_options as $section => $keys) {
        foreach($keys as $key) {
            $ini->write($section, $key, '');
        }
    }
    $ini->updateFile();

    echo Log::get();
} catch (Exception $e) {
    Log::append($e->getMessage());
    echo Log::get();
}
