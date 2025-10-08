<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo;

use KeepersTeam\Webtlo\Config\Defaults;

/**
 * Класс для записи конфигурации WebTLO в .ini файл.
 *
 * Конфиг хранится в config.ini файле в каталоге по-умолчанию, см. \KeepersTeam\Webtlo\Helper::getStorageDir().
 * Запись выполняется тут путём модификации TIniFileEx.
 *
 * Значения из конфига преобразуются в ряд DTO-классов в namespace KeepersTeam\Webtlo\Config
 * с помощью \KeepersTeam\Webtlo\Config\ConfigServiceProvider.
 *
 * Обработанные значения из DTO преобразуются в Front-Config в \KeepersTeam\Webtlo\Front\Render.
 * Front-Config - это двумерный массив, $config['section']['key'].
 * Который в index.php распихивается по полям в html.
 * После чего с помощью js+jquery наполняется различным функционалом.
 *
 *
 * Жизненный цикл параметров:
 * 1. Загрузка из config.ini в TIniFileEx, см.\KeepersTeam\Webtlo\AppServiceProvider.
 * 2. Выполнение миграций конфига, если нужно, см. \KeepersTeam\Webtlo\Config\ConfigMigration.
 * 3. Запись в DTO (обработка значений по умолчанию, в случае отсутствия фактических значений).
 * 4. Рендер значений в index.php + инициализация фронта.
 * 5. Изменение пользователем значений, см. \KeepersTeam\Webtlo\Front\Render.
 * 6. Сохранение изменений => src/php/actions/set_config.php
 * 7. Попадание сюда, Settings:update()
 */
final class Settings
{
    /** Актуальная версия. */
    final public const ACTUAL_VERSION = 3;

    public function __construct(
        private readonly TIniFileEx $ini,
    ) {}

    /**
     * @param array<string, mixed> $cfg
     * @param array<string, mixed> $forums
     * @param array<string, mixed> $torrentClients
     */
    public function update(array $cfg, array $forums, array $torrentClients): bool
    {
        $this->ini->write('other', 'user_version', self::ACTUAL_VERSION);

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

    public function setForumCookie(string $cookie): void
    {
        $this->ini->write('torrent-tracker', 'user_session', trim($cookie));
        $this->ini->writeFile();
    }
}
