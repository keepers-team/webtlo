<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo;

use Exception;
use KeepersTeam\Webtlo\Config\Defaults;
use KeepersTeam\Webtlo\Legacy\Log;
use PDO;

final class Settings
{
    /**
     * @var ?array<string, mixed>
     */
    private static ?array $config = null;

    public function __construct(
        private readonly TIniFileEx $ini,
        private readonly DB         $db,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function get(): array
    {
        return $this->populate();
    }

    /**
     * @return array<string, mixed>
     */
    public function populate(): array
    {
        if (self::$config !== null) {
            return self::$config;
        }

        $ini    = $this->ini;
        $config = [];

        // торрент-клиенты
        $qt = $ini->read('other', 'qt', '0');

        $config['clients'] = [];
        for ($i = 1; $i <= $qt; ++$i) {
            $sectionName = "torrent-client-$i";

            $id = $ini->read($sectionName, 'id', $i);
            $cm = $ini->read($sectionName, 'comment');

            $torrentClient = [];

            $torrentClient['id']  = $id;
            $torrentClient['cm']  = $cm ?: $id;
            $torrentClient['cl']  = $ini->read($sectionName, 'client', 'utorrent');
            $torrentClient['ht']  = $ini->read($sectionName, 'hostname');
            $torrentClient['pt']  = $ini->read($sectionName, 'port');
            $torrentClient['lg']  = $ini->read($sectionName, 'login');
            $torrentClient['pw']  = $ini->read($sectionName, 'password');
            $torrentClient['ssl'] = $ini->read($sectionName, 'ssl', 0);

            $torrentClient['control_peers'] = $ini->read($sectionName, 'control_peers');
            $torrentClient['exclude']       = $ini->read($sectionName, 'exclude', 0);

            $torrentClient['request_timeout'] = $ini->read($sectionName, 'request_timeout', Defaults::timeout);
            $torrentClient['connect_timeout'] = $ini->read($sectionName, 'connect_timeout', Defaults::timeout);

            $config['clients'][$id] = $torrentClient;

            unset($id, $cm, $torrentClient);
        }

        // Сортируем торрент-клиенты по введённому названию.
        $config['clients'] = Helper::natsortField($config['clients'], 'cm');

        // Уровень записи логов.
        $config['log_level'] = $ini->read('other', 'log_level', 'Info');

        // Выбранная тема интерфейса.
        $config['ui']['theme'] = $ini->read('ui', 'theme', Defaults::uiTheme);

        // подразделы
        $subsections = $ini->read('sections', 'subsections');
        if (!empty($subsections)) {
            $subsections = explode(',', $subsections);
            $subsections = array_map('intval', $subsections);

            $titles = $this->getSubsectionsTitles($subsections);
            foreach ($subsections as $id) {
                $forum_client = $ini->read($id, 'client', 0);

                $config['subsections'][$id]['cl']            = $forum_client !== '' ? $forum_client : 0;
                $config['subsections'][$id]['lb']            = $ini->read("$id", 'label');
                $config['subsections'][$id]['df']            = $ini->read("$id", 'data-folder');
                $config['subsections'][$id]['sub_folder']    = $ini->read($id, 'data-sub-folder', 0);
                $config['subsections'][$id]['hide_topics']   = $ini->read($id, 'hide-topics', 0);
                $config['subsections'][$id]['id']            = $id;
                $config['subsections'][$id]['na']            = $titles[$id] ?? $ini->read("$id", 'title', "$id");
                $config['subsections'][$id]['control_peers'] = $ini->read($id, 'control-peers');
                $config['subsections'][$id]['exclude']       = $ini->read($id, 'exclude', 0);
            }
            $config['subsections'] = Helper::natsortField($config['subsections'], 'na');
        }

        // фильтрация раздач
        $config['rule_topics']                 = $ini->read('sections', 'rule_topics', 5);
        $config['rule_date_release']           = $ini->read('sections', 'rule_date_release', 5);
        $config['avg_seeders']                 = $ini->read('sections', 'avg_seeders', 1);
        $config['avg_seeders_period']          = $ini->read('sections', 'avg_seeders_period', 14);
        $config['avg_seeders_period_outdated'] = $ini->read('sections', 'avg_seeders_period_outdated', 7);
        $config['exclude_self_keep']           = $ini->read('sections', 'exclude_self_keep', 1);
        $config['enable_auto_apply_filter']    = $ini->read('sections', 'enable_auto_apply_filter', 1);
        $config['ui_save_selected_section']    = $ini->read('sections', 'ui_save_selected_section', 1);

        // регулировка раздач
        $config['topics_control']['peers']               = $ini->read('topics_control', 'peers', 10);
        $config['topics_control']['intervals']           = $ini->read('topics_control', 'intervals', '');
        $config['topics_control']['keepers']             = $ini->read('topics_control', 'keepers', 3);
        $config['topics_control']['random']              = $ini->read('topics_control', 'random', 1);
        $config['topics_control']['priority']            = $ini->read('topics_control', 'priority', 1);
        $config['topics_control']['unadded_subsections'] = $ini->read('topics_control', 'unadded_subsections', 0);
        $config['topics_control']['leechers']            = $ini->read('topics_control', 'leechers', 0);
        $config['topics_control']['no_leechers']         = $ini->read('topics_control', 'no_leechers', 1);
        $config['topics_control']['days_until_unseeded'] = $ini->read('topics_control', 'days_until_unseeded', 21);
        $config['topics_control']['max_unseeded_count']  = $ini->read('topics_control', 'max_unseeded_count', 100);

        // прокси
        $config['proxy_activate_forum']  = $ini->read('proxy', 'activate_forum', 1);
        $config['proxy_activate_api']    = $ini->read('proxy', 'activate_api', 0);
        $config['proxy_activate_report'] = $ini->read('proxy', 'activate_report', 0);

        $config['proxy_type']     = $ini->read('proxy', 'type', 'socks5h');
        $config['proxy_hostname'] = $ini->read('proxy', 'hostname', 'gateway.keepers.tech');
        $config['proxy_port']     = $ini->read('proxy', 'port', 60789);
        $config['proxy_login']    = $ini->read('proxy', 'login');
        $config['proxy_paswd']    = $ini->read('proxy', 'password');

        $config['proxy_address'] = $config['proxy_hostname'] . ':' . $config['proxy_port'];
        $config['proxy_auth']    = $config['proxy_login'] . ':' . $config['proxy_paswd'];

        // Авторизация и хранительские ключи.
        $config['tracker_login'] = $ini->read('torrent-tracker', 'login');
        $config['tracker_paswd'] = $ini->read('torrent-tracker', 'password');

        $config['bt_key']  = $ini->read('torrent-tracker', 'bt_key');
        $config['api_key'] = $ini->read('torrent-tracker', 'api_key');

        $config['user_id']      = $ini->read('torrent-tracker', 'user_id');
        $config['user_session'] = $ini->read('torrent-tracker', 'user_session');

        // Форум
        $config['forum_url']        = basename($ini->read('torrent-tracker', 'forum_url', 'rutracker.org'));
        $config['forum_url_custom'] = basename($ini->read('torrent-tracker', 'forum_url_custom'));
        $config['forum_ssl']        = $ini->read('torrent-tracker', 'forum_ssl', 1);

        $forum_schema = $config['forum_ssl'] ? 'https' : 'http';
        $forum_url    = $config['forum_url'] === 'custom' ? $config['forum_url_custom'] : $config['forum_url'];

        $config['forum_base_url'] = $forum_url;
        $config['forum_address']  = $forum_schema . '://' . $forum_url;

        $config['forum_timeout']         = $ini->read('curl_setopt', 'forum_timeout', 40);
        $config['forum_connect_timeout'] = $ini->read('curl_setopt', 'forum_connecttimeout', 40);

        // Апи для получения сведений о раздачах
        $config['api_url']        = basename($ini->read('torrent-tracker', 'api_url', 'api.rutracker.cc'));
        $config['api_url_custom'] = basename($ini->read('torrent-tracker', 'api_url_custom'));
        $config['api_ssl']        = $ini->read('torrent-tracker', 'api_ssl', 1);

        $config['api_base_url'] = $config['api_url'] === 'custom'
            ? $config['api_url_custom']
            : $config['api_url'];

        $config['api_timeout']         = $ini->read('curl_setopt', 'api_timeout', 40);
        $config['api_connect_timeout'] = $ini->read('curl_setopt', 'api_connecttimeout', 40);

        // Апи для отправки отчётов.
        $config['report_url']        = basename($ini->read('torrent-tracker', 'report_url', 'rep.rutracker.cc'));
        $config['report_url_custom'] = basename($ini->read('torrent-tracker', 'report_url_custom'));
        $config['report_ssl']        = $ini->read('torrent-tracker', 'report_ssl', 1);

        $config['report_base_url'] = $config['report_url'] === 'custom'
            ? $config['report_url_custom']
            : $config['report_url'];

        // загрузки
        $config['save_dir']    = $ini->read('download', 'savedir', 'C:\Temp\\');
        $config['savesub_dir'] = $ini->read('download', 'savesubdir', 0);
        $config['retracker']   = $ini->read('download', 'retracker', 0);

        // кураторы
        $config['dir_torrents'] = $ini->read('curators', 'dir_torrents', 'C:\Temp\\');
        $config['user_passkey'] = $ini->read('curators', 'user_passkey');
        $config['tor_for_user'] = $ini->read('curators', 'tor_for_user', 0);

        // отчёты
        $config['reports'] = [
            // Отправлять ли отчёт пользователя в API.
            'send_report_api'     => $ini->read('reports', 'send_report_api', 1),
            // Отправлять сводный отчёт на форум
            'send_summary_report' => $ini->read('reports', 'send_summary_report', 1),
            // Отправлять краткую информацию о настройках WebTLO вместе со сводным отчётом
            'send_report_settings' => $ini->read('reports', 'send_report_settings', 1),
            // Исключить авторские раздачи из отчётов.
            'exclude_authored'     => $ini->read('reports', 'exclude_authored', 0),
            // Снимать отметку хранения с не хранимых подразделов.
            'unset_other_forums' => $ini->read('reports', 'unset_other_forums', 1),
            // Снимать отметку хранения с не хранимых раздач
            'unset_other_topics' => $ini->read('reports', 'unset_other_topics', 1),
            'exclude_forums_ids'  => $ini->read('reports', 'exclude_forums_ids'),
            'exclude_clients_ids' => $ini->read('reports', 'exclude_clients_ids'),
            // Список игнорируемых хранителей
            'exclude_keepers_ids' => $ini->read('reports', 'exclude_keepers_ids'),
        ];

        // автоматизация
        $config['automation'] = [
            'update'  => $ini->read('automation', 'update', 1),
            'reports' => $ini->read('automation', 'reports', 0),
            'control' => $ini->read('automation', 'control', 0),
        ];

        // Обновление список раздач
        $config['update'] = [
            'untracked'    => $ini->read('update', 'untracked', 1),
            'unregistered' => $ini->read('update', 'unregistered', 1),
        ];

        // версия конфига
        $user_version = (int) $ini->read('other', 'user_version', 0);

        // применение заплаток
        if ($user_version < 1) {
            if (
                !empty($subsections)
                && !empty($config['clients'])
            ) {
                $tor_clients_ids = array_keys($config['clients']);
                $tor_comments    = array_column($config['clients'], 'cm');
                $tor_clients     = array_combine($tor_comments, $tor_clients_ids);
                foreach ($subsections as $forum_id) {
                    $forum_client = $ini->read($forum_id, 'client', '0');
                    if (
                        !empty($forum_client)
                        && isset($tor_clients[$forum_client])
                    ) {
                        $forum_client_correct = $tor_clients[$forum_client];
                        $ini->write($forum_id, 'client', $forum_client_correct);
                        $config['subsections'][$forum_id]['cl'] = $forum_client_correct;
                    }
                }
            }
            $ini->write('other', 'user_version', 1);
            $ini->writeFile();
        }

        if ($user_version < 2) {
            $proxy_activate = $ini->read('proxy', 'activate', 0);

            $config['proxy_activate_forum'] = $proxy_activate;
            $ini->write('proxy', 'activate_forum', $proxy_activate);
            $ini->write('other', 'user_version', 2);
            $ini->writeFile();
        }

        if ($user_version < 3) {
            // Бекапим конфиг при изменении версии.
            Backup::config($ini->getFile(), $user_version);

            // Парсим опцию исключения из отчётов
            $excludeForumsIDs = $ini->read('reports', 'exclude');
            $excludeForumsIDs = array_filter(explode(',', trim($excludeForumsIDs)));
            $excludeForumsIDs = array_unique($excludeForumsIDs);
            $ini->write('reports', 'exclude', '');

            if (count($excludeForumsIDs)) {
                $checkedForumIDs = [];
                foreach ($excludeForumsIDs as $forum_id) {
                    $forum_id = (int) $forum_id;
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

            $ini->write('other', 'user_version', 3);
            $ini->writeFile();
        }

        return self::$config = $config;
    }

    /**
     * @param array<string, mixed> $cfg
     * @param array<string, mixed> $forums
     * @param array<string, mixed> $torrentClients
     */
    public function update(array $cfg, array $forums, array $torrentClients): bool
    {
        // Уровень ведения журнала.
        $this->ini->write('other', 'log_level', trim($cfg['log_level'] ?? 'Info'));

        // Форум / api.
        $this->setForum($cfg);

        // Прокси.
        $this->setProxy($cfg);

        // Кураторы.
        $this->setCurators($cfg);

        // Загрузка торрент-файлов.
        $this->setDownload($cfg);

        // Регулировка раздач.
        $this->setTopicControl($cfg);

        // Обновление списков раздач.
        $this->setUpdate($cfg);

        // Фильтрация раздач.
        $this->setTopicFiltration($cfg);

        // Торрент-клиенты.
        $excludeClientsIDs = $this->setTorrentClients($torrentClients);
        // Подразделы.
        $excludeForumsIDs = $this->setSubsections($forums);

        // Отправка отчётов.
        $this->setReports($cfg, $excludeClientsIDs, $excludeForumsIDs);

        // Автоматизация.
        $this->setAutomation($cfg);

        // Настройки интерфейса.
        $this->setUI($cfg);

        // Запись файла с настройками.
        return $this->ini->writeFile();
    }

    public function makePublicCopy(string $cloneName): bool
    {
        $iniClone = $this->ini;
        $iniClone->cloneFile($cloneName);

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
                $iniClone->write($section, $key, '');
            }
        }

        return $iniClone->writeFile();
    }

    /**
     * @param array<int|string, mixed>|array{} $torrentClients
     *
     * @return int[]
     */
    private function setTorrentClients(array $torrentClients = []): array
    {
        $ini = $this->ini;

        // торрент-клиенты
        $torrentClientNumber = 0;
        $excludeClientsIDs   = [];
        if (count($torrentClients)) {
            foreach ($torrentClients as $torrentClientID => $torrentClientData) {
                $torrentClientID = (int) $torrentClientID;

                ++$torrentClientNumber;
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

        // Количество добавленных торрент-клиентов.
        $ini->write('other', 'qt', $torrentClientNumber);

        return $excludeClientsIDs;
    }

    /**
     * @param array<int|string, mixed>|array{} $forums
     *
     * @return int[]
     */
    private function setSubsections(array $forums = []): array
    {
        $ini = $this->ini;

        $ini->write('sections', 'subsections', '');
        $excludeForumsIDs = [];
        if (count($forums)) {
            foreach ($forums as $forumID => $forumData) {
                if (isset($forumData['title'])) {
                    $ini->write($forumID, 'title', trim((string) $forumData['title']));
                }
                if (isset($forumData['client'])) {
                    $ini->write($forumID, 'client', $forumData['client']);
                }
                if (isset($forumData['label'])) {
                    $ini->write($forumID, 'label', trim((string) $forumData['label']));
                }
                if (isset($forumData['savepath'])) {
                    $ini->write($forumID, 'data-folder', trim((string) $forumData['savepath']));
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

        return array_map('intval', $excludeForumsIDs);
    }

    /**
     * @param array<string, mixed> $cfg
     */
    private function setTopicControl(array $cfg): void
    {
        $ini = $this->ini;

        $ini->write('topics_control', 'peers', (int) ($cfg['peers'] ?? 0));
        $ini->write('topics_control', 'intervals', (string) ($cfg['peers_intervals'] ?? ''));
        $ini->write('topics_control', 'keepers', (int) ($cfg['keepers'] ?? 0));
        $ini->write('topics_control', 'leechers', isset($cfg['leechers']) ? 1 : 0);
        $ini->write('topics_control', 'random', (int) ($cfg['random'] ?? 0));
        $ini->write('topics_control', 'priority', (int) ($cfg['peer_priority'] ?? 1));
        $ini->write('topics_control', 'no_leechers', isset($cfg['no_leechers']) ? 1 : 0);
        $ini->write('topics_control', 'unadded_subsections', isset($cfg['unadded_subsections']) ? 1 : 0);
        $ini->write('topics_control', 'days_until_unseeded', (int) ($cfg['days_until_unseeded'] ?? 0));
        $ini->write('topics_control', 'max_unseeded_count', (int) ($cfg['max_unseeded_count'] ?? 0));
    }

    /**
     * @param array<string, mixed> $cfg
     */
    private function setProxy(array $cfg): void
    {
        $ini = $this->ini;

        $ini->write('proxy', 'activate_forum', isset($cfg['proxy_activate_forum']) ? 1 : 0);
        $ini->write('proxy', 'activate_api', isset($cfg['proxy_activate_api']) ? 1 : 0);
        $ini->write('proxy', 'activate_report', isset($cfg['proxy_activate_report']) ? 1 : 0);

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
    }

    /**
     * @param array<string, mixed> $cfg
     */
    private function setCurators(array $cfg): void
    {
        $ini = $this->ini;

        if (isset($cfg['dir_torrents'])) {
            $ini->write('curators', 'dir_torrents', trim($cfg['dir_torrents']));
        }
        if (isset($cfg['passkey'])) {
            $ini->write('curators', 'user_passkey', trim($cfg['passkey']));
        }
        $ini->write('curators', 'tor_for_user', isset($cfg['tor_for_user']) ? 1 : 0);
    }

    /**
     * @param array<string, mixed> $cfg
     */
    private function setForum(array $cfg): void
    {
        $ini = $this->ini;

        // Перебираем три набора полей с адресами.
        foreach (['forum', 'api', 'report'] as $key) {
            $url    = "{$key}_url";
            $custom = "{$key}_url_custom";
            $ssl    = "{$key}_ssl";

            $ini->write('torrent-tracker', $url, trim($cfg[$url] ?? ''));
            $ini->write('torrent-tracker', $custom, trim($cfg[$custom] ?? ''));
            $ini->write('torrent-tracker', $ssl, isset($cfg[$ssl]) ? 1 : 0);

            unset($key, $url, $custom, $ssl);
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
        if (isset($cfg['user_session'])) {
            $ini->write('torrent-tracker', 'user_session', trim($cfg['user_session']));
        }
        if (isset($cfg['bt_key'])) {
            $ini->write('torrent-tracker', 'bt_key', trim($cfg['bt_key']));
        }
        if (isset($cfg['api_key'])) {
            $ini->write('torrent-tracker', 'api_key', trim($cfg['api_key']));
        }
    }

    /**
     * @param array<string, mixed> $cfg
     */
    private function setDownload(array $cfg): void
    {
        $ini = $this->ini;

        if (isset($cfg['savedir'])) {
            $ini->write('download', 'savedir', trim($cfg['savedir']));
        }
        $ini->write('download', 'savesubdir', isset($cfg['savesubdir']) ? 1 : 0);
        $ini->write('download', 'retracker', isset($cfg['retracker']) ? 1 : 0);
    }

    /**
     * @param array<string, mixed> $cfg
     */
    private function setTopicFiltration(array $cfg): void
    {
        $ini = $this->ini;

        $numerics = [
            'rule_topics',
            'rule_date_release',
            'avg_seeders_period',
            'avg_seeders_period_outdated',
        ];
        foreach ($numerics as $key) {
            if (isset($cfg[$key]) && is_numeric($cfg[$key])) {
                $ini->write('sections', $key, trim((string) $cfg[$key]));
            }
        }

        $booleans = [
            'avg_seeders',
            'exclude_self_keep',
            'enable_auto_apply_filter',
            'ui_save_selected_section',
        ];
        foreach ($booleans as $key) {
            $ini->write('sections', $key, (int) isset($cfg[$key]));
        }
    }

    /**
     * @param array<string, mixed> $cfg
     * @param int[]                $excludeClientsIDs
     * @param int[]                $excludeForumsIDs
     */
    private function setReports(array $cfg, array $excludeClientsIDs, array $excludeForumsIDs): void
    {
        $ini = $this->ini;

        // Отправлять ли отчёт пользователя в API.
        $ini->write('reports', 'send_report_api', (int) isset($cfg['send_report_api']));
        // Отправка сводных отчётов на форум
        $ini->write('reports', 'send_summary_report', (int) isset($cfg['send_summary_report']));
        // Отправлять краткую информацию о настройках WebTLO вместе со сводным отчётом.
        $ini->write('reports', 'send_report_settings', (int) isset($cfg['send_report_settings']));
        // Исключить авторские раздачи из отчётов.
        $ini->write('reports', 'exclude_authored', (int) isset($cfg['exclude_authored']));
        // Снимать отметку хранения с не хранимых подразделов
        $ini->write('reports', 'unset_other_forums', (int) isset($cfg['unset_other_forums']));
        // Снимать отметку хранения с не хранимых раздач
        $ini->write('reports', 'unset_other_topics', (int) isset($cfg['unset_other_topics']));

        // Исключаемые из отчётов торрент-клиенты
        $excludeClientsIDs = array_unique($excludeClientsIDs);
        sort($excludeClientsIDs);
        $ini->write('reports', 'exclude_clients_ids', implode(',', $excludeClientsIDs));

        // Исключаемые из отчётов подразделы
        $excludeForumsIDs = array_unique($excludeForumsIDs);
        sort($excludeForumsIDs);
        $ini->write('reports', 'exclude_forums_ids', implode(',', $excludeForumsIDs));
    }

    /**
     * @param array<string, mixed> $cfg
     */
    private function setAutomation(array $cfg): void
    {
        $ini = $this->ini;

        $ini->write('automation', 'update', isset($cfg['automation_update']) ? 1 : 0);
        $ini->write('automation', 'reports', isset($cfg['automation_reports']) ? 1 : 0);
        $ini->write('automation', 'control', isset($cfg['automation_control']) ? 1 : 0);
    }

    /**
     * @param array<string, mixed> $cfg
     */
    private function setUpdate(array $cfg): void
    {
        $ini = $this->ini;

        $ini->write('update', 'untracked', isset($cfg['update_untracked']) ? 1 : 0);
        $ini->write('update', 'unregistered', isset($cfg['update_unregistered']) ? 1 : 0);
    }

    /**
     * @param array<string, mixed> $cfg
     */
    private function setUI(array $cfg): void
    {
        $ini = $this->ini;

        $ini->write('ui', 'theme', $cfg['theme_selector'] ?? Defaults::uiTheme);
    }

    /**
     * Пробуем найти наименования подразделов в БД.
     *
     * @param int[] $subsections
     *
     * @return string[]|array{}
     */
    private function getSubsectionsTitles(array $subsections): array
    {
        $in = str_repeat('?,', count($subsections) - 1) . '?';

        try {
            return $this->db->query(
                "SELECT id, name FROM Forums WHERE id IN ($in)",
                $subsections,
                PDO::FETCH_KEY_PAIR
            );
        } catch (Exception $e) {
            Log::append($e->getMessage());

            return [];
        }
    }

    public function setForumCookie(string $cookie): void
    {
        $this->ini->write('torrent-tracker', 'user_session', trim($cookie));
        $this->ini->writeFile();
    }
}
