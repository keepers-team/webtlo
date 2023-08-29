<?php

include_once dirname(__FILE__) . '/classes/date.php';
include_once dirname(__FILE__) . '/classes/log.php';
include_once dirname(__FILE__) . '/classes/db.php';
include_once dirname(__FILE__) . '/classes/proxy.php';
include_once dirname(__FILE__) . '/classes/settings.php';
include_once dirname(__FILE__) . '/classes/Timers.php';
include_once dirname(__FILE__) . '/migration/Backup.php';
include_once dirname(__FILE__) . '/include.php';

// версия Web-TLO
$webtlo = get_webtlo_version();

// подключаемся к базе
Db::create();

// данные о сидах устарели
$avgSeedersPeriodOutdated = TIniFileEx::read('sections', 'avg_seeders_period_outdated', 7);
$avgSeedersPeriodOutdatedSeconds = $avgSeedersPeriodOutdated * 86400;
Db::query_database(
    "DELETE FROM Topics WHERE ss IN (SELECT id FROM UpdateTime WHERE strftime('%s', 'now') - ud > CAST(:ud as INTEGER)) AND pt <> 2
    OR strftime('%s', 'now') - (SELECT ud FROM UpdateTime WHERE id = 9999) > CAST(:ud as INTEGER) AND pt = 2",
    [':ud' => $avgSeedersPeriodOutdatedSeconds]
);
Db::query_database(
    "DELETE FROM UpdateTime WHERE strftime('%s', 'now') - ud > CAST(? as INTEGER)",
    [$avgSeedersPeriodOutdatedSeconds]
);

function get_webtlo_version()
{
    $webtlo_version_defaults = [
        'version' => '',
        'github' => '',
        'wiki' => '',
        'release' => '',
        'release_api' => '',
        'version_url' => '',
        'version_line' => 'Версия TLO: [b]Web-TLO-unknown[/b]',
        'version_line_url' => "Версия TLO: [b]Web-TLO-[url='#']unknown[/url][/b]"
    ];
    $version_json_path = dirname(__FILE__) . '/../version.json';
    if (!file_exists($version_json_path)) {
        error_log('`version.json` not found! Make sure you copied all files from the repo.');
        return (object) $webtlo_version_defaults;
    }
    $version_json = json_decode(file_get_contents($version_json_path), true);
    $result = (object) array_merge($webtlo_version_defaults, $version_json);

    if (!empty($result->version)) {
        $result->version_url = $result->github . '/releases/tag/' . $result->version;
        $result->version_line     = 'Версия TLO: [b]Web-TLO-' . $result->version . '[/b]';
        $result->version_line_url = 'Версия TLO: [b]Web-TLO-[url='. $result->version_url . ']' . $result->version . '[/url][/b]';
    }
    return $result;
}

/**
 * Получить timestamp последнего обновления заданного подраздела.
 */
function get_last_update_time(int $forum_id): int
{
    $update_time = Db::query_database_row(
        "SELECT ud FROM UpdateTime WHERE id = ?",
        [$forum_id],
        true,
        PDO::FETCH_COLUMN
    );

    return $update_time ?? 0;
}

/**
 * Записать timestamp последнего обновления заданного подраздела.
 */
function set_last_update_time(int $forum_id, ?int $update_time = null): void
{
    $update_time = $update_time ?? time();
    Db::query_database(
        "INSERT INTO UpdateTime (id,ud) SELECT ?,?",
        [$forum_id, $update_time]
    );
}

/**
 * Проверить прошло ли заданное количество секунд с последнего обновления подраздела.
 */
function check_update_available($forum_id, $compare = 3600): bool
{
    $update_time = get_last_update_time($forum_id);

    if (time() - $update_time < $compare) {
        // Если время не прошло, запретить обновление чего-либо.
        return false;
    }
    return true;
}

function get_settings($filename = "")
{
    $config = [];

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
        $config['clients'][$id]['ssl'] = $ini->read("torrent-client-$i", "ssl", 0);
        $config['clients'][$id]['control_peers'] = $ini->read("torrent-client-$i", "control_peers", "");
        $config['clients'][$id]['exclude'] = $ini->read("torrent-client-$i", "exclude", 0);
    }
    if (isset($config['clients']) && is_array($config['clients'])) {
        $config['clients'] = natsort_field($config['clients'], 'cm');
    }

    // подразделы
    $subsections = $ini->read("sections", "subsections", "");
    if (!empty($subsections)) {
        $subsections = explode(',', $subsections);
        $in = str_repeat('?,', count($subsections) - 1) . '?';
        $titles = Db::query_database(
            "SELECT id,name FROM Forums WHERE id IN ($in)",
            $subsections,
            true,
            PDO::FETCH_KEY_PAIR
        );
        foreach ($subsections as $id) {
            $forum_client = $ini->read($id, "client", 0);
            $config['subsections'][$id]['cl'] = $forum_client !== "" ? $forum_client : 0;
            $config['subsections'][$id]['lb'] = $ini->read("$id", "label", "");
            $config['subsections'][$id]['df'] = $ini->read("$id", "data-folder", "");
            $config['subsections'][$id]['sub_folder'] = $ini->read($id, "data-sub-folder", 0);
            $config['subsections'][$id]['hide_topics'] = $ini->read($id, "hide-topics", 0);
            $config['subsections'][$id]['id'] = $id;
            $config['subsections'][$id]['na'] = $titles[$id] ?? $ini->read("$id", "title", "$id");
            $config['subsections'][$id]['control_peers'] = $ini->read($id, 'control-peers', '');
            $config['subsections'][$id]['exclude'] = $ini->read($id, 'exclude', 0);
        }
        $config['subsections'] = natsort_field($config['subsections'], 'na');
    }

    // фильтрация раздач
    $config['rule_topics'] = $ini->read('sections', 'rule_topics', 5);
    $config['rule_date_release'] = $ini->read('sections', 'rule_date_release', 5);
    $config['avg_seeders'] = $ini->read('sections', 'avg_seeders', 1);
    $config['avg_seeders_period'] = $ini->read('sections', 'avg_seeders_period', 14);
    $config['avg_seeders_period_outdated'] = $ini->read('sections', 'avg_seeders_period_outdated', 7);
    $config['enable_auto_apply_filter'] = $ini->read('sections', 'enable_auto_apply_filter', 1);
    $config['exclude_self_keep'] = $ini->read('sections', 'exclude_self_keep', 1);

    // регулировка раздач
    $config['topics_control']['peers'] = $ini->read('topics_control', 'peers', 10);
    $config['topics_control']['keepers'] = $ini->read('topics_control', 'keepers', 3);
    $config['topics_control']['unadded_subsections'] = $ini->read('topics_control', 'unadded_subsections', 0);
    $config['topics_control']['leechers'] = $ini->read('topics_control', 'leechers', 0);
    $config['topics_control']['no_leechers'] = $ini->read('topics_control', 'no_leechers', 1);

    // прокси
    $config['proxy_activate_forum'] = $ini->read('proxy', 'activate_forum', 0);
    $config['proxy_activate_api'] = $ini->read('proxy', 'activate_api', 0);
    $config['proxy_type'] = $ini->read('proxy', 'type', 'socks5h');
    $config['proxy_hostname'] = $ini->read('proxy', 'hostname', 'gateway.keepers.tech');
    $config['proxy_port'] = $ini->read('proxy', 'port', 60789);
    $config['proxy_login'] = $ini->read('proxy', 'login', '');
    $config['proxy_paswd'] = $ini->read('proxy', 'password', '');
    $config['proxy_address'] = $config['proxy_hostname'] . ':' . $config['proxy_port'];
    $config['proxy_auth'] = $config['proxy_login'] . ':' . $config['proxy_paswd'];

    // авторизация
    $config['tracker_login'] = $ini->read('torrent-tracker', 'login', '');
    $config['tracker_paswd'] = $ini->read('torrent-tracker', 'password', '');
    $config['bt_key'] = $ini->read('torrent-tracker', 'bt_key', '');
    $config['api_key'] = $ini->read('torrent-tracker', 'api_key', '');
    $config['api_url'] = basename($ini->read('torrent-tracker', 'api_url', 'api.rutracker.cc'));
    $config['api_url_custom'] = basename($ini->read('torrent-tracker', 'api_url_custom', ''));
    $config['api_ssl'] = $ini->read('torrent-tracker', 'api_ssl', 1);
    $config['user_id'] = $ini->read('torrent-tracker', 'user_id', '');
    $config['forum_url'] = basename($ini->read('torrent-tracker', 'forum_url', 'rutracker.org'));
    $config['forum_url_custom'] = basename($ini->read('torrent-tracker', 'forum_url_custom', ''));
    $config['forum_ssl'] = $ini->read('torrent-tracker', 'forum_ssl', 1);
    $api_schema = $config['api_ssl'] ? 'https' : 'http';
    $forum_schema = $config['forum_ssl'] ? 'https' : 'http';
    $api_url = $config['api_url'] == 'custom' ? $config['api_url_custom'] : $config['api_url'];
    $forum_url = $config['forum_url'] == 'custom' ? $config['forum_url_custom'] : $config['forum_url'];
    $config['api_address'] = $api_schema . '://' . $api_url;
    $config['forum_address'] = $forum_schema . '://' . $forum_url;

    // загрузки
    $config['save_dir'] = $ini->read('download', 'savedir', 'C:\Temp\\');
    $config['savesub_dir'] = $ini->read('download', 'savesubdir', 0);
    $config['retracker'] = $ini->read('download', 'retracker', 0);

    // кураторы
    $config['dir_torrents'] = $ini->read('curators', 'dir_torrents', 'C:\Temp\\');
    $config['user_passkey'] = $ini->read('curators', 'user_passkey', '');
    $config['tor_for_user'] = $ini->read('curators', 'tor_for_user', 0);

    // вакансии
    $config['vacancies'] = [
        'scan_reports' => $ini->read('vacancies', 'scan_reports', 0),
        'scan_posted_days' => $ini->read('vacancies', 'scan_posted_days', 30),
        'send_topic_id' => $ini->read('vacancies', 'send_topic_id', ''),
        'send_post_id' => $ini->read('vacancies', 'send_post_id', ''),
        'avg_seeders_period' => $ini->read('vacancies', 'avg_seeders_period', 14),
        'avg_seeders_value' => $ini->read('vacancies', 'avg_seeders_value', 0.5),
        'reg_time_seconds' => $ini->read('vacancies', 'reg_time_seconds', 2592000),
        'exclude_forums_ids' => $ini->read('vacancies', 'exclude_forums_ids', ''),
        'include_forums_ids' => $ini->read('vacancies', 'include_forums_ids', ''),
    ];

    // отчёты
    $config['reports'] = [
        'auto_clear_messages' => $ini->read('reports', 'auto_clear_messages', 0),
        'exclude_forums_ids' => $ini->read('reports', 'exclude_forums_ids', ''),
        'exclude_clients_ids' => $ini->read('reports', 'exclude_clients_ids', ''),
        'send_summary_report' => $ini->read('reports', 'common', 1)
    ];

    // автоматизация
    $config['automation'] = [
        'update'  => $ini->read('automation', 'update',  1),
        'reports' => $ini->read('automation', 'reports', 0),
        'control' => $ini->read('automation', 'control', 0),
    ];

    // Обновление список раздач
    $config['update'] = [
        'priority'     => $ini->read('update', 'priority', 0),
    ];

    // таймауты
    $config['curl_setopt'] = [
        'api' => [
            CURLOPT_TIMEOUT => $ini->read('curl_setopt', 'api_timeout', 40),
            CURLOPT_CONNECTTIMEOUT => $ini->read('curl_setopt', 'api_connecttimeout', 40),
        ],
        'forum' => [
            CURLOPT_TIMEOUT => $ini->read('curl_setopt', 'forum_timeout', 40),
            CURLOPT_CONNECTTIMEOUT => $ini->read('curl_setopt', 'forum_connecttimeout', 40),
        ],
        'torrent_client' => [
            CURLOPT_TIMEOUT => $ini->read('curl_setopt', 'torrent_client_timeout', 40),
            CURLOPT_CONNECTTIMEOUT => $ini->read('curl_setopt', 'torrent_client_connecttimeout', 40),
        ]
    ];

    // версия конфига
    $user_version = $ini->read('other', 'user_version', 0);

    // применение заплаток
    if ($user_version < 1) {
        if (
            !empty($subsections)
            && !empty($config['clients'])
        ) {
            $tor_clients_ids = array_keys($config['clients']);
            $tor_clients_comments = array_column($config['clients'], "cm");
            $tor_clients = array_combine($tor_clients_comments, $tor_clients_ids);
            foreach ($subsections as $forum_id) {
                $forum_client = $ini->read($forum_id, "client", "0");
                if (
                    !empty($forum_client)
                    && isset($tor_clients[$forum_client])
                ) {
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

    if ($user_version < 3) {
        // Бекапим конфиг при изменении версии.
        Backup::config($ini->getFile(), $user_version);

        // Парсим опцию исключения из отчётов
        $excludeForumsIDs = $ini->read("reports", "exclude", "");
        $excludeForumsIDs = array_filter(explode(",", trim($excludeForumsIDs)));
        $excludeForumsIDs = array_unique($excludeForumsIDs);
        $ini->write("reports", "exclude", "");

        if (count($excludeForumsIDs)) {
            $checkedForumIDs = [];
            foreach ($excludeForumsIDs as $forum_id) {
                $forum_id = (int)$forum_id;
                if (isset($config['subsections'][$forum_id])) {
                    $config['subsections'][$forum_id]['exclude'] = 1;
                    $ini->write($forum_id, 'exclude', 1);

                    $checkedForumIDs[] = $forum_id;
                    unset($forum_id);
                }
            }
            $excludeForumsIDs = $checkedForumIDs;
            unset($checkedForumIDs);

            if (count($excludeForumsIDs)) {
                sort($excludeForumsIDs);

                $config['reports']['exclude_forums_ids'] = $excludeForumsIDs;
                $ini->write('reports', 'exclude_forums_ids', implode(',', $excludeForumsIDs));
            }
        }

        $ini->write("other", "user_version", 3);
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
    $filesizename = [" B", " KB", " MB", " GB", " TB", " PB", " EB", " ZB", " YB"];
    $i = $size >= pow(1024, 4) ? 3 : floor(log($size, 1024));
    return $size ? round($size / pow(1024, $i), 2) . $filesizename[$i] : '0 B';
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

function ckdir_recursive(string $path): void
{
    if (!file_exists($path)) {
        if (!mkdir_recursive($path)) {
            throw new Exception('Не удалось создать каталог ' . $path);
        }
    }
}

function natsort_field(array $input, $field, $direct = 1)
{
    uasort($input, function ($a, $b) use ($field, $direct) {
        if (
            is_numeric($a[$field])
            && is_numeric($b[$field])
        ) {
            return ($a[$field] != $b[$field] ? $a[$field] < $b[$field] ? -1 : 1 : 0) * $direct;
        }
        $a[$field] = mb_ereg_replace('ё', 'е', mb_strtolower($a[$field], 'UTF-8'));
        $b[$field] = mb_ereg_replace('ё', 'е', mb_strtolower($b[$field], 'UTF-8'));
        return (strnatcasecmp($a[$field], $b[$field])) * $direct;
    });
    return $input;
}
