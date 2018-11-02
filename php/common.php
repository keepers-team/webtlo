<?php

include_once dirname(__FILE__) . '/classes/date.php';
include_once dirname(__FILE__) . '/classes/log.php';
include_once dirname(__FILE__) . '/classes/db.php';
include_once dirname(__FILE__) . '/classes/proxy.php';
include_once dirname(__FILE__) . '/classes/settings.php';

function get_settings($filename = "")
{

    $config = array();

    $ini = new TIniFileEx($filename);

    // торрент-клиенты
    $qt = $ini->read("other", "qt", "0");
    for ($i = 1; $i <= $qt; $i++) {
        $id = $ini->read("torrent-client-$i", "id", $i);
        $cm = $ini->read("torrent-client-$i", "comment", "");
        $config['clients'][$id]['cm'] = $cm != "" ? $cm : $id;
        $config['clients'][$id]['cl'] = $ini->read("torrent-client-$i", "client", "utorrent");
        $config['clients'][$id]['ht'] = $ini->read("torrent-client-$i", "hostname", "");
        $config['clients'][$id]['pt'] = $ini->read("torrent-client-$i", "port", "");
        $config['clients'][$id]['lg'] = $ini->read("torrent-client-$i", "login", "");
        $config['clients'][$id]['pw'] = $ini->read("torrent-client-$i", "password", "");
    }
    if (isset($config['clients']) && is_array($config['clients'])) {
        $config['clients'] = natsort_field($config['clients'], 'cm');
    }

    // подразделы
    $config['subsec'] = $ini->read("sections", "subsections", "");
    if (!empty($config['subsec'])) {
        $subsections = explode(',', $config['subsec']);
        $titles = Db::query_database(
            'SELECT id,na FROM Forums WHERE id IN (' . $config['subsec'] . ')',
            array(),
            true,
            PDO::FETCH_KEY_PAIR
        );
    }
    if (isset($subsections)) {
        foreach ($subsections as $id) {
            $forum_client = $ini->read($id, "client", 0);
            $config['subsections'][$id]['cl'] = $forum_client !== "" ? $forum_client : 0;
            $config['subsections'][$id]['lb'] = $ini->read("$id", "label", "");
            $config['subsections'][$id]['df'] = $ini->read("$id", "data-folder", "");
            $config['subsections'][$id]['ln'] = $ini->read("$id", "link", "");
            $config['subsections'][$id]['sub_folder'] = $ini->read("$id", "data-sub-folder", "");
            $config['subsections'][$id]['hide_topics'] = $ini->read($id, "hide-topics", 0);
            $config['subsections'][$id]['id'] = $id;
            $config['subsections'][$id]['na'] = isset($titles[$id]) ? $titles[$id] : $ini->read("$id", "title", "$id");
        }
        $config['subsections'] = natsort_field($config['subsections'], 'na');
    }

    // раздачи
    $config['rule_topics'] = $ini->read('sections', 'rule_topics', 3);
    $config['rule_date_release'] = $ini->read('sections', 'rule_date_release', 0);
    $config['avg_seeders'] = $ini->read('sections', 'avg_seeders', 0);
    $config['avg_seeders_period'] = $ini->read('sections', 'avg_seeders_period', 14);

    // регулировка раздач
    $config['topics_control']['peers'] = $ini->read('topics_control', 'peers', 10);
    $config['topics_control']['leechers'] = $ini->read('topics_control', 'leechers', 0);
    $config['topics_control']['no_leechers'] = $ini->read('topics_control', 'no_leechers', 1);

    // прокси
    $config['proxy_activate_forum'] = $ini->read('proxy', 'activate_forum', 0);
    $config['proxy_activate_api'] = $ini->read('proxy', 'activate_api', 0);
    $config['proxy_type'] = $ini->read('proxy', 'type', 'socks5h');
    $config['proxy_hostname'] = $ini->read('proxy', 'hostname', 'px1.blockme.site');
    $config['proxy_port'] = $ini->read('proxy', 'port', 33128);
    $config['proxy_login'] = $ini->read('proxy', 'login', '');
    $config['proxy_paswd'] = $ini->read('proxy', 'password', '');
    $config['proxy_address'] = $config['proxy_hostname'] . ':' . $config['proxy_port'];
    $config['proxy_auth'] = $config['proxy_login'] . ':' . $config['proxy_paswd'];

    // авторизация
    $config['tracker_login'] = $ini->read('torrent-tracker', 'login', '');
    $config['tracker_paswd'] = $ini->read('torrent-tracker', 'password', '');
    $config['bt_key'] = $ini->read('torrent-tracker', 'bt_key', '');
    $config['api_key'] = $ini->read('torrent-tracker', 'api_key', '');
    $config['api_url'] = $ini->read('torrent-tracker', 'api_url', 'https://api.t-ru.org');
    $config['user_id'] = $ini->read('torrent-tracker', 'user_id', '');
    $config['forum_url'] = $ini->read('torrent-tracker', 'forum_url', 'https://rutracker.cr');

    // загрузки
    $config['save_dir'] = $ini->read('download', 'savedir', 'C:\Temp\\');
    $config['savesub_dir'] = $ini->read('download', 'savesubdir', 0);
    $config['retracker'] = $ini->read('download', 'retracker', 0);

    // кураторы
    $config['dir_torrents'] = $ini->read('curators', 'dir_torrents', 'C:\Temp\\');
    $config['user_passkey'] = $ini->read('curators', 'user_passkey', '');
    $config['tor_for_user'] = $ini->read('curators', 'tor_for_user', 0);

    // версия конфига
    $user_version = $ini->read('other', 'user_version', 0);

    // применение заплаток
    if ($user_version < 1) {
        $forum_ids = explode(',', $config['subsec']);
        if (!empty($forum_ids) && !empty($config['clients'])) {
            $tor_clients_ids = array_keys($config['clients']);
            $tor_clients_comments = array_column_common($config['clients'], "cm");
            $tor_clients = array_combine($tor_clients_comments, $tor_clients_ids);
            foreach ($forum_ids as $forum_id) {
                $forum_client = $ini->read($forum_id, "client", "0");
                if (!empty($forum_client) && isset($tor_clients[$forum_client])) {
                    $forum_client_correct = $tor_clients[$forum_client];
                    $ini->write($forum_id, "client", $forum_client_correct);
                    $config['subsections'][$forum_id]['cl'] = $forum_client_correct;
                }
            }
        }
        $ini->write("other", "user_version", 1);
        $ini->updateFile();
    }

    if ($user_version < 2) {
        $proxy_activate = $ini->read("proxy", "activate", 0);
        $config['proxy_activate_forum'] = $proxy_activate;
        $ini->write("proxy", "activate_forum", $proxy_activate);
        $ini->write("other", "user_version", 2);
        $ini->updateFile();
    }

    // установка настроек прокси
    Proxy::options(
        $config['proxy_activate_forum'],
        $config['proxy_activate_api'],
        $config['proxy_type'],
        $config['proxy_address'],
        $config['proxy_auth']
    );

    return $config;

}

function convert_bytes($size)
{
    $filesizename = array(" Bytes", " KB", " MB", " GB", " TB", " PB", " EB", " ZB", " YB");
    $i = $size >= pow(1024, 4) ? 3 : floor(log($size, 1024));
    return $size ? round($size / pow(1024, $i), 2) . $filesizename[$i] : '0';
}

function convert_seconds($seconds, $leadzeros = false)
{
    $ret = "";
    $hours = floor($seconds / 3600);
    $mins = floor($seconds / 60 % 60);
    $secs = $seconds % 60;
    if ($leadzeros) {
        if (strlen($hours) == 1) {
            $hours = "0" . $hours;
        }
        if (strlen($secs) == 1) {
            $secs = "0" . $secs;
        }
        if (strlen($mins) == 1) {
            $mins = "0" . $mins;
        }
    }
    if ($hours == 0) {
        if ($mins == 0) {
            $ret = "${secs}s";
        } else {
            $ret = "${mins}m ${secs}s";
        }
    } else {
        $ret = "${hours}h ${mins}m ${secs}s";
    }
    return $ret;
}

function rmdir_recursive($path)
{
    $return = true;
    if (!file_exists($path)) {
        return true;
    }
    if (!is_dir($path)) {
        return unlink($path);
    }
    foreach (scandir($path) as $next_path) {
        if ('.' === $next_path || '..' === $next_path) {
            continue;
        }
        if (is_dir("$path/$next_path")) {
            if (!is_writable("$path/$next_path")) {
                return false;
            }
            $return = rmdir_recursive("$path/$next_path");
        } else {
            unlink("$path/$next_path");
        }
    }
    return ($return && is_writable($path)) ? rmdir($path) : false;
}

function mkdir_recursive($path)
{
    $return = false;
    if (PHP_OS == 'WINNT') {
        $winpath = mb_convert_encoding($path, 'Windows-1251', 'UTF-8');
        if (is_writable($winpath) && is_dir($winpath)) {
            return true;
        }
    }
    if (is_writable($path) && is_dir($path)) {
        return true;
    }
    $prev_path = dirname($path);
    if ($path != $prev_path) {
        $return = mkdir_recursive($prev_path);
    }
    if (PHP_OS == 'WINNT') {
        $winprev_path = mb_convert_encoding($prev_path, 'Windows-1251', 'UTF-8');
        return ($return && is_writable($winprev_path) && !file_exists($winpath)) ? mkdir($winpath) : false;
    }
    return ($return && is_writable($prev_path) && !file_exists($path)) ? mkdir($path) : false;
}

function array_column_common(array $input, $columnKey, $indexKey = null)
{
    $array = array();
    foreach ($input as $value) {
        if (!isset($value[$columnKey])) {
            trigger_error("Key \"$columnKey\" does not exist in array");
            return false;
        }
        if (is_null($indexKey)) {
            $array[] = $value[$columnKey];
        } else {
            if (!isset($value[$indexKey])) {
                trigger_error("Key \"$indexKey\" does not exist in array");
                return false;
            }
            if (!is_scalar($value[$indexKey])) {
                trigger_error("Key \"$indexKey\" does not contain scalar value");
                return false;
            }
            $array[$value[$indexKey]] = $value[$columnKey];
        }
    }
    return $array;
}

function natsort_field(array $input, $field, $direct = 1)
{
    uasort($input, function ($a, $b) use ($field, $direct) {
        if (is_string($a[$field])) {
            $a[$field] = mb_ereg_replace('ё', 'е', mb_strtolower($a[$field], 'UTF-8'));
        }

        if (is_string($b[$field])) {
            $b[$field] = mb_ereg_replace('ё', 'е', mb_strtolower($b[$field], 'UTF-8'));
        }

        return ($a[$field] != $b[$field] ? $a[$field] < $b[$field] ? -1 : 1 : 0) * $direct;
    });
    return $input;
}
