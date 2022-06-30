<?php

Header("Cache-Control: no-cache, no-store, must-revalidate, max-age=0");
mb_internal_encoding("UTF-8");

try {
    include_once dirname(__FILE__) . '/php/common.php';

    // получение настроек
    $cfg = get_settings();

    // чекбоксы
    $savesubdir = $cfg['savesub_dir'] == 1 ? "checked" : "";
    $retracker = $cfg['retracker'] == 1 ? "checked" : "";
    $proxy_activate_forum = $cfg['proxy_activate_forum'] == 1 ? "checked" : "";
    $proxy_activate_api = $cfg['proxy_activate_api'] == 1 ? "checked" : "";
    $avg_seeders = $cfg['avg_seeders'] == 1 ? "checked" : "";
    $leechers = $cfg['topics_control']['leechers'] ? "checked" : "";
    $no_leechers = $cfg['topics_control']['no_leechers'] ? "checked" : "";
    $tor_for_user = $cfg['tor_for_user'] == 1 ? "checked" : "";
    $enable_auto_apply_filter = $cfg['enable_auto_apply_filter'] == 1 ? "checked" : "";

    // вставка option в select

    // форматы строк
    $optionFormat = '<option value="%s" %s>%s</option>';
    $itemFormat = '<li class="ui-widget-content" value="%s" %s>%s</li>';
    $datasetFormatTorrentClient = 'data-comment="%s" data-type="%s" data-hostname="%s" data-port="%s" data-login="%s" data-password="%s" data-ssl="%s"';
    $datasetFormatForum = 'data-client="%s" data-label="%s" data-savepath="%s" data-subdirectory="%s" data-hide="%s" data-peers="%s"';

    // стандартные адреса
    $forumAddressList = array(
        'rutracker.org',
        'rutracker.net',
        'rutracker.nl',
        'custom'
    );

    $apiAddressList = array(
        'api.t-ru.org',
        'api.rutracker.org',
        'custom'
    );

    // адреса форума
    $optionForumAddress = '';
    foreach ($forumAddressList as $value) {
        $selected = '';
        if ($value == $cfg['forum_url']) {
            $selected = 'selected';
        }
        $text = $value == 'custom' ? 'другой' : $value;
        $optionForumAddress .= sprintf($optionFormat, $value, $selected, $text);
    }
    $forumVerifySSL = $cfg['forum_ssl'] ? 'checked' : '';

    // адреса api
    $optionApiAddress = '';
    foreach ($apiAddressList as $value) {
        $selected = '';
        if ($value == $cfg['api_url']) {
            $selected = 'selected';
        }
        $text = $value == 'custom' ? 'другой' : $value;
        $optionApiAddress .= sprintf($optionFormat, $value, $selected, $text);
    }
    $apiVerifySSL = $cfg['api_ssl'] ? 'checked' : '';

    // торрент-клиенты
    $optionTorrentClients = '';
    $optionTorrentClientsDataset = '';
    if (isset($cfg['clients'])) {
        foreach ($cfg['clients'] as $torrentClientID => $torrentClientData) {
            $datasetTorrentClient = sprintf(
                $datasetFormatTorrentClient,
                $torrentClientData['cm'],
                $torrentClientData['cl'],
                $torrentClientData['ht'],
                $torrentClientData['pt'],
                $torrentClientData['lg'],
                $torrentClientData['pw'],
                $torrentClientData['ssl']
            );
            $optionTorrentClients .= sprintf(
                $optionFormat,
                $torrentClientID,
                '',
                $torrentClientData['cm']
            );
            $optionTorrentClientsDataset .= sprintf(
                $itemFormat,
                $torrentClientID,
                $datasetTorrentClient,
                $torrentClientData['cm']
            );
        }
    }

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
                $forumData['control_peers']
            );
            $optionForums .= sprintf(
                $optionFormat,
                $forumID,
                '',
                $forumData['na']
            );
            $optionForumsDataset .= sprintf(
                $optionFormat,
                $forumID,
                $datasetForum,
                $forumData['na']
            );
        }
    }
} catch (Exception $e) {
    // $e->getMessage();
}

?>

<!DOCTYPE html>
<html class="ui-widget-content">

<head>
    <meta charset="utf-8" />
    <title>web-TLO-2.4.1</title>
    <script type="text/javascript" src="https://ajax.googleapis.com/ajax/libs/jquery/1.12.4/jquery.min.js"></script>
    <script type="text/javascript" src="https://ajax.googleapis.com/ajax/libs/jqueryui/1.12.1/jquery-ui.min.js"></script>
    <script type="text/javascript" src="https://ajax.googleapis.com/ajax/libs/jqueryui/1.11.4/i18n/jquery-ui-i18n.min.js"></script>
    <script src="scripts/jquery.mousewheel.min.js"></script>
    <script src="scripts/js.cookie.min.js"></script>
    <link rel="stylesheet" href="css/reset.css" /> <!-- сброс стилей -->
    <link rel="stylesheet" href="css/style.css" /> <!-- таблица стилей webtlo -->
    <link rel="stylesheet" href="css/font-awesome.min.css">
</head>

<body>
    <div id="menutabs" class="menu">
        <ul class="menu">
            <li class="menu"><a href="#main" class="menu">Главная</a></li>
            <li class="menu"><a href="#settings" class="menu">Настройки</a></li>
            <li class="menu"><a href="#reports" class="menu">Отчёты</a></li>
            <li class="menu"><a href="#statistics" class="menu">Статистика</a></li>
            <li class="menu"><a href="#journal" class="menu">Журнал</a></li>
            <li class="menu"><a href="#manual" class="menu">О программе</a></li>
        </ul>
        <div id="new_version_available">
            <p id="new_version_description"></p>
        </div>
        <div id="content">
            <div id="main" class="content">
                <select id="main-subsections">
                    <optgroup>
                        <option value="-3">Раздачи из всех хранимых подразделов</option>
                        <option value="-5">Раздачи с высоким приоритетом хранения</option>
                        <option value="-2">Раздачи из «чёрного списка»</option>
                        <option value="-4">Хранимые дублирующиеся раздачи</option>
                        <option value="0">Хранимые раздачи из других подразделов</option>
                        <!--
                            <option value="-1">Хранимые раздачи незарегистрированные на трекере</option>
                        -->
                    </optgroup>
                    <optgroup label="Хранимые подразделы" id="main-subsections-stored">
                        <?php echo $optionForums ?>
                    </optgroup>
                </select>
                <div id="topics_data">
                    <div id="topics_control">
                        <div id="toolbar-filter-topics">
                            <button type="button" id="filter_show" title="Скрыть или показать параметры фильтра">
                                <i class="fa fa-filter" aria-hidden="true"></i>
                            </button>
                            <button id="apply_filter" type="button" title="Применить параметры фильтра">
                                <i class="fa fa-check" aria-hidden="true"></i>
                            </button>
                            <button type="button" id="filter_reset" title="Сбросить параметры фильтра на значения по умолчанию">
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
                        <div id="toolbar-new-torrents">
                            <button type="button" id="tor_add" title="Добавить выделенные раздачи текущего подраздела в торрент-клиент">
                                <i class="fa fa-plus" aria-hidden="true"></i>
                            </button>
                            <button type="button" class="tor_download" value="0" title="Скачать *.torrent файлы выделенных раздач текущего подраздела в каталог">
                                <i class="fa fa-download" aria-hidden="true"></i>
                            </button>
                            <button type="button" class="tor_download" value="1" title="Скачать *.torrent-файлы выделенных раздач текущего подраздела в каталог с заменой Passkey">
                                <i class="fa fa-download download-replace" aria-hidden="true"></i>
                                <i class="fa fa-asterisk download-replace-super" aria-hidden="true"></i>
                            </button>
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
                        <button id="update_info" name="update_info" type="button" title="Обновить сведения о раздачах">
                            <i class="fa fa-refresh" aria-hidden="true"></i> Обновить сведения
                        </button>
                        <button id="send_reports" name="send_reports" type="button" title="Отправить отчёты на форум">
                            <i class="fa fa-paper-plane-o" aria-hidden="true"></i> Отправить отчёты
                        </button>
                        <button id="control_torrents" name="control_torrents" type="button" title="Выполнить регулировку раздач в торрент-клиентах">
                            <i class="fa fa-adjust" aria-hidden="true"></i> Регулировка раздач
                        </button>
                        <div id="indication">
                            <i id="loading" class="fa fa-spinner fa-pulse"></i>
                            <div id="process"></div>
                        </div>
                    </div>
                    <form method="post" id="topics_filter">
                        <div class="topics_filter">
                            <div class="filter_block ui-widget">
                                <fieldset title="Статус раздач в торрент-клиенте">
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
                                <fieldset title="Направление сортировки раздач">
                                    <label>
                                        <input type="radio" name="filter_sort_direction" value="1" checked class="default sort" />
                                        по возрастанию
                                    </label>
                                    <label>
                                        <input type="radio" name="filter_sort_direction" value="-1" class="sort" />
                                        по убыванию
                                    </label>
                                </fieldset>
                                <fieldset title="Критерий сортировки раздач">
                                    <label>
                                        <input type="radio" name="filter_sort" value="na" class="sort" />
                                        по названию
                                    </label>
                                    <label>
                                        <input type="radio" name="filter_sort" value="si" class="sort" />
                                        по объёму
                                    </label>
                                    <label>
                                        <input type="radio" name="filter_sort" value="se" checked class="default sort" />
                                        по количеству сидов
                                    </label>
                                    <label>
                                        <input type="radio" name="filter_sort" value="rg" class="sort" />
                                        по дате регистрации
                                    </label>
                                </fieldset>
                            </div>
                            <div class="filter_block ui-widget" title="Статус раздач на трекере">
                                <fieldset>
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
                                <fieldset title="Приоритет раздач на трекере">
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
                                    <label title="Выберите произвольный период средних сидов">
                                        Период средних сидов:
                                        <input type="text" id="filter_avg_seeders_period" name="avg_seeders_period" size="1" value="<?php echo $cfg['avg_seeders_period'] ?>" />
                                    </label>
                                    <label class="date_container ui-widget" title="Отображать раздачи зарегистрированные на форуме до">
                                        Дата регистрации до:
                                        <input type="text" id="filter_date_release" name="filter_date_release" value="<?php echo "-${cfg['rule_date_release']}" ?>" />
                                    </label>
                                </fieldset>
                                <hr />
                                <fieldset title="Поиск раздач по фразе">
                                    <label>
                                        Поиск по фразе:
                                        <input type="search" name="filter_phrase" size="20" />
                                    </label>
                                    <label title="Искать совпадение в названии раздачи">
                                        <input type="radio" name="filter_by_phrase" value="1" class="default" checked />
                                        в названии раздачи
                                    </label>
                                    <label title="Искать совпадение в имени хранителя">
                                        <input type="radio" name="filter_by_phrase" id="filter_by_keeper" value="0" />
                                        в имени хранителя
                                    </label>
                                </fieldset>
                            </div>
                            <div class="filter_block filter_rule ui-widget" title="">
                                <fieldset>
                                    <label title="Отображать только раздачи, для которых информация о сидах содержится за весь период, указанный в настройках (при использовании алгоритма нахождения среднего значения количества сидов)">
                                        <input type="checkbox" name="avg_seeders_complete" />
                                        "зелёные"
                                    </label>
                                    <label title="Отображать только те раздачи, которые никто не хранит из числа других хранителей">
                                        <input type="checkbox" name="not_keepers" class="default keepers" checked />
                                        нет хранителей
                                    </label>
                                    <label title="Отображать только те раздачи, которые хранит кто-то ещё из числа других хранителей">
                                        <input type="checkbox" class="keepers" name="is_keepers" />
                                        есть хранители
                                    </label>
                                    <label title="Отображать только те раздачи, которые никто не сидирует из числа других хранителей">
                                        <input type="checkbox" class="keepers_seeders" name="not_keepers_seeders" />
                                        нет сидов-хранителей
                                    </label>
                                    <label title="Отображать только те раздачи, которые кто-то сидирует из числа других хранителей">
                                        <input type="checkbox" class="keepers_seeders" name="is_keepers_seeders" />
                                        есть сиды-хранители
                                    </label>
                                </fieldset>
                                <hr />
                                <label title="Использовать интервал сидов">
                                    <input type="checkbox" name="filter_interval" />
                                    интервал
                                </label>
                                <fieldset class="filter_rule_one" title="Количество сидов на раздаче">
                                    <label>
                                        <input type="radio" name="filter_rule_direction" value="1" checked class="default" />
                                        не более
                                    </label>
                                    <label>
                                        <input type="radio" name="filter_rule_direction" value="0" />
                                        не менее
                                    </label>
                                    <label class="filter_rule_value" title="Количество сидов">
                                        <input type="text" id="filter_rule" name="filter_rule" size="1" value="<?php echo $cfg['rule_topics'] ?>" />
                                    </label>
                                </fieldset>
                                <fieldset class="filter_rule_interval">
                                    <label class="filter_rule_value" title="Начальное количество сидов">
                                        от
                                        <input type="text" id="filter_rule_from" name="filter_rule_interval[from]" size="1" value="0" />
                                    </label>
                                    <label class="filter_rule_value" title="Конечное количество сидов">
                                        до
                                        <input type="text" id="filter_rule_to" name="filter_rule_interval[to]" size="1" value="<?php echo $cfg['rule_topics'] ?>" />
                                    </label>
                                </fieldset>
                            </div>
                        </div>
                    </form>
                    <div class="status_info">
                        <div id="counter">Выбрано раздач: <span id="topics_count" class="bold">0</span> (<span id="topics_size">0.00</span>) из <span id="filtered_topics_count" class="bold">0</span> (<span id="filtered_topics_size">0.00</span>)</div>
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
                        <h2>Настройки авторизации на форуме</h2>
                        <div>
                            <button type="button" id="check_mirrors_access" title="Проверить доступность форума и API">
                                Проверить доступ
                            </button>
                            <button type="button" id="forum_auth" title="Авторизоваться на форуме">
                                Авторизоваться
                                <i id="forum_auth_result"></i>
                            </button>
                            <div id="forum_url_params">
                                <label>
                                    Используемый адрес форума:
                                    <select name="forum_url" id="forum_url" class="myinput">
                                        <?php echo $optionForumAddress ?>
                                    </select>
                                </label>
                                <input id="forum_url_custom" name="forum_url_custom" class="myinput" type="text" size="14" value="<?php echo $cfg['forum_url_custom'] ?>" />
                                <label title="Использовать SSL/TLS">
                                    <input id="forum_ssl" name="forum_ssl" type="checkbox" <?php echo $forumVerifySSL ?> />
                                    SSL/TLS
                                </label>
                                <i id="forum_url_result" class=""></i>
                            </div>
                            <div id="api_url_params">
                                <label>
                                    Используемый адрес API:
                                    <select name="api_url" id="api_url" class="myinput">
                                        <?php echo $optionApiAddress ?>
                                    </select>
                                </label>
                                <input id="api_url_custom" name="api_url_custom" class="myinput" type="text" size="14" value="<?php echo $cfg['api_url_custom'] ?>" />
                                <label title="Использовать SSL/TLS">
                                    <input id="api_ssl" name="api_ssl" type="checkbox" <?php echo $apiVerifySSL ?> />
                                    SSL/TLS
                                </label>
                                <i id="api_url_result" class=""></i>
                            </div>
                            <div id="forum_auth_params">
                                <div>
                                    <label>
                                        Логин:
                                        <input id="tracker_username" name="tracker_username" class="myinput" type="text" size="24" title="Логин на http://rutracker.org" value="<?php echo $cfg['tracker_login'] ?>" />
                                    </label>
                                    <label>
                                        Пароль:
                                        <input id="tracker_password" name="tracker_password" class="myinput" type="password" size="24" title="Пароль на http://rutracker.org" value="<?php echo $cfg['tracker_paswd'] ?>" />
                                    </label>
                                </div>
                            </div>
                            <div id="api_auth_params">
                                <div>
                                    <label>
                                        Ключ bt:
                                        <input id="bt_key" name="bt_key" class="myinput user_details" type="password" size="24" title="Хранительский ключ bt" value="<?php echo $cfg['bt_key'] ?>" />
                                    </label>
                                    <label>
                                        Ключ api:
                                        <input id="api_key" name="api_key" class="myinput user_details" type="password" size="24" title="Хранительский ключ api" value="<?php echo $cfg['api_key'] ?>" />
                                    </label>
                                    <label>
                                        Ключ id:
                                        <input id="user_id" name="user_id" class="myinput user_details" type="text" size="24" title="Идентификатор пользователя" value="<?php echo $cfg['user_id'] ?>" />
                                    </label>
                                </div>
                            </div>
                        </div>
                        <h2>Настройки прокси-сервера</h2>
                        <div>
                            <div>
                                <label title="Использовать прокси-сервер при обращении к форуму, например, для обхода блокировки.">
                                    <input name="proxy_activate_forum" type="checkbox" size="24" <?php echo $proxy_activate_forum ?> />
                                    использовать прокси-сервер при обращении к форуму
                                </label>
                            </div>
                            <div>
                                <label title="Использовать прокси-сервер при обращении к API, например, для обхода блокировки.">
                                    <input name="proxy_activate_api" type="checkbox" size="24" <?php echo $proxy_activate_api ?> />
                                    использовать прокси-сервер при обращении к API
                                </label>
                            </div>
                            <div id="proxy_prop">
                                <div>
                                    <label>
                                        Тип прокси-сервера:
                                        <select name="proxy_type" id="proxy_type" class="myinput" title="Тип прокси-сервера">
                                            <option value="http" <?php echo ($cfg['proxy_type'] == 'http' ? "selected" : "") ?>>HTTP</option>
                                            <option value="socks4" <?php echo ($cfg['proxy_type'] == 'socks4' ? "selected" : "") ?>>SOCKS4</option>
                                            <option value="socks4a" <?php echo ($cfg['proxy_type'] == 'socks4a' ? "selected" : "") ?>>SOCKS4A</option>
                                            <option value="socks5" <?php echo ($cfg['proxy_type'] == 'socks5' ? "selected" : "") ?>>SOCKS5</option>
                                            <option value="socks5h" <?php echo ($cfg['proxy_type'] == 'socks5h' ? "selected" : "") ?>>SOCKS5H</option>
                                        </select>
                                    </label>
                                </div>
                                <div>
                                    <label>
                                        IP-адрес/сетевое имя:
                                        <input name="proxy_hostname" id="proxy_hostname" class="myinput" type="text" size="24" title="IP-адрес или сетевое/доменное имя прокси-сервера." value="<?php echo $cfg['proxy_hostname'] ?>" />
                                    </label>
                                    <label>
                                        Порт:
                                        <input name="proxy_port" id="proxy_port" class="myinput" type="text" size="24" title="Порт прокси-сервера." value="<?php echo $cfg['proxy_port'] ?>" />
                                    </label>
                                </div>
                                <div>
                                    <label>
                                        Логин:
                                        <input name="proxy_login" id="proxy_login" class="myinput" type="text" size="24" title="Имя пользователя для доступа к прокси-серверу (необязательно)." value="<?php echo $cfg['proxy_login'] ?>" />
                                    </label>
                                    <label>
                                        Пароль:
                                        <input name="proxy_paswd" id="proxy_paswd" class="myinput" type="password" size="24" title="Пароль для доступа к прокси-серверу (необязатально)." value="<?php echo $cfg['proxy_paswd'] ?>" />
                                    </label>
                                </div>
                            </div>
                        </div>
                        <h2>Настройки торрент-клиентов</h2>
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
                                    <?php echo $optionTorrentClientsDataset ?>
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
                                            <option value="vuze" title="Web Remote plugin">Vuze</option>
                                            <option value="deluge" title="WebUi plugin">Deluge</option>
                                            <option value="qbittorrent">qBittorrent</option>
                                            <option value="ktorrent">KTorrent</option>
                                            <option value="rtorrent">rTorrent</option>
                                        </select>
                                    </label>
                                </div>
                                <div>
                                    <label>
                                        IP-адрес/сетевое имя:
                                        <input name="torrent-client-hostname" id="torrent-client-hostname" class="torrent-client-props" type="text" size="24" title="IP-адрес или сетевое/доменное имя компьютера с запущенным торрент-клиентом." />
                                        <input name="torrent-client-ssl" id="torrent-client-ssl" class="torrent-client-props" type="checkbox" title="Использовать SSL/TLS" />
                                        SSL/TLS
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
                            </div>
                        </div>
                        <h2>Настройки сканируемых подразделов</h2>
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
                                    <select name="list-forums" id="list-forums">
                                        <?php echo $optionForumsDataset ?>
                                    </select>
                                </label>
                            </div>
                            <fieldset id="forum-props">
                                <label>
                                    Индекс:
                                    <input disabled id="forum-id" class="myinput forum-props ui-state-disabled" type="text" title="Индекс подраздела" />
                                </label>
                                <label>
                                    Торрент-клиент:
                                    <select id="forum-client" class="myinput forum-props" title="Добавлять раздачи текущего подраздела в торрент-клиент">
                                        <option value=0>не выбран</option>
                                        <?php echo $optionTorrentClients ?>
                                    </select>
                                </label>
                                <label>
                                    Метка:
                                    <input id="forum-label" class="myinput forum-props" type="text" size="50" title="При добавлении раздачи установить для неё метку (поддерживаются только Deluge, qBittorrent и uTorrent)" />
                                </label>
                                <label>
                                    Каталог для данных:
                                    <input id="forum-savepath" class="myinput forum-props" type="text" size="57" title="При добавлении раздачи данные сохранять в каталог (поддерживаются все кроме KTorrent)" />
                                </label>
                                <label>
                                    Создавать подкаталог для добавляемой раздачи:
                                    <select id="forum-subdirectory" class="myinput forum-props" title="Создавать подкаталог для данных добавляемой раздачи">
                                        <option value="0">нет</option>
                                        <option value="1">ID раздачи</option>
                                        <!-- <option value="2">Запрашивать</option> -->
                                    </select>
                                </label>
                                <label>
                                    Скрывать раздачи в общем списке:
                                    <select id="forum-hide-topics" class="myinput forum-props" title="Позволяет скрыть раздачи текущего подраздела из списка 'Раздачи из всех хранимых подразделов'">
                                        <option value="0">нет</option>
                                        <option value="1">да</option>
                                    </select>
                                </label>
                                <label>
                                    Останавливать раздачи с количеством пиров более:
                                    <input id="forum-control-peers" class="myinput forum-props" type="text" size="10" title="Укажите числовое значение пиров, при котором требуется останавливать раздачи текущего подраздела в торрент-клиентах. Либо оставьте это поле пустым, чтобы использовать глобальное значение для регулировки раздач. Значение равное -1 вовсе исключит подраздел из регулировки" />
                                </label>
                            </fieldset>
                        </div>
                        <h2>Настройки управления раздачами</h2>
                        <div>
                            <h3>Фильтрация раздач</h3>
                            <label class="label" title="Укажите числовое значение количества сидов (по умолчанию: 3)">
                                Предлагать для хранения раздачи с количеством сидов не более:
                                <input id="rule_topics" name="rule_topics" type="text" size="2" value="<?php echo $cfg['rule_topics'] ?>" />
                            </label>
                            <label class="label" title="Укажите необходимое количество дней">
                                Предлагать для хранения раздачи старше
                                <input id="rule_date_release" name="rule_date_release" type="text" size="2" value="<?php echo $cfg['rule_date_release'] ?>" />
                                дн.
                            </label>
                            <label class="label" title="Если перерыв между обновлениями сведений составит больше этого периода, то накопленные данные о сидах будут считаться устаревшими (по умолчанию: 7)">
                                Допустимый период простоя между обновлениями:
                                <input id="avg_seeders_period_outdated" name="avg_seeders_period_outdated" type="text" size="2" value="<?php echo $cfg['avg_seeders_period_outdated'] ?>" />
                                дн.
                            </label>
                            <label class="label" title="При фильтрации раздач будет использоваться среднее значение количества сидов вместо мгновенного (по умолчанию: выключено)">
                                <input id="avg_seeders" name="avg_seeders" type="checkbox" <?php echo $avg_seeders ?> />
                                находить среднее значение количества сидов за
                                <input id="avg_seeders_period" name="avg_seeders_period" title="Укажите период хранения сведений о средних сидах, максимум 30 дней (по умолчанию: 14)" type="text" size="2" value="<?php echo $cfg['avg_seeders_period'] ?>" />
                                дн.
                            </label>
                            <label class="label" title="При изменении параметров фильтра, автоматически обновлять список раздач на главной">
                                <input id="enable_auto_apply_filter" name="enable_auto_apply_filter" type="checkbox" <?php echo $enable_auto_apply_filter ?> />
                                применять параметры фильтра автоматически
                            </label>
                            <h3>Регулировка раздач<sup>1</sup></h3>
                            <label class="label" title="Укажите числовое значение пиров, при котором требуется останавливать раздачи в торрент-клиентах (по умолчанию: 10)">
                                Останавливать раздачи с количеством пиров более:
                                <input id="peers" name="peers" type="text" size="2" value="<?php echo $cfg['topics_control']['peers'] ?>" />
                            </label>
                            <label class="label" title="Установите, если необходимо учитывать значение личей при регулировке, иначе будут браться только значения сидов (по умолчанию: выключено)">
                                <input name="leechers" type="checkbox" <?php echo $leechers ?> />
                                учитывать значение личей
                            </label>
                            <label class="label" title="Выберите, если нужно запускать раздачи с 0 (нулём) личей, когда нет скачивающих (по умолчанию: включено)">
                                <input name="no_leechers" type="checkbox" <?php echo $no_leechers ?> />
                                запускать раздачи с 0 (нулём) личей
                            </label>
                            <p class="footnote"><sup>1</sup>Необходимо настроить запуск скрипта control.php. Обратитесь к <a target="_blank" href="https://github.com/berkut-174/webtlo/wiki/Automation-scripts">этой</a> странице за подробностями.</p>
                        </div>
                        <h2>Настройки загрузки торрент-файлов</h2>
                        <div>
                            <h3>Каталог для скачиваемых *.torrent файлов</h3>
                            <div>
                                <input id="savedir" name="savedir" class="myinput" type="text" size="53" title="Каталог, куда будут сохраняться новые *.torrent-файлы." value="<?php echo $cfg['save_dir'] ?>" />
                            </div>
                            <label title="При установленной метке *.torrent-файлы дополнительно будут помещены в подкаталог.">
                                <input name="savesubdir" type="checkbox" size="24" <?php echo $savesubdir ?> />
                                создавать подкаталоги
                            </label>
                            <h3>Настройки retracker.local</h3>
                            <label title="Добавлять retracker.local в скачиваемые *.torrent-файлы.">
                                <input name="retracker" type="checkbox" size="24" <?php echo $retracker ?> />
                                добавлять retracker.local в скачиваемые *.torrent-файлы
                            </label>
                            <h3>Скачивание *.torrent файлов с заменой Passkey</h3>
                            <label class="label">
                                Каталог:
                                <input id="dir_torrents" name="dir_torrents" class="myinput" type="text" size="53" title="Каталог, в который требуется сохранять торрент-файлы с изменённым Passkey." value="<?php echo $cfg['dir_torrents'] ?>" />
                            </label>
                            <label class="label">
                                Passkey:
                                <input id="passkey" name="passkey" class="myinput" type="text" size="15" title="Passkey, который необходимо вшить в скачиваемые торрент-файлы." value="<?php echo $cfg['user_passkey'] ?>" />
                            </label>
                            <label>
                                <input name="tor_for_user" type="checkbox" size="24" <?php echo $tor_for_user ?> />
                                скачать торрент-файлы для обычного пользователя
                            </label>
                        </div>
                        <h2>Настройки интерфейса</h2>
                        <div>
                            <label>
                                Цветовая схема:
                                <select id="theme-selector" class="myinput">
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
                        </div>
                    </div>
                </form>
            </div>
            <div id="reports" class="content">
                <select id="reports-subsections">
                    <optgroup>
                        <option value="" disabled selected>Выберите подраздел из выпадающего списка</option>
                    </optgroup>
                    <optgroup>
                        <option value="0">Сводный отчёт</option>
                    </optgroup>
                    <optgroup label="Хранимые подразделы" id="reports-subsections-stored">
                        <?php echo $optionForums ?>
                    </optgroup>
                </select>
                <div id="reports-content"></div>
            </div>
            <div id="statistics" class="content">
                <div>
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
                <div>
                    <button type="button" id="clear_log" title="Очистить содержимое лога">
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
                <p>web-TLO</p>
                <p>Простое веб-приложение для управления торрентами</p>
                <p><a href="https://github.com/berkut-174/webtlo/wiki" target="_blank">https://github.com/berkut-174/webtlo/wiki</a></p>
                <p>Copyright © 2016-2022 Alexander Shemetov</p>
            </div>
        </div>
    </div>
    <div id="dialog" title="Сообщение"></div>
    <!-- скрипты webtlo -->
    <script type="text/javascript" src="scripts/jquery.common.js"></script>
    <script type="text/javascript" src="scripts/jquery.tor_clients.js"></script>
    <script type="text/javascript" src="scripts/jquery.subsections.js"></script>
    <script type="text/javascript" src="scripts/jquery.actions.js"></script>
    <script type="text/javascript" src="scripts/jquery.widgets.js"></script>
    <script type="text/javascript" src="scripts/jquery.topics.js"></script>
</body>

</html>