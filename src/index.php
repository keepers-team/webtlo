<?php

use KeepersTeam\Webtlo\AppContainer;
use KeepersTeam\Webtlo\Legacy\Db as DbLegacy;
use KeepersTeam\Webtlo\Static\AppLogger;
use KeepersTeam\Webtlo\WebTLO;

Header("Cache-Control: no-cache, no-store, must-revalidate, max-age=0");
mb_internal_encoding("UTF-8");

// 1. Подключаем общие настройки (запуск БД).
try {
    include_once dirname(__FILE__) . '/vendor/autoload.php';

    AppContainer::create();
    DbLegacy::create();
} catch (Exception $e) {
    $initError = $e->getMessage();
}

// 2. Загружаем конфиг и рисуем селекторы.
try {
    $app = AppContainer::create();
    $cfg = $app->getLegacyConfig();

    // Callback для чекбоксов.
    $checkbox_check = cfg_checkbox($cfg);

    // чекбоксы
    $savesub_dir = $cfg['savesub_dir'] == 1 ? "checked" : "";
    $retracker = $cfg['retracker'] == 1 ? "checked" : "";
    $proxy_activate_forum = $cfg['proxy_activate_forum'] == 1 ? "checked" : "";
    $proxy_activate_api = $cfg['proxy_activate_api'] == 1 ? "checked" : "";
    $avg_seeders = $cfg['avg_seeders'] == 1 ? "checked" : "";
    $leechers = $cfg['topics_control']['leechers'] ? "checked" : "";
    $no_leechers = $cfg['topics_control']['no_leechers'] ? "checked" : "";
    $unadded_subsections = $cfg['topics_control']['unadded_subsections'] ? "checked" : "";
    $tor_for_user = $cfg['tor_for_user'] == 1 ? "checked" : "";
    $enable_auto_apply_filter = $cfg['enable_auto_apply_filter'] == 1 ? "checked" : "";
    $exclude_self_keep = $cfg['exclude_self_keep'] == 1 ? "checked" : "";

    $send_summary_report = $cfg['reports']['send_summary_report'] == 1 ? "checked" : "";
    $auto_clear_messages = $cfg['reports']['auto_clear_messages'] == 1 ? "checked" : "";

    // вставка option в select

    // форматы строк
    $optionFormat = /** @lang text */
        '<option value="%s" %s>%s</option>';
    $itemFormat   = /** @lang text */
        '<li class="ui-widget-content" value="%s" %s>%s</li>';

    $makeTemplate = fn($keys) => implode(' ', array_map(fn($el) => "data-$el=\"%s\"", $keys));

    /** Параметры торрент-клиента. */
    $datasetFormatTorrentClient = $makeTemplate([
        'comment',
        'type',
        'hostname',
        'port',
        'login',
        'password',
        'ssl',
        'peers',
        'exclude',
    ]);

    /** Параметры подраздела. */
    $datasetFormatForum = $makeTemplate([
        'client',
        'label',
        'savepath',
        'subdirectory',
        'hide',
        'peers',
        'exclude',
    ]);

    // стандартные адреса
    $forumAddressList = [
        'rutracker.org',
        'rutracker.net',
        'rutracker.nl',
        'custom'
    ];

    $apiAddressList = [
        'api.rutracker.cc',
        'custom'
    ];

    // адреса форума
    $optionForumAddress = '';
    foreach ($forumAddressList as $value) {
        $selected = $value == $cfg['forum_url'] ? 'selected' : '';
        $text     = $value == 'custom' ? 'другой' : $value;

        $optionForumAddress .= sprintf($optionFormat, $value, $selected, $text);
    }
    $forumVerifySSL = $cfg['forum_ssl'] ? 'checked' : '';

    // адреса api
    $optionApiAddress = '';
    foreach ($apiAddressList as $value) {
        $selected = $value == $cfg['api_url'] ? 'selected' : '';
        $text     = $value == 'custom' ? 'другой' : $value;

        $optionApiAddress .= sprintf($optionFormat, $value, $selected, $text);
    }
    $apiVerifySSL = $cfg['api_ssl'] ? 'checked' : '';

    // торрент-клиенты
    $optionTorrentClients = '';
    $optionTorrentClientsDataset = '';
    $excludeClientsIDs = [];
    if (isset($cfg['clients'])) {
        foreach ($cfg['clients'] as $torrentClientID => $torrentClientData) {
            if ($torrentClientData['exclude']) {
                $excludeClientsIDs[] = sprintf(
                    '%s(%d)',
                    $torrentClientData['cm'],
                    (string)$torrentClientID
                );
            }

            $datasetTorrentClient = sprintf(
                $datasetFormatTorrentClient,
                $torrentClientData['cm'],
                $torrentClientData['cl'],
                $torrentClientData['ht'],
                $torrentClientData['pt'],
                $torrentClientData['lg'],
                $torrentClientData['pw'],
                $torrentClientData['ssl'],
                $torrentClientData['control_peers'],
                $torrentClientData['exclude']
            );

            $optionTorrentClients .= sprintf(
                $optionFormat,
                (string)$torrentClientID,
                '',
                $torrentClientData['cm']
            );

            $optionTorrentClientsDataset .= sprintf(
                $itemFormat,
                (string)$torrentClientID,
                $datasetTorrentClient,
                $torrentClientData['cm']
            );
        }
    }

    $optionFilterClients = sprintf(
        $optionFormat,
        '0',
        '',
        'любой'
    ) . $optionTorrentClients;

    // хранимые подразделы
    $optionForums = '';
    $optionForumsDataset = '';
    if (isset($cfg['subsections'])) {
        foreach ($cfg['subsections'] as $forumID => $forumData) {
            $datasetForum = sprintf(
                $datasetFormatForum,
                $forumData['cl'],
                $forumData['lb'],
                $forumData['df'],
                $forumData['sub_folder'],
                $forumData['hide_topics'],
                $forumData['control_peers'],
                $forumData['exclude']
            );

            $optionForums .= sprintf(
                $optionFormat,
                (string)$forumID,
                '',
                $forumData['na']
            );

            $optionForumsDataset .= sprintf(
                $optionFormat,
                (string)$forumID,
                $datasetForum,
                $forumData['na']
            );
        }
    }

    // Уровни ведения журнала.
    $selectLogLevel = AppLogger::getSelectOptions($optionFormat, $cfg['log_level'] ?? '');

    $webtlo = WebTLO::getVersion();
} catch (Exception $e) {
    // $e->getMessage();
}

function cfg_checkbox($cfg): Closure
{
    return function($section, $option) use ($cfg) {
        $value = $cfg[$section][$option] ?? 0;
        return $value == 1 ? "checked" : "";
    };
}

?>

<!DOCTYPE html>
<html class="ui-widget-content" lang="ru">

<head>
    <meta charset="utf-8" />
    <title>web-TLO-<?= $webtlo->version ?? 'unknown' ?></title>

    <script src="scripts/jquery.lib/js.cookie.min.js"></script>
    <script src="scripts/jquery.lib/jquery.1.12.4.min.js"></script>
    <script src="scripts/jquery.lib/jquery.mousewheel.min.js"></script>
    <script src="scripts/jquery.lib/jquery-ui.1.12.1.min.js"></script>
    <script src="scripts/jquery.lib/jquery-ui-i18n.1.11.4.min.js"></script>

    <link rel="stylesheet" href="css/reset.css" /> <!-- сброс стилей -->
    <link rel="stylesheet" href="css/style.css" /> <!-- таблица стилей webtlo -->
    <link rel="stylesheet" href="css/fontawesome-all.min.css"> <!-- шрифт с иконками -->
    <link rel="stylesheet" href="css/v4-shims.min.css"> <!-- иконки от v4 -->
    <link rel="stylesheet" href="css/jquery-ui.smoothness.min.css" /> <!-- Стандартный стиль jquery -->

</head>

<body>
    <div id="menutabs" class="menu">
        <ul class="menu">
            <li id="menu_main"       class="menu"><a href="#main"       class="menu">Главная</a></li>
            <li id="menu_settings"   class="menu"><a href="#settings"   class="menu">Настройки</a></li>
            <li id="menu_reports"    class="menu"><a href="#reports"    class="menu">Отчёты</a></li>
            <li id="menu_statistics" class="menu"><a href="#statistics" class="menu">Статистика</a></li>
            <li id="menu_journal"    class="menu"><a href="#journal"    class="menu">Журнал</a></li>
            <li id="menu_manual"     class="menu"><a href="#manual"     class="menu">О программе</a></li>
        </ul>
        <div id="new_version_available">
            <span id="current_version"><?= "v$webtlo->version" ?? '' ?></span>
            <span id="new_version_description"></span>
        </div>
        <div id="content">
            <div id="main" class="content">
                <select id="main-subsections">
                    <optgroup label="">
                        <option value="-999">[[Выберите необходимый раздел из списка]]</option>
                    </optgroup>
                    <optgroup label="Общие группы раздач">
                        <option value="-3">Раздачи из всех хранимых подразделов</option>
                        <option value="-5">Раздачи с высоким приоритетом хранения</option>
                        <option value="-2">Раздачи из «чёрного списка»</option>
                        <option value="-4">Хранимые дублирующиеся раздачи</option>
                        <option value="-6">Хранимые раздачи по спискам</option>
                        <option value="0">Хранимые раздачи из других подразделов</option>
                        <option value="-1">Хранимые раздачи незарегистрированные на трекере</option>
                    </optgroup>
                    <optgroup label="Хранимые подразделы" id="main-subsections-stored">
                        <?= $optionForums ?? '' ?>
                    </optgroup>
                </select>
                <div id="topics_data">
                    <span id="load_error"><?= $initError ?? '' ?></span>
                    <div id="topics_control">
                        <div id="toolbar-filter-topics">
                            <button type="button" id="filter_show" title="Скрыть или показать параметры фильтра">
                                <i class="fa fa-filter" aria-hidden="true"></i>
                            </button>
                            <button id="apply_filter" type="button" title="Применить параметры фильтра">
                                <i class="fa fa-check" aria-hidden="true"></i>
                            </button>
                            <button type="button" id="filter_reset" title="Click - сбросить параметры фильтра на значения по умолчанию. &#10;Ctrl+Click - восстановить последние сброшенные настройки.">
                                <i class="fa fa-undo" aria-hidden="true"></i>
                            </button>
                        </div>
                        <div id="toolbar-select-topics">
                            <button type="button" class="tor_select" value="1" title="Выделить все раздачи текущего подраздела">
                                <i class="fa fa-check-square-o" aria-hidden="true"></i>
                            </button>
                            <button type="button" class="tor_select" title="Снять выделение всех раздач текущего подраздела">
                                <i class="fa fa-square-o" aria-hidden="true"></i>
                            </button>
                        </div>
                        <button type="button" id="tor_add" title="Добавить выделенные раздачи текущего подраздела в торрент-клиент">
                            <i class="fa fa-plus" aria-hidden="true"></i>
                        </button>
                        <div class="control-group">
                            <button type="button" class="tor_download" value="0" title="Скачать *.torrent файлы выделенных раздач текущего подраздела в каталог">
                                <i class="fa fa-download" aria-hidden="true"></i>
                            </button>
                            <select id="tor_download_options" class="filter-select-menu">
                                <option class="tor_download" value="1" title="Скачать *.torrent-файлы выделенных раздач текущего подраздела в каталог с заменой Passkey">с заменой Passkey</option>
                                <option class="tor_download_by_keepers_list" value="0" title="Скачать *.torrent-файлы хранимых раздач (по спискам с форума) текущего подраздела в каталог">по спискам с форума</option>
                                <option class="tor_download_by_keepers_list" value="1" title="Скачать *.torrent-файлы хранимых раздач (по спискам с форума) текущего подраздела в каталог с заменой Passkey">по спискам с форума и с заменой Passkey</option>
                            </select>
                        </div>
                        <div id="toolbar-control-topics">
                            <button type="button" id="tor_blacklist" value="1" title="Включить выделенные раздачи в чёрный список или наоборот исключить">
                                <i class="fa fa-ban" aria-hidden="true"></i>
                            </button>
                            <button type="button" class="tor_label torrent_action" value="set_label" title="Установить метку для выделенных раздач текущего подраздела в торрент-клиенте (удерживайте Ctrl для установки произвольной метки)">
                                <i class="fa fa-tag" aria-hidden="true"></i>
                            </button>
                            <button type="button" class="tor_start torrent_action" value="start" title="Запустить выделенные раздачи текущего подраздела в торрент-клиенте">
                                <i class="fa fa-play" aria-hidden="true"></i>
                            </button>
                            <button type="button" class="tor_stop torrent_action" value="stop" title="Приостановить выделенные раздачи текущего подраздела в торрент-клиенте">
                                <i class="fa fa-pause" aria-hidden="true"></i>
                            </button>
                            <button type="button" class="tor_remove torrent_action" value="remove" title="Удалить выделенные раздачи текущего подраздела из торрент-клиента">
                                <i class="fa fa-times" aria-hidden="true"></i>
                            </button>
                        </div>
                        <div class="control-group">
                            <button type="button" id="update_info" name="update_info" title="Обновить сведения о раздачах">
                                <i class="fa fa-refresh" aria-hidden="true"></i>
                                <span>Обновить сведения</span>
                            </button>
                            <select id="update_info_select" class="filter-select-menu">
                            </select>
                        </div>
                        <button class="send_reports" name="send_reports" type="button" title="Отправить отчёты на форум">
                            <i class="fa fa-paper-plane-o" aria-hidden="true"></i> Отправить отчёты
                        </button>
                        <button id="control_torrents" name="control_torrents" type="button" title="Выполнить регулировку раздач в торрент-клиентах">
                            <i class="fa fa-adjust" aria-hidden="true"></i> Регулировка раздач
                        </button>

                        <div class="process-indication">
                            <i class="process-loading process-icon fa fa-spinner fa-pulse"></i>
                            <div class="process-loading process-status"></div>
                        </div>
                    </div>
                    <form method="post" id="topics_filter">
                        <div class="topics_filter">
                            <div class="filter_block ui-widget">
                                <fieldset class="filter-exception-client-status" title="Статус раздач в торрент-клиенте">
                                    <label>
                                        <input type="checkbox" name="filter_client_status[]" value="1" />
                                        храню
                                    </label>
                                    <label>
                                        <input type="checkbox" name="filter_client_status[]" value="null" checked class="default" />
                                        не храню
                                    </label>
                                    <label>
                                        <input type="checkbox" name="filter_client_status[]" value="0" />
                                        качаю
                                    </label>
                                </fieldset>
                                <hr />
                                <fieldset class="filter-exception-sort-direction" title="Направление сортировки раздач">
                                    <label>
                                        <input type="radio" name="filter_sort_direction" value="1" checked class="default sort" />
                                        по возрастанию
                                    </label>
                                    <label>
                                        <input type="radio" name="filter_sort_direction" value="-1" class="sort" />
                                        по убыванию
                                    </label>
                                </fieldset>
                                <fieldset class="filter-exception-sort-rule" title="Критерий сортировки раздач">
                                    <label>
                                        <input type="radio" name="filter_sort" value="name" class="sort" />
                                        по названию
                                    </label>
                                    <label>
                                        <input type="radio" name="filter_sort" value="size" class="sort" />
                                        по объёму
                                    </label>
                                    <label>
                                        <input type="radio" name="filter_sort" value="topic_id" class="sort" />
                                        по номеру темы
                                    </label>
                                    <label>
                                        <input type="radio" name="filter_sort" value="seed" checked class="default sort" />
                                        по количеству сидов
                                    </label>
                                    <label>
                                        <input type="radio" name="filter_sort" value="reg_time" class="sort" />
                                        по дате регистрации
                                    </label>
                                    <label>
                                        <input type="radio" name="filter_sort" value="client_id" class="sort" />
                                        по клиенту
                                    </label>
                                </fieldset>
                            </div>
                            <div class="filter_block ui-widget" title="Статус раздач на трекере">
                                <fieldset class="filter-exception-tracker-status">
                                    <label>
                                        <input type="checkbox" name="filter_tracker_status[]" value="0" class="default" checked />
                                        не проверено
                                    </label>
                                    <label>
                                        <input type="checkbox" name="filter_tracker_status[]" value="2" class="default" checked />
                                        проверено
                                    </label>
                                    <label>
                                        <input type="checkbox" name="filter_tracker_status[]" value="3" class="default" checked />
                                        недооформлено
                                    </label>
                                    <label>
                                        <input type="checkbox" name="filter_tracker_status[]" value="8" class="default" checked />
                                        сомнительно
                                    </label>
                                    <label>
                                        <input type="checkbox" name="filter_tracker_status[]" value="10" class="default" checked />
                                        временная
                                    </label>
                                </fieldset>
                                <hr />
                                <fieldset class="filter-exception-tracker-priority" title="Приоритет раздач на трекере">
                                    <label>
                                        <input type="checkbox" name="keeping_priority[]" value="0" />
                                        низкий
                                    </label>
                                    <label>
                                        <input type="checkbox" name="keeping_priority[]" value="1" class="default" checked />
                                        обычный
                                    </label>
                                    <label>
                                        <input type="checkbox" name="keeping_priority[]" value="2" class="default" checked />
                                        высокий
                                    </label>
                                </fieldset>
                            </div>
                            <div class="filter_block ui-widget" title="">
                                <fieldset class="filter_common">
                                    <label class="filter-exception-seeders-period" title="Выберите произвольный период средних сидов">
                                        Период средних сидов:
                                        <input type="text" id="filter_avg_seeders_period" name="avg_seeders_period" size="1" value="<?= $cfg['avg_seeders_period'] ?>" />
                                    </label>
                                    <label class="date_container filter-exception-date-release ui-widget" title="Отображать раздачи зарегистрированные на форуме до">
                                        Дата регистрации до:
                                        <input type="text" id="filter_date_release" name="filter_date_release" value="<?= -$cfg['rule_date_release'] ?>" />
                                    </label>
                                </fieldset>
                                <hr />
                                <fieldset title="Клиент, в котором хранится раздача">
                                    <label>
                                        Клиент:
                                        <select name="filter_client_id" id="filter_client_id" class="myinput">
                                            <?= $optionFilterClients ?? '' ?>
                                        </select>
                                    </label>
                                </fieldset>
                                <hr />
                                <fieldset title="Поиск раздач по фразе">
                                    <label>
                                        Поиск по фразе:
                                        <input type="search" name="filter_phrase" size="16" />
                                    </label>
                                    <label title="Искать совпадение в названии раздачи">
                                        <input type="radio" name="filter_by_phrase" value="1" class="default" checked />
                                        в названии раздачи
                                    </label>
                                    <label title="Искать совпадение в имени хранителя">
                                        <input type="radio" name="filter_by_phrase" id="filter_by_keeper" value="0" />
                                        в имени хранителя
                                    </label>
                                    <label title="Искать совпадение в номере темы (полное совпадение или шаблон вида '1234*')">
                                        <input type="radio" name="filter_by_phrase" value="2" />
                                        в номере темы
                                    </label>
                                </fieldset>
                            </div>
                            <div class="filter_block ui-widget"
                                 title="Статус определяется при сканировании списков хранимого на форуме и обновлении сведений через API форума. Каждая раздача может иметь (или не иметь):
                                 'Хранителя', который скачал раздачу и включил её в свой отчёт;
                                 'Хранителя', который раздаёт раздачу на момент последнего обновления сведений;
                                 'Хранителя', который скачивает раздачу.">
                                <span>Статус хранения раздачи</span>
                                <hr/>
                                <fieldset class="filter-topic-kept-status">
                                    <fieldset title="Отобразить раздачи, у которых есть минимум однин хранитель, с полностью скачанной раздачей / раздачи, у которых нет хранителей.">
                                        <legend>
                                            <i class="fa fa-upload text-success" title="Есть в списке и раздаёт"></i>
                                            <i class="fa fa-hard-drive text-success" title="Есть в списке, не раздаёт"></i>
                                            Есть Хранитель
                                        </legend>
                                        <div class="filter_status_controlgroup filter_status_has_keeper">
                                            <input type="radio" id="has_keeper_null" name="filter_status_has_keeper" value="-1" checked="checked" class="default">
                                            <label for="has_keeper_null">--</label>

                                            <input type="radio" id="has_keeper_yes" name="filter_status_has_keeper" value="1">
                                            <label for="has_keeper_yes">да</label>

                                            <input type="radio" id="has_keeper_no" name="filter_status_has_keeper" value="0">
                                            <label for="has_keeper_no">нет</label>
                                        </div>
                                    </fieldset>
                                    <fieldset title="Отобразить раздачи, которые раздаются как минимум одним хранителем / раздачи, которые не раздаёт никто">
                                        <legend>
                                            <i class="fa fa-upload text-success" title="Есть в списке и раздаёт"></i>
                                            <i class="fa fa-arrow-circle-o-up text-success" title="Нет в списке и раздаёт"></i>
                                            Хранитель раздаёт
                                        </legend>
                                        <div class="filter_status_controlgroup filter_status_has_seeder">
                                            <input type="radio" id="has_seeder_null" name="filter_status_has_seeder" value="-1" checked="checked" class="default">
                                            <label for="has_seeder_null">--</label>

                                            <input type="radio" id="has_seeder_yes" name="filter_status_has_seeder" value="1">
                                            <label for="has_seeder_yes">да</label>

                                            <input type="radio" id="has_seeder_no" name="filter_status_has_seeder" value="0">
                                            <label for="has_seeder_no">нет</label>
                                        </div>
                                    </fieldset>
                                    <fieldset title="Отобразить раздачи, которые скачивает как минимум один хранитель / раздачи, которые не скачивает ни один хранитель">
                                        <legend>
                                            <i class="fa fa-arrow-circle-o-down text-danger" title="Скачивает"></i>
                                            Хранитель скачивает
                                        </legend>
                                        <div class="filter_status_controlgroup filter_status_has_downloader">
                                            <input type="radio" id="has_downloader_null" name="filter_status_has_downloader" value="-1" checked="checked" class="default">
                                            <label for="has_downloader_null">--</label>

                                            <input type="radio" id="has_downloader_yes" name="filter_status_has_downloader" value="1">
                                            <label for="has_downloader_yes">да</label>

                                            <input type="radio" id="has_downloader_no" name="filter_status_has_downloader" value="0">
                                            <label for="has_downloader_no">нет</label>
                                        </div>
                                    </fieldset>
                                </fieldset>
                            </div>
                            <div class="filter_block filter_rule ui-widget">
                                <fieldset class="filter-exception-keepers-count">
                                    <label title="Отобразить раздачи, по количеству хранителей, подходящих под условия.">
                                        <input type="checkbox" class="keepers" name="is_keepers" />
                                        количество хранителей
                                    </label>
                                    <fieldset class="keepers_filter_rule_fieldset">
                                        <label class="keepers-filter-count-padding25">
                                            от
                                            <input type="text" id="keepers_filter_count_min" class="keepers_filter_count"
                                                   title="Минимум хранителей"
                                                   name="keepers_filter_count[min]" size="1" value="1"/>
                                            до
                                            <input type="text" id="keepers_filter_count_max" class="keepers_filter_count"
                                                   title="Максимум хранителей"
                                                   name="keepers_filter_count[max]" size="1" value="10"/>
                                        </label>
                                        <fieldset class="filter-exception-keepers-count">
                                            <legend class="keepers-filter-count-padding25"
                                                   style="padding-top: 3px; padding-bottom: 3px;"
                                                   title="К хранителям каждой раздачи применяется фильтр выбираемый ниже. После чего применяется фильтр по количеству.">
                                                учитываемые типы:
                                            </legend>
                                            <label class="keepers-filter-count-padding20"
                                                   title="Хранитель скачал раздачу, добавил её в свой список и, в данный момент, не раздаёт её.">
                                                <input type="checkbox" class="keepers default" name="keepers_count_kept"
                                                       checked/>
                                                <i class="fa fa-hard-drive text-success"
                                                   title="Есть в списке, не раздаёт"></i>
                                                хранит, не раздаёт
                                            </label>
                                            <label class="keepers-filter-count-padding20"
                                                   title="Хранитель скачал раздачу, добавил её в свой список и, в данный момент, раздаёт её.">
                                                <input type="checkbox" class="keepers default"
                                                       name="keepers_count_kept_seed" checked/>
                                                <i class="fa fa-upload text-success"
                                                   title="Есть в списке и раздаёт"></i>
                                                хранит и раздаёт
                                            </label>
                                            <label class="keepers-filter-count-padding20"
                                                   title="Хранитель скачал раздачу и, в данный момент, раздаёт её.">
                                                <input type="checkbox" class="keepers" name="keepers_count_seed"/>
                                                <i class="fa fa-upload text-success"
                                                   title="Есть в списке и раздаёт"></i>
                                                <i class="fa fa-arrow-circle-o-up text-success"
                                                   title="Нет в списке и раздаёт"></i>
                                                раздаёт
                                            </label>
                                            <label class="keepers-filter-count-padding20"
                                                   title="Хранитель добавил раздачу в свой список и, в данный момент, качает её.">
                                                <input type="checkbox" class="keepers" name="keepers_count_download"/>
                                                <i class="fa fa-arrow-circle-o-down text-danger" title="Скачивает"></i>
                                                качает
                                            </label>
                                        </fieldset>
                                    </fieldset>
                                </fieldset>
                                <hr/>
                                <label title="Отображать только раздачи, для которых информация о сидах содержится за весь период, указанный в настройках (при использовании алгоритма нахождения среднего значения количества сидов)">
                                    <input type="checkbox" name="avg_seeders_complete"/>
                                    <i class="fa fa-circle text-success" title="Полные данные о средних сидах"></i>
<!--                                    <i class="fa fa-circle text-warning" title="Неполные данные о средних сидах"></i>-->
<!--                                    <i class="fa fa-circle text-danger" title="Отсутствуют данные о средних сидах"></i>-->
                                    "зелёные"
                                </label>
                                <hr/>
                                <label title="Использовать интервал сидов">
                                    <input type="checkbox" name="filter_interval" />
                                    интервал
                                </label>
                                <fieldset class="filter_rule_one filter-exception-seed-one"  title="Количество сидов на раздаче">
                                    <label>
                                        <input type="radio" name="filter_rule_direction" value="1" checked class="default" />
                                        не более
                                    </label>
                                    <label>
                                        <input type="radio" name="filter_rule_direction" value="0" />
                                        не менее
                                    </label>
                                    <label class="filter_rule_value" title="Количество сидов">
                                        <input type="text" id="filter_rule" name="filter_rule" size="1" value="<?= $cfg['rule_topics'] ?>" />
                                    </label>
                                </fieldset>
                                <fieldset class="filter_rule_interval filter-exception-seed-interval">
                                    <label class="filter_rule_value">
                                        от
                                        <input type="text" id="filter_rule_min" title="Минимальное количество сидов"
                                               name="filter_rule_interval[min]" size="1" value="0"/>
                                        до
                                        <input type="text" id="filter_rule_max" title="Максимальное количество сидов"
                                               name="filter_rule_interval[max]" size="1" value="<?= $cfg['rule_topics'] ?>"/>
                                    </label>
                                </fieldset>
                            </div>
                        </div>
                    </form>
                    <div class="process-bar"></div>
                    <div class="status_info">
                        <div id="counter">
                            Выбрано раздач: <span id="topics_count" class="bold">0</span> (<span id="topics_size">0.00</span>)
                            из <span id="filtered_topics_count" class="bold">0</span> (<span id="filtered_topics_size">0.00</span>);
                            <span title="Раздачи выбранного подраздела, добавленные в чёрный список">
                                В чёрном списке: <span id="excluded_topics_count" class="bold">0</span> (<span id="excluded_topics_size">0.00</span>);
                            </span>
                        </div>
                        <div id="topics_result"></div>
                    </div>
                    <form id="topics" method="post"></form>
                </div>
            </div>
            <div id="settings" class="content">
                <div>
                    <button type="button" id="savecfg" title="Записать настройки в файл">
                        Сохранить настройки
                    </button>
                </div>
                <form id="config">
                    <div class="sub_settings">
                        <h2 id="sub_setting_auth">Связь с форумом и API</h2>
                        <div>
                            <div id="forum_url_params">
                                <label for="forum_url" class="param-name">Адрес форума:</label>
                                <select name="forum_url" id="forum_url" class="myinput">
                                    <?= $optionForumAddress ?? '' ?>
                                </select>
                                <input id="forum_url_custom" name="forum_url_custom" class="myinput" type="text"
                                       size="14" value="<?= $cfg['forum_url_custom'] ?? '' ?>"/>
                                <label>
                                    <input id="forum_ssl" name="forum_ssl" class="check_access_forum"
                                           type="checkbox" <?= $forumVerifySSL ?? 'checked' ?> />
                                    HTTPS
                                </label>
                                <label title="Использовать прокси-сервер при обращении к форуму, например, для обхода блокировки.">
                                    <input name="proxy_activate_forum" class="check_access_forum" type="checkbox"
                                           size="24" <?= $proxy_activate_forum ?? '' ?> />
                                    Через прокси
                                </label>
                                <i id="forum_url_result" class=""></i>
                            </div>
                            <div id="api_url_params">
                                <label for="api_url" class="param-name">Адрес API:</label>
                                <select name="api_url" id="api_url" class="myinput">
                                    <?= $optionApiAddress ?? '' ?>
                                </select>
                                <input id="api_url_custom" name="api_url_custom" class="myinput" type="text" size="14"
                                       value="<?= $cfg['api_url_custom'] ?? '' ?>"/>
                                <label>
                                    <input id="api_ssl" name="api_ssl" class="check_access_api"
                                           type="checkbox" <?= $apiVerifySSL ?? 'checked' ?> />
                                    HTTPS
                                </label>
                                <label title="Использовать прокси-сервер при обращении к API, например, для обхода блокировки.">
                                    <input name="proxy_activate_api" class="check_access_api" type="checkbox"
                                           size="24" <?= $proxy_activate_api ?? '' ?> />
                                    Через прокси
                                </label>
                                <i id="api_url_result" class=""></i>
                            </div>
                            <div id="forum_auth_params">
                                <div>
                                    <label for="tracker_username" class="param-name">Логин:</label>
                                    <input id="tracker_username" name="tracker_username" type="text"
                                           class="myinput" size="25"
                                           placeholder="Логин на форуме" title="Логин на форуме"
                                           value="<?= $cfg['tracker_login'] ?>"/>
                                </div>
                                <div>
                                    <label for="tracker_password" class="param-name">Пароль:</label>
                                    <input id="tracker_password" name="tracker_password" type="password"
                                           class="myinput user_protected" size="25"
                                           placeholder="Пароль на форуме" title="Пароль на форуме"
                                           value="<?= $cfg['tracker_paswd'] ?>"/>
                                </div>
                                <div>
                                    <label for="user_session" class="param-name">Сессия:</label>
                                    <input id="user_session" name="user_session" type="password"
                                           class="myinput user_protected" size="25" readonly
                                           value="<?= $cfg['user_session'] ?>"/>
                                </div>
                            </div>
                            <button type="button" id="check_mirrors_access" class="settings-button" title="Проверить доступность форума и API">
                                Проверить доступ
                            </button>
                            <button type="button" id="forum_auth" class="settings-button" title="Авторизоваться на форуме">
                                Авторизоваться
                                <i id="forum_auth_result"></i>
                            </button>
                            <button type="button" id="show_passwords" class="settings-button" title="Показать/скрыть пароли и ключи">
                                <i class="fa fa-eye"></i>
                            </button>
                            <div id="api_auth_params">
                                <div>
                                    Полученные ключи:
                                    <label>
                                        bt  <input id="bt_key" name="bt_key" class="myinput user_details user_protected" type="password" size="10" readonly value="<?= $cfg['bt_key'] ?>" />
                                        api <input id="api_key" name="api_key" class="myinput user_details user_protected" type="password" size="10" readonly value="<?= $cfg['api_key'] ?>" />
                                        id  <input id="user_id" name="user_id" class="myinput user_details" type="text" size="10" readonly value="<?= $cfg['user_id'] ?>" />
                                    </label>
                                </div>
                            </div>
                            <hr>
                            <div id="proxy_prop">
                            <h2>Настройки прокси-сервера</h2>
                                <div>
                                    <label>
                                        Тип:
                                        <select name="proxy_type" id="proxy_type" class="myinput" title="Тип прокси-сервера">
                                            <option value="http" <?= ($cfg['proxy_type'] == 'http' ? "selected" : "") ?>>HTTP</option>
                                            <option value="socks4" <?= ($cfg['proxy_type'] == 'socks4' ? "selected" : "") ?>>SOCKS4</option>
                                            <option value="socks4a" <?= ($cfg['proxy_type'] == 'socks4a' ? "selected" : "") ?>>SOCKS4A</option>
                                            <option value="socks5" <?= ($cfg['proxy_type'] == 'socks5' ? "selected" : "") ?>>SOCKS5</option>
                                            <option value="socks5h" <?= ($cfg['proxy_type'] == 'socks5h' ? "selected" : "") ?>>SOCKS5H</option>
                                        </select>
                                    </label>
                                </div>
                                <div>
                                    <label>
                                        Адрес:
                                        <input name="proxy_hostname" id="proxy_hostname" type="text"
                                               class="myinput" size="24"
                                               title="IP-адрес или сетевое/доменное имя прокси-сервера."
                                               value="<?= $cfg['proxy_hostname'] ?>"/>
                                    </label>
                                    <label>
                                        Порт:
                                        <input name="proxy_port" id="proxy_port" type="text"
                                               class="myinput" size="6"
                                               title="Порт прокси-сервера."
                                               value="<?= $cfg['proxy_port'] ?>"/>
                                    </label>
                                </div>
                                <div>
                                    <label>
                                        Логин:
                                        <input name="proxy_login" id="proxy_login" type="text"
                                               class="myinput" size="24"
                                               title="Имя пользователя для доступа к прокси-серверу (необязательно)."
                                               value="<?= $cfg['proxy_login'] ?>"/>
                                    </label>
                                    <label>
                                        Пароль:
                                        <input name="proxy_paswd" id="proxy_paswd" type="password"
                                               class="myinput user_protected" size="24"
                                               title="Пароль для доступа к прокси-серверу (необязатально)."
                                               value="<?= $cfg['proxy_paswd'] ?>"/>
                                    </label>
                                </div>
                            </div>
                        </div>
                        <h2 id="sub_setting_client">Торрент-клиенты</h2>
                        <div>
                            <div>
                                <button type="button" id="add-torrent-client" title="Добавить новый торрент-клиент в список">
                                    Добавить
                                </button>
                                <button type="button" id="remove-torrent-client" title="Удалить выбранный торрент-клиент из списка">
                                    Удалить
                                </button>
                                <button type="button" id="connect-torrent-client" title="Проверить доступность выбранного торрент-клиента в списке">
                                    <i id="checking" class="fa fa-spinner fa-spin"></i> Проверить
                                </button>
                                <span id="torrent-client-response"></span>
                            </div>
                            <div class="block-settings">
                                <ol id="list-torrent-clients" title="Список сканируемых торрент-клиентов">
                                    <?= $optionTorrentClientsDataset ?>
                                </ol>
                            </div>
                            <div id="torrent-client-props" class="block-settings">
                                <div>
                                    <label>
                                        Название:
                                        <input name="torrent-client-comment" id="torrent-client-comment" class="torrent-client-props" type="text" size="50" title="Произвольное название торрент-клиента (комментарий)" />
                                    </label>
                                    <label>
                                        Торрент-клиент:
                                        <select name="torrent-client-type" id="torrent-client-type" class="torrent-client-props">
                                            <option value="utorrent">uTorrent</option>
                                            <option value="transmission">Transmission</option>
                                            <option value="deluge" title="WebUi plugin">Deluge</option>
                                            <option value="qbittorrent">qBittorrent</option>
                                            <option value="flood">Flood</option>
                                            <option value="rtorrent">rTorrent</option>
                                        </select>
                                    </label>
                                </div>
                                <div>
                                    <label>
                                        IP-адрес/сетевое имя:
                                        <input name="torrent-client-hostname" id="torrent-client-hostname" class="torrent-client-props" type="text" size="24" title="IP-адрес или сетевое/доменное имя компьютера с запущенным торрент-клиентом." />
                                        <input name="torrent-client-ssl" id="torrent-client-ssl" class="torrent-client-props" type="checkbox" />
                                        HTTPS
                                    </label>
                                    <label>
                                        Порт:
                                        <input name="torrent-client-port" id="torrent-client-port" class="torrent-client-props" type="text" size="24" title="Порт веб-интерфейса торрент-клиента." />
                                    </label>
                                </div>
                                <div>
                                    <label>
                                        Логин:
                                        <input name="torrent-client-login" id="torrent-client-login" class="torrent-client-props" type="text" size="24" title="Логин для доступа к веб-интерфейсу торрент-клиента (необязатально)." />
                                    </label>
                                    <label>
                                        Пароль:
                                        <input name="torrent-client-password" id="torrent-client-password" class="torrent-client-props" type="password" size="24" title="Пароль для доступа к веб-интерфейсу торрент-клиента (необязатально)." />
                                    </label>
                                </div>
                                <div>
                                    <label title="Позволяет исключить все раздачи данного торрент-клиента из формируемых отчётов">
                                        Исключить раздачи из отчётов
                                        <select id="torrent-client-exclude" class="myinput torrent-client-props">
                                            <option value="0">нет</option>
                                            <option value="1">да</option>
                                        </select>
                                    </label>
                                </div>
                                <div>
                                    <label>
                                        Останавливать раздачи с количеством пиров более:
                                        <input name="torrent-client-peers" id="torrent-client-peers" class="torrent-client-props spinner-peers" type="text" size="10" title="Числовое значение пиров, при котором требуется останавливать раздачи текущего торрент-клиента. Значение равное -1 исключит торрент-клиент из регулировки. См. подраздел 'Автоматизация и дополнительные настройки > Регулировка раздач.'" />
                                    </label>
                                </div>
                            </div>
                        </div>
                        <h2 id="sub_setting_forum">Сканируемые подразделы</h2>
                        <div>
                            <div class="input-container">
                                <input id="add-forum" class="myinput" type="text" size="100" placeholder="Для добавления подраздела начните вводить его индекс или название" title="Добавить новый подраздел" />
                                <div class="spinner-container">
                                    <i class="spinner"></i>
                                </div>
                            </div>
                            <input id="remove-forum" type="button" value="Удалить" title="Удалить выбранный подраздел" />
                            <div>
                                <label>
                                    Подраздел:
                                    <select name="list-forums" id="list-forums" class="ignore-save-change">
                                        <?= $optionForumsDataset ?? '' ?>
                                    </select>
                                </label>
                            </div>
                            <fieldset id="forum-props">
                                <label>
                                    Индекс:
                                    <input disabled size=10 id="forum-id" class="myinput forum-props ui-state-disabled" type="text" title="Индекс подраздела" />
                                </label>
                                <label>
                                    Торрент-клиент:
                                    <select id="forum-client" class="myinput forum-props" title="Добавлять раздачи текущего подраздела в торрент-клиент">
                                        <option value=0>не выбран</option>
                                        <?= $optionTorrentClients ?? '' ?>
                                    </select>
                                </label>
                                <label>
                                    Метка:
                                    <input id="forum-label" class="myinput forum-props" type="text" size="50" title="При добавлении раздачи установить для неё метку (поддерживаются только Deluge, qBittorrent, Flood и uTorrent)" />
                                </label>
                                <label>
                                    Каталог для данных:
                                    <input id="forum-savepath" class="myinput forum-props" type="text" size="57" title="При добавлении раздачи данные сохранять в каталог" />
                                </label>
                                <label>
                                    Создавать подкаталог для добавляемой раздачи:
                                    <select id="forum-subdirectory" class="myinput forum-props" title="Создавать подкаталог для данных добавляемой раздачи">
                                        <option value="0">нет</option>
                                        <option value="1">номер темы</option>
                                        <option value="2">хэш раздачи</option>
                                    </select>
                                </label>
                                <label title="Позволяет скрыть раздачи текущего подраздела из списка 'Раздачи из всех хранимых подразделов'">
                                    <i class="fa fa-eye-slash" aria-hidden="true" title="Иконка скрытого подраздела"></i>
                                    Скрывать раздачи в общем списке:
                                    <select id="forum-hide-topics" class="myinput forum-props">
                                        <option value="0">нет</option>
                                        <option value="1">да</option>
                                    </select>
                                </label>
                                <label title="Позволяет исключить все раздачи данного раздела из формируемых отчётов">
                                    <i class="fa fa-circle-minus" title="Иконка исключённого из отчётов подраздела"></i>
                                    Исключить раздачи из отчётов
                                    <select id="forum-exclude" class="myinput forum-props">
                                        <option value="0">нет</option>
                                        <option value="1">да</option>
                                    </select>
                                </label>
                                <label title="Числовое значение пиров, при котором требуется останавливать раздачи текущего торрент-клиента. Значение равное -1 исключит торрент-клиент из регулировки. См. подраздел 'Настройки управления раздачами.'">
                                    <i class="fa fa-bolt" aria-hidden="true" title="Иконка исключённого из регулировки подраздела"></i>
                                    Останавливать раздачи с количеством пиров более:
                                    <input id="forum-control-peers" class="myinput forum-props spinner-peers" type="text" size="10" />
                                </label>
                            </fieldset>
                        </div>
                        <h2>Фильтрация раздач</h2>
                        <div>
                            <label class="label" title="Укажите числовое значение количества сидов (по умолчанию: 3)">
                                Предлагать для хранения раздачи с количеством сидов не более:
                                <input id="rule_topics" name="rule_topics" type="text" size="2"
                                       value="<?= $cfg['rule_topics'] ?? 5 ?>"/>
                            </label>
                            <label class="label" title="Укажите необходимое количество дней">
                                Предлагать для хранения раздачи старше
                                <input id="rule_date_release" name="rule_date_release" type="text" size="2"
                                       value="<?= $cfg['rule_date_release'] ?? 5 ?>"/>
                                дн.
                            </label>
                            <label class="label"
                                   title="Если перерыв между обновлениями сведений составит больше этого периода, то накопленные данные о сидах будут считаться устаревшими (по умолчанию: 7)">
                                Допустимый период простоя между обновлениями:
                                <input id="avg_seeders_period_outdated" name="avg_seeders_period_outdated" type="text"
                                       size="2" value="<?= $cfg['avg_seeders_period_outdated'] ?? 7 ?>"/>
                                дн.
                            </label>
                            <label class="label"
                                   title="При фильтрации раздач будет использоваться среднее значение количества сидов вместо мгновенного (по умолчанию: выключено)">
                                <input id="avg_seeders" name="avg_seeders" type="checkbox" <?= $avg_seeders ?? '' ?> />
                                находить среднее значение количества сидов за
                                <input id="avg_seeders_period" name="avg_seeders_period"
                                       title="Укажите период хранения сведений о средних сидах, максимум 30 дней (по умолчанию: 14)"
                                       type="text" size="2" value="<?= $cfg['avg_seeders_period'] ?? 14 ?>"/>
                                дн.
                            </label>
                            <label class="label"
                                   title="При изменении параметров фильтра, автоматически обновлять список раздач на главной">
                                <input id="enable_auto_apply_filter" name="enable_auto_apply_filter"
                                       type="checkbox" <?= $enable_auto_apply_filter ?? '' ?> />
                                применять параметры фильтра автоматически
                            </label>
                            <label title="Всегда отключено для вкладки 'Хранимые раздачи по спискам'">
                                <input name="exclude_self_keep" type="checkbox"
                                       size="24" <?= $exclude_self_keep ?? '' ?> />
                                не показывать себя, как хранителя, в списке раздач на главной
                            </label>
                        </div>
                        <h2>Скачивание торрент-файлов</h2>
                        <div>
                            <h3>Каталог для скачиваемых *.torrent файлов</h3>
                            <div>
                                <input id="savedir" name="savedir" class="myinput" type="text" size="53"
                                       title="Каталог, куда будут сохраняться новые *.torrent-файлы."
                                       value="<?= $cfg['save_dir'] ?? '' ?>"/>
                            </div>
                            <label title="При установленной метке *.torrent-файлы дополнительно будут помещены в подкаталог.">
                                <input name="savesubdir" type="checkbox" size="24" <?= $savesub_dir ?? '' ?> />
                                создавать подкаталоги
                            </label>
                            <h3>Настройки retracker.local</h3>
                            <label title="Добавлять retracker.local в скачиваемые *.torrent-файлы.">
                                <input name="retracker" type="checkbox" size="24" <?= $retracker ?? '' ?> />
                                добавлять retracker.local в скачиваемые *.torrent-файлы
                            </label>
                            <h3>Скачивание *.torrent файлов с заменой Passkey</h3>
                            <label class="label">
                                Каталог:
                                <input id="dir_torrents" name="dir_torrents" class="myinput" type="text" size="53"
                                       title="Каталог, в который требуется сохранять торрент-файлы с изменённым Passkey."
                                       value="<?= $cfg['dir_torrents'] ?? '' ?>"/>
                            </label>
                            <label class="label">
                                Passkey:
                                <input id="passkey" name="passkey" class="myinput" type="text" size="15"
                                       title="Passkey, который необходимо вшить в скачиваемые торрент-файлы."
                                       value="<?= $cfg['user_passkey'] ?? '' ?>"/>
                            </label>
                            <label>
                                <input name="tor_for_user" type="checkbox" size="24" <?= $tor_for_user ?? '' ?> />
                                скачать торрент-файлы для обычного пользователя
                            </label>
                        </div>
                        <h2>Отправка отчётов</h2>
                        <div>
                            <label class="label" title="Можно отключить отправку сводного отчёта. Отчёты по хранимым подразделам будут отправлены как обычно.">
                                <input name="send_summary_report" type="checkbox" size="24" <?= $send_summary_report ?? '' ?> />
                                Отправлять сводный отчёт
                            </label>
                            <label class="label" title="Посты с отчётами о нехранимых подразделах, Будут заменены на 'Не актуально'">
                                <input name="auto_clear_messages" type="checkbox" size="24" <?= $auto_clear_messages ?? '' ?> />
                                Очищать свои "неактуальные" сообщения в рабочем подфоруме
                            </label>
                            <h3>Список исключённых групп, см. настройки торрент-клиентов/подразделов:</h3>
                            <label class="label">
                                Исключенные клиенты
                                <input id="exclude_clients_ids" type="text" size="20" readonly
                                       value="<?= implode(',', $excludeClientsIDs ?? []) ?>"/>
                            </label>
                            <label class="label">
                                Исключенные подразделы
                                <input id="exclude_forums_ids" type="text" size="20" readonly
                                       value="<?= $cfg['reports']['exclude_forums_ids'] ?? '' ?>"/>
                            </label>
                        </div>
                        <h2>Автоматизация и дополнительные настройки</h2>
                        <div>
                            <h3>Задачи, запускаемые из планировщика<sup>1</sup></h3>
                            <label class="label">
                                <input name="automation_update" type="checkbox" size="24" <?= $checkbox_check('automation', 'update')  ?> />
                                <span class="scriptname">[update.php, keepers.php]</span>
                                Обновление списков раздач в хранимых подразделах, списков других хранителей, списков хранимых раздач в торрент-клиентах
                            </label>
                            <label class="label">
                                <input name="automation_reports" type="checkbox" size="24" <?= $checkbox_check('automation', 'reports') ?> />
                                <span class="scriptname">[reports.php]</span>
                                Отправка отчётов на форум
                            </label>
                            <label class="label">
                                <input name="automation_control" type="checkbox" size="24" <?= $checkbox_check('automation', 'control') ?> />
                                <span class="scriptname">[control.php]</span>
                                Регулировка раздач в торрент-клиентах
                            </label>
                            <hr>
                            <h3>Дополнительные настройки обновления сведений</h3>
                            <label class="label">
                                <input name="update_priority" type="checkbox" size="24" <?= $checkbox_check('update', 'priority') ?> />
                                Обновлять списки раздач с высоким приоритетом хранения всего трекера
                            </label>
                            <label class="label">
                                <input name="update_untracked" type="checkbox" size="24" <?= $checkbox_check('update', 'untracked') ?> />
                                Поиск хранимых раздач из других подразделов
                            </label>
                            <label class="label">
                                <input name="update_unregistered" type="checkbox" size="24" <?= $checkbox_check('update', 'unregistered') ?> />
                                Поиск хранимых раздач незарегистрированных на трекере
                            </label>
                            <hr>
                            <h3>Регулировка раздач<sup>2</sup></h3>
                            <label class="label" title="Укажите числовое значение пиров, при котором требуется останавливать раздачи в торрент-клиентах (по умолчанию: 10)">
                                Останавливать раздачи с количеством пиров более:
                                <input id="peers" name="peers" class="spinner-peers" type="text" size="2" value="<?= $cfg['topics_control']['peers'] ?? 10 ?>" />
                            </label>
                            <label class="label" title="Укажите значение количества сидов-хранителей, которых не учитывать при подсчёте сидов. 0 - для выключения (по умолчанию: 3)">
                                Не учитывать до
                                <input id="keepers" name="keepers" class="spinner-keepers" type="text" size="1" value="<?= $cfg['topics_control']['keepers'] ?? 3 ?>" />
                                сидирующих хранителей, при подсчете текущих сидов
                            </label>
                            <label class="label" title="Установите, если необходимо регулировать раздачи, которые не попадают в хранимые разделы (по умолчанию: выключено)">
                                <input name="unadded_subsections" type="checkbox" <?= $unadded_subsections ?? '' ?> />
                                регулировать раздачи не из хранимых подразделов
                            </label>
                            <label class="label" title="Установите, если необходимо учитывать значение личей при регулировке, иначе будут браться только значения сидов (по умолчанию: выключено)">
                                <input name="leechers" type="checkbox" <?= $leechers ?? '' ?> />
                                учитывать значение личей
                            </label>
                            <label class="label" title="Выберите, если нужно запускать раздачи с 0 (нулём) личей, когда нет скачивающих (по умолчанию: включено)">
                                <input name="no_leechers" type="checkbox" <?= $no_leechers ?? '' ?> />
                                запускать раздачи с 0 (нулём) личей
                            </label>
                            <hr>
                            <ol class="footnote">
                                <li>Указанные настройки влияют исключительно на выполнение соответствующих фоновых задач. <br />
                                    Запуск задач должен быть настроен самостоятельно (cron или планировщик windows). <br />
                                    За подробностями обратитесь к <a target="_blank" href="<?= $webtlo->wiki . "/configuration/automation-scripts/" ?>">этой</a> странице.</li>
                                <li>Необходимо настроить автозапуск control.php</li>
                            </ol>
                        </div>
                        <h2>Журнал и внешний вид</h2>
                        <div>
                            <label class="label">
                                Цветовая схема интерфейса:
                                <select id="theme-selector" class="myinput ignore-save-change">
                                    <option value="black-tie">Black Tie</option>
                                    <option value="blitzer">Blitzer</option>
                                    <option value="cupertino">Cupertino</option>
                                    <option value="dark-hive">Dark Hive</option>
                                    <option value="dot-luv">Dot Luv</option>
                                    <option value="eggplant">Eggplant</option>
                                    <option value="excite-bike">Excite Bike</option>
                                    <option value="flick">Flick</option>
                                    <option value="hot-sneaks">Hot Sneaks</option>
                                    <option value="humanity">Humanity</option>
                                    <option value="le-frog">Le Frog</option>
                                    <option value="mint-choc">Mint Choc</option>
                                    <option value="overcast">Overcast</option>
                                    <option value="pepper-grinder">Pepper Grinder</option>
                                    <option value="redmond">Redmond</option>
                                    <option value="smoothness">Smoothness</option>
                                    <option value="south-street">South Street</option>
                                    <option value="start">Start</option>
                                    <option value="sunny">Sunny</option>
                                    <option value="swanky-purse">Swanky Purse</option>
                                    <option value="trontastic">Trontastic</option>
                                    <option value="ui-darkness">UI Darkness</option>
                                    <option value="ui-lightness">UI Lightness</option>
                                    <option value="vader">Vader</option>
                                </select>
                            </label>
                            <hr>
                            <label class="label">
                                Уровень ведения журнала:
                                <select name="log_level" id="log_level" class="myinput" title="Записи с выбранным уровнем и ниже - попадут в журнал. Не все записи в журнале имеют указание уровня.">
                                    <?= $selectLogLevel ?>
                                </select>
                            </label>
                        </div>
                    </div>
                </form>
            </div>
            <div id="reports" class="content">
                <div id="toolbar-reports-buttons" class="toolbar-buttons">
                    <button type="button" disabled id="get_reports" title="Повторить построение выбранного отчёта">
                        <i class="fa fa-refresh" aria-hidden="true"></i>
                    </button>
                    <button type="button" class="send_reports" name="send_reports" title="Отправить отчёты на форум">
                        <i class="fa fa-paper-plane-o" aria-hidden="true"></i> Отправить отчёты
                    </button>
                </div>
                <select id="reports-subsections">
                    <optgroup label="">
                        <option value="" disabled selected>Выберите подраздел из выпадающего списка</option>
                    </optgroup>
                    <optgroup label="">
                        <option value="0">Сводный отчёт</option>
                    </optgroup>
                    <optgroup label="Хранимые подразделы" id="reports-subsections-stored">
                        <?= $optionForums ?? '' ?>
                    </optgroup>
                </select>
                <div id="reports-content"></div>
            </div>
            <div id="statistics" class="content">
                <div class="toolbar-buttons">
                    <button type="button" id="get_statistics" title="Получить статистику по хранимым подразделам">
                        Отобразить статистику
                    </button>
                </div>
                <div id="data_statistics">
                    <table id="table_statistics">
                        <thead class="ui-widget-header">
                            <tr>
                                <th colspan="2">Подраздел</th>
                                <th colspan="10">Количество и вес раздач</th>
                            </tr>
                            <tr>
                                <th>ID</th>
                                <th width="40%">Название</th>
                                <th colspan="2">сc == 0</th>
                                <th colspan="2">0.0 < cc <=0.5</th>
                                <th colspan="2">0.5 < сc <=1.0</th>
                                <th colspan="2">1.0 < сc <=1.5</th>
                                <th colspan="2">Всего в подразделе</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td colspan="12">&mdash;</td>
                            </tr>
                        </tbody>
                        <tfoot class="ui-widget-header"></tfoot>
                    </table>
                </div>
            </div>
            <div id="journal" class="content">
                <div id="toolbar-journal-buttons" class="toolbar-buttons">
                    <button type="button" id="refresh_log" title="Обновить лог">
                        <i class="fa fa-refresh" aria-hidden="true"></i>
                    </button>
                    <button type="button" id="clear_log" title="Очистить содержимое лога">
                        <i class="fa fa-trash-can" aria-hidden="true"></i>
                        Очистить лог
                    </button>
                </div>
                <div id="log_tabs" class="menu">
                    <ul class="menu">
                        <li class="menu"><a href="#log" class="menu">Лог</a></li>
                        <li class="menu"><a href="#log_update" class="menu log_file">update</a></li>
                        <li class="menu"><a href="#log_keepers" class="menu log_file">keepers</a></li>
                        <li class="menu"><a href="#log_reports" class="menu log_file">reports</a></li>
                        <li class="menu"><a href="#log_control" class="menu log_file">control</a></li>
                        <li class="menu"><a href="#log_seeders" class="menu log_file">seeders</a></li>
                    </ul>
                    <div id="log_content">
                        <div id="log"></div>
                        <div id="log_update"></div>
                        <div id="log_keepers"></div>
                        <div id="log_reports"></div>
                        <div id="log_control"></div>
                        <div id="log_seeders"></div>
                    </div>
                </div>
            </div>
            <div id="manual" class="content">
                <p>
                    <?= $webtlo->getReleaseLink() ?>
                    <?= $webtlo->getCommitLink() ?>
                </p>
                <p>Простое веб-приложение для управления торрентами</p>
                <p>
                    <?= $webtlo->getWikiLink() ?>
                </p>

                <hr />
                <p>Данные о системе:</p>
                <ul class="about-installation">
                    <?= $webtlo->getInstallation() ?>
                </ul>
                <a href="/probe.php" target="_blank" style="font-size: small">Тест конфигурации</a>

                <hr />
                <p>Copyright © 2016-2024 Alexander Shemetov</p>
            </div>
        </div>
    </div>
    <div id="dialog" title="Сообщение"></div>
    <!-- скрипты webtlo -->
    <script type="text/javascript" src="scripts/jquery.common.js"></script>
    <script type="text/javascript" src="scripts/jquery.topics.func.js"></script>
    <script type="text/javascript" src="scripts/jquery.subsections.func.js"></script>
    <script type="text/javascript" src="scripts/jquery.clients.func.js"></script>
    <script type="text/javascript" src="scripts/jquery.actions.js"></script>
    <script type="text/javascript" src="scripts/jquery.settings.init.js"></script>
    <script type="text/javascript" src="scripts/jquery.widgets.init.js"></script>
    <script type="text/javascript" src="scripts/jquery.topics.init.js"></script>
    <script type="text/javascript" src="scripts/jquery.subsections.init.js"></script>
    <script type="text/javascript" src="scripts/jquery.clients.init.js"></script>
</body>

</html>
