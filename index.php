<?php

Header("Cache-Control: no-cache, no-store, must-revalidate, max-age=0");
mb_internal_encoding("UTF-8");

include dirname(__FILE__) . '/common.php';

if(!ini_get('date.timezone')) date_default_timezone_set('Europe/Moscow');

// получение настроек
$cfg = get_settings();
	
// торрент-клиенты
if(isset($cfg['clients'])){
	foreach($cfg['clients'] as $id => $tc){
		$tcs[] = '<option value="'.$id.'" data="'.implode('|', $tc).'">'.$tc['cm'].'</option>';
	}
	$tcs = implode('', $tcs);
} else $tcs = '';

// подразделы
if(isset($cfg['subsections'])){
	foreach($cfg['subsections'] as $id => &$ss){
		$subsections[] = '<option value="'.$id.'" data="'.implode('|', $ss).'">'.$ss['na'].'</option>';
	}
	$subsections = implode('', $subsections);
} else $subsections = '';

// чекбоксы
$savesubdir = ($cfg['savesub_dir'] == 1 ? "checked" : "");
$retracker = ($cfg['retracker'] == 1 ? "checked" : "");
$proxy_activate_forum = ( $cfg['proxy_activate_forum'] == 1 ? "checked" : "" );
$proxy_activate_api = ( $cfg['proxy_activate_api'] == 1 ? "checked" : "" );
$avg_seeders = ($cfg['avg_seeders'] == 1 ? "checked" : "");
$leechers = $cfg['topics_control']['leechers'] ? "checked" : "";
$no_leechers = $cfg['topics_control']['no_leechers'] ? "checked" : "";
$tor_for_user = $cfg['tor_for_user'] == 1 ? "checked" : "";

?>

<!DOCTYPE html>
<html>
	<head>
		<meta charset="utf-8" />
		<title>web-TLO-0.9.3.10</title>
		<script src="jquery-ui-1.12.1/jquery.js"></script>
		<script src="jquery-ui-1.12.1/jquery-ui.js"></script>
		<script src="jquery-ui-1.12.1/datepicker-ru.js"></script>
		<script src="jquery-ui-1.12.1/external/jquery.mousewheel.js"></script>
		<script src="jquery-ui-1.12.1/external/js.cookie.js"></script>
		<link rel="stylesheet" href="css/reset.css" /> <!-- сброс стилей -->
		<link rel="stylesheet" href="jquery-ui-1.12.1/jquery-ui.css" />
		<link rel="stylesheet" href="css/style.css" /> <!-- таблица стилей webtlo -->
		<link rel="stylesheet" href="css/font-awesome.min.css">
		<link rel="icon" href="img/favicon.ico">
	</head>
	<body>
		<div id="menutabs" class="menu">
			<ul class="menu">
				<li class="menu"><a href="#main" class="menu">Главная</a></li>
				<li class="menu"><a href="#settings" class="menu">Настройки</a></li>
				<li class="menu"><a href="#reports" class="menu">Отчёты</a></li>
				<li class="menu"><a href="#statistics" class="menu">Статистика</a></li>
				<li class="menu"><a href="#journal" class="menu">Журнал</a></li>
				<li class="menu"><a href="#manual" title="Открыть файл руководства">FAQ</a></li>
			</ul>
			<div id="content">
				<div id="main" class="content">
					<select id="subsections">
						<optgroup id="subsections_stored">
							<?php echo $subsections ?>
						</optgroup>
						<optgroup label="Прочее">
							<option value="0">Хранимые раздачи из других подразделов</option>
<!--
							<option value="-1">Хранимые раздачи незарегистрированные на трекере</option>
-->
							<option value="-2">Раздачи из "чёрного списка"</option>
							<option value="-3">Раздачи из всех хранимых подразделов</option>
						</optgroup>
					</select>
					<div id="topics_data">
						<div id="topics_control">
							<div id="filter">
								<button type="button" id="filter_show" title="Скрыть или показать настройки фильтра">
									<i class="fa fa-filter" aria-hidden="true"></i>
								</button>
								<button type="button" id="filter_reset" title="Сбросить настройки фильтра на значения по умолчанию">
									<i class="fa fa-undo" aria-hidden="true"></i>
								</button>
							</div>
							<div id="select">
								<button type="button" class="tor_select" value="1" title="Выделить все раздачи текущего подраздела">
									<i class="fa fa-check-square-o" aria-hidden="true"></i>
								</button>
								<button type="button" class="tor_select" title="Снять выделение всех раздач текущего подраздела">
									<i class="fa fa-square-o" aria-hidden="true"></i>
								</button>
							</div>
							<div id="new-torrents">
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
								<button type="button" id="tor_blacklist" value="1" title="Включить выделенные раздачи в чёрный список или наоборот исключить">
									<i class="fa fa-ban" aria-hidden="true"></i>
								</button>
							</div>
							<div id="control">
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
							<button id="update" name="update" type="button" title="Обновить сведения о раздачах">
								<i class="fa fa-refresh" aria-hidden="true"></i> Обновить сведения
							</button>
							<button id="startreports" name="startreports" type="button" title="Сформировать отчёты для вставки на форум">
								<i class="fa fa-file-text-o" aria-hidden="true"></i> Создать отчёты
							</button>
							<button id="sendreports" name="sendreports" type="button" title="Отправить отчёты на форум">
								<i class="fa fa-paper-plane-o" aria-hidden="true"></i> Отправить отчёты
							</button>
							<div id="indication">
								<i id="loading" class="fa fa-spinner fa-pulse"></i>
								<div style="display:none;" id="process"></div>
							</div>
						</div>
						<form method="post" id="topics_filter">
							<div class="topics_filter">
								<div class="filter_block ui-widget">
									<fieldset title="Статусы раздач в торрент-клиенте">
										<label>
											<input type="checkbox" name="filter_status[]" value="1" />
											храню
										</label>
										<label>
											<input type="checkbox" name="filter_status[]" value="0" checked class="default" />
											не храню
										</label>
										<label>
											<input type="checkbox" name="filter_status[]" value="-1" />
											качаю
										</label>
									</fieldset>
									<fieldset>
										<label title="Отображать только раздачи, для которых информация о сидах содержится за весь период, указанный в настройках (при использовании алгоритма нахождения среднего значения количества сидов)">
											<input type="checkbox" name="avg_seeders_complete" />
											"зелёные"
										</label>
										<label title="Отображать только те раздачи, которые никто не хранит из числа других хранителей">
											<input type="checkbox" class="keepers" name="not_keepers" />
											нет хранителей
										</label>
										<label title="Отображать только те раздачи, которые хранит кто-то ещё из числа других хранителей">
											<input type="checkbox" class="keepers" name="is_keepers" />
											есть хранители
										</label>
									</fieldset>
								</div>
								<div class="filter_block ui-widget" title="Статусы раздач на трекере">
									<fieldset>
										<label>
											<input type="checkbox" name="filter_tor_status[]" value="0" />
											не проверено
										</label>
										<label>
											<input type="checkbox" name="filter_tor_status[]" value="2" checked class="default" />
											проверено
										</label>
										<label>
											<input type="checkbox" name="filter_tor_status[]" value="3" />
											недооформлено
										</label>
										<label>
											<input type="checkbox" name="filter_tor_status[]" value="8" checked class="default" />
											сомнительно
										</label>
										<label>
											<input type="checkbox" name="filter_tor_status[]" value="10" />
											временная
										</label>
									</fieldset>
								</div>
								<div class="filter_block ui-widget" title="Сортировка">
									<fieldset>
										<label>
											<input type="radio" name="filter_sort_direction" value="1" checked class="default" />
											по возрастанию
										</label>
										<label>
											<input type="radio" name="filter_sort_direction" value="-1" />
											по убыванию
										</label>
									</fieldset>
									<fieldset>
										<label>
											<input type="radio" name="filter_sort" value="na" />
											по названию
										</label>
										<label>
											<input type="radio" name="filter_sort" value="si" />
											по объёму
										</label>
										<label>
											<input type="radio" name="filter_sort" value="avg" checked class="default" />
											по количеству сидов
										</label>
										<label>
											<input type="radio" name="filter_sort" value="rg" />
											по дате регистрации
										</label>
									</fieldset>
								</div>
								<div class="filter_block ui-widget" title="Поиск по фразе">
									<fieldset>
										<label title="Введите фразу для поиска">
											Поиск по фразе:
											<input type="search" name="filter_phrase" size="20"/>
										</label>
										<label>
											<input type="radio" name="filter_by_phrase" value="1" checked class="default" />
											в названии раздачи
										</label>
										<label>
											<input type="radio" name="filter_by_phrase" id="filter_by_keeper" value="0" />
											в имени хранителя
										</label>
									</fieldset>
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
								</div>
								<div class="filter_block filter_rule ui-widget" title="Сиды">
									<label title="Использовать интервал сидов">
										<input type="checkbox" name="filter_interval" />
										интервал
									</label>
									<fieldset class="filter_rule_one">
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
									<fieldset class="filter_rule_interval" style="display: none">
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
						<hr />
						<div class="status_info">
							<div id="counter">Выбрано раздач: <span id="topics_count" class="rp-header">0</span> (<span id="topics_size">0.00</span>) из <span id="filtered_topics_count" class="rp-header">0</span> (<span id="filtered_topics_size">0.00</span>)</div>
							<div id="topics_result"></div>
						</div>
						<hr />
						<form id="topics" method="post"></form>
					</div>
				</div>
				<div id="settings" class="content">
					<div>
						<input id="savecfg" name="savecfg" type="button" value="Сохранить настройки" title="Записать настройки в файл">
					</div>
					<form id="config">
						<div class="sub_settings">
							<h2>Настройки авторизации на форуме</h2>
							<div>
								<input id="check_mirrors_access" type="button" value="Проверить доступ" title="Проверить доступность форума и API" />
								<div>
									<label>
										Используемый адрес форума:
										<select name="forum_url" id="forum_url" class="myinput">
											<option value="http://rutracker.cr" <?php echo ($cfg['forum_url'] == 'http://rutracker.cr' ? "selected" : "") ?> >http://rutracker.cr</option>
											<option value="http://rutracker.net" <?php echo ($cfg['forum_url'] == 'http://rutracker.net' ? "selected" : "") ?> >http://rutracker.net</option>
											<option value="http://rutracker.org" <?php echo ($cfg['forum_url'] == 'http://rutracker.org' ? "selected" : "") ?> >http://rutracker.org</option>
											<option value="http://rutracker.nl" <?php echo ($cfg['forum_url'] == 'http://rutracker.nl' ? "selected" : "") ?> >http://rutracker.nl</option>
											<option value="https://rutracker.cr" <?php echo ($cfg['forum_url'] == 'https://rutracker.cr' ? "selected" : "") ?> >https://rutracker.cr</option>
											<option value="https://rutracker.net" <?php echo ($cfg['forum_url'] == 'https://rutracker.net' ? "selected" : "") ?> >https://rutracker.net</option>
											<option value="https://rutracker.org" <?php echo ($cfg['forum_url'] == 'https://rutracker.org' ? "selected" : "") ?> >https://rutracker.org</option>
											<option value="https://rutracker.nl" <?php echo ($cfg['forum_url'] == 'https://rutracker.nl' ? "selected" : "") ?> >https://rutracker.nl</option>
										</select>
										<i id="forum_url_result" class=""></i>
									</label>
								</div>
								<div>
									<label>
										Используемый адрес API:
										<select name="api_url" id="api_url" class="myinput">
											<option value="http://api.rutracker.cc" <?php echo ($cfg['api_url'] == 'http://api.rutracker.cc' ? "selected" : "") ?> >http://api.rutracker.cc</option>
											<option value="http://api.t-ru.org" <?php echo ($cfg['api_url'] == 'http://api.t-ru.org' ? "selected" : "") ?> >http://api.t-ru.org</option>
											<option value="http://api.rutracker.org" <?php echo ($cfg['api_url'] == 'http://api.rutracker.org' ? "selected" : "") ?> >http://api.rutracker.org</option>
											<option value="https://api.rutracker.cc" <?php echo ($cfg['api_url'] == 'https://api.rutracker.cc' ? "selected" : "") ?> >https://api.rutracker.cc</option>
											<option value="https://api.t-ru.org" <?php echo ($cfg['api_url'] == 'https://api.t-ru.org' ? "selected" : "") ?> >https://api.t-ru.org</option>
											<option value="https://api.rutracker.org" <?php echo ($cfg['api_url'] == 'https://api.rutracker.org' ? "selected" : "") ?> >https://api.rutracker.org</option>
										</select>
										<i id="api_url_result" class=""></i>
									</label>
								</div>
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
												<option value="http" <?php echo ($cfg['proxy_type'] == 'http' ? "selected" : "") ?> >HTTP</option>
												<option value="socks4" <?php echo ($cfg['proxy_type'] == 'socks4' ? "selected" : "") ?> >SOCKS4</option>
												<option value="socks4a" <?php echo ($cfg['proxy_type'] == 'socks4a' ? "selected" : "") ?> >SOCKS4A</option>
												<option value="socks5" <?php echo ($cfg['proxy_type'] == 'socks5' ? "selected" : "") ?> >SOCKS5</option>
												<option value="socks5h" <?php echo ($cfg['proxy_type'] == 'socks5h' ? "selected" : "") ?> >SOCKS5H</option>
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
								<p>
									<input name="add-tc" id="add-tc" type="button" value="Добавить" title="Добавить новый торрент-клиент в список" />
									<input name="del-tc" id="del-tc" type="button" value="Удалить" title="Удалить выбранный торрент-клиент из списка" />
									<button name="online-tc" id="online-tc" type="button" title="Проверить доступность выбранного торрент-клиента в списке">
										<i id="checking" class="fa fa-spinner fa-spin"></i> Проверить
									</button>
									<span id="result-tc"></span>
								</p>
								
								<div class="block-settings">											
									<select id="list-tcs" size=10>
										<option value=0 data="0" disabled>список торрент-клиентов</option>
										<?php echo $tcs ?>
									</select>
								</div>
								<div class="block-settings" id="tc-prop">
									<div>
										<label>
											Название (комментарий)
											<input name="TC_comment" id="TC_comment" class="tc-prop" type="text" size="24" title="Комментарий" />
										</label>
										<label>
											Торрент-клиент
											<select name="TC_client" id="TC_client" class="tc-prop">
												<option value="utorrent">uTorrent</option>
												<option value="transmission">Transmission</option>
												<option value="vuze" title="Web Remote   plugin">Vuze</option>
												<option value="deluge" title="WebUi   plugin">Deluge</option>
												<option value="qbittorrent">qBittorrent</option>
												<option value="ktorrent">KTorrent</option>
												<option value="rtorrent">rTorrent 0.9.x</option>
											</select>
										</label>
									</div>
									<div>
										<label>
											IP-адрес/сетевое имя:
											<input name="TC_hostname" id="TC_hostname" class="tc-prop" type="text" size="24" title="IP-адрес или сетевое/доменное имя компьютера с запущенным торрент-клиентом." />
										</label>
										<label>
											Порт:
											<input name="TC_port" id="TC_port" class="tc-prop" type="text" size="24" title="Порт веб-интерфейса торрент-клиента." />
										</label>
									</div>
									<div>
										<label>
											Логин:
											<input name="TC_login" id="TC_login" class="tc-prop" type="text" size="24" title="Логин для доступа к веб-интерфейсу торрент-клиента (необязатально)." />
										</label>
										<label>
											Пароль:
											<input name="TC_password" id="TC_password" class="tc-prop" type="password" size="24" title="Пароль для доступа к веб-интерфейсу торрент-клиента (необязатально)." />
										</label>
									</div>
								</div>
							</div>
							<h2>Настройки сканируемых подразделов</h2>
							<div>
								<input id="ss-add" class="myinput" type="text" size="100" placeholder="Для добавления подраздела начните вводить его индекс или название" title="Добавить новый подраздел" />
								<input id="ss-del" type="button" value="Удалить" title="Удалить выбранный подраздел" />
								<label class="flex">
									Подраздел:
									<select name="list-ss" id="list-ss">
										<?php echo $subsections ?>
									</select>
								</label>
								<fieldset id="ss-prop">
									<label class="flex">
										Индекс:
										<input disabled id="ss-id" class="myinput ss-prop" type="text" title="Индекс подраздела" />
									</label>
									<label class="flex">
										Торрент-клиент:
										<select id="ss-client" class="myinput ss-prop" title="Добавлять раздачи текущего подраздела в торрент-клиент">
											<option value=0>не выбран</option>
										</select>
									</label>
									<label class="flex">
										Метка:
										<input id="ss-label" class="myinput ss-prop" type="text" size="50" title="При добавлении раздачи установить для неё метку (поддерживаются только Deluge, qBittorrent и uTorrent)" />
									</label>
									<label class="flex">
										Каталог для данных:
										<input id="ss-folder" class="myinput ss-prop" type="text" size="57" title="При добавлении раздачи данные сохранять в каталог (поддерживаются все кроме KTorrent)" />
									</label>
									<label class="flex">
										Ссылка на список:
										<input id="ss-link" class="myinput ss-prop" type="text" size="55" title="Ссылка для отправки отчётов на форум (например, https://rutracker.org/forum/viewtopic.php?t=3572968)" />
									</label>
									<label class="flex">
										Создавать подкаталог для добавляемой раздачи:
										<select id="ss-sub-folder" class="myinput ss-prop" title="Создавать подкаталог для данных добавляемой раздачи">
											<option value="0">нет</option>
											<option value="1">ID раздачи</option>
											<!-- <option value="2">Запрашивать</option> -->
										</select>
									</label>
									<label class="flex">
										Скрывать раздачи в общем списке:
										<select id="ss-hide-topics" class="myinput ss-prop" title="Позволяет скрыть раздачи текущего подраздела из списка 'Раздачи из всех хранимых подразделов'">
											<option value="0">нет</option>
											<option value="1">да</option>
										</select>
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
								<label class="label" title="При фильтрации раздач будет использоваться среднее значение количества сидов вместо мгновенного (по умолчанию: выключено)">
									<input id="avg_seeders" name="avg_seeders" type="checkbox" size="24" <?php echo $avg_seeders ?> />
									находить среднее значение количества сидов за
									<input id="avg_seeders_period" name="avg_seeders_period" title="Укажите период хранения сведений о средних сидах, максимум 30 дней (по умолчанию: 14)" type="text" size="2" value="<?php echo $cfg['avg_seeders_period'] ?>"/>
									дн.
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
								<p class="footnote"><sup>1</sup>Необходимо настроить запуск скрипта control.php. Обратитесь к п.5 <a target="_blank" href="manual.pdf">руководства</a> за подробностями.</p>
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
						</div>
					</form>
				</div>					
				<div id="reports" class="content"></div>
				<div id="statistics" class="content">
					<div>
						<button type="button" id="get_statistics" title="Получить статистику по хранимым подразделам">
							Отобразить статистику
						</button>
					</div>
					<div id="data_statistics">
						<table id="table_statistics">
							<thead>
								<tr>
									<th colspan="2">Подраздел</th>
									<th colspan="10">Количество и вес раздач</th>
								</tr>
								<tr>
									<th>ID</th>
									<th width="40%">Название</th>
									<th colspan="2">сc == 0</th>
									<th colspan="2">0.0 < cc <= 0.5</th>
									<th colspan="2">0.5 < сc <= 1.0</th>
									<th colspan="2">1.0 < сc <= 1.5</th>
									<th colspan="2">Всего в подразделе</th>
								</tr>
							</thead>
							<tbody>
								<tr>
									<td colspan="12">&mdash;</td>
								</tr>
							</tbody>
							<tfoot></tfoot>
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
					<object data="manual.pdf" type="application/pdf" width="100%" height="100%"></object>
				</div>
			</div>
		</div>
		<div id="dialog" title="Сообщение"></div>
		<!-- скрипты webtlo -->
		<script type="text/javascript" src="js/common.js"></script>
		<script type="text/javascript" src="js/tor_clients.js"></script>
		<script type="text/javascript" src="js/subsections.js"></script>
		<script type="text/javascript" src="js/webtlo.js"></script>
		<script type="text/javascript" src="js/topics.js"></script>		
	</body>
</html>
