<?php

Header("Cache-Control: no-cache, no-store, must-revalidate, max-age=0");
mb_internal_encoding("UTF-8");

include dirname(__FILE__) . '/common.php';

if(!ini_get('date.timezone')) date_default_timezone_set('Europe/Moscow');

// получение настроек
$cfg = get_settings();
	
// торрент-клиенты
if(isset($cfg['clients'])){
	foreach($cfg['clients'] as $tc){
		$tcs[] = '<option value="'.$tc['cm'].'" data="'.implode('|', $tc).'">'.$tc['cm'].'</option>';
	}
	$tcs = implode('', $tcs);
} else $tcs = '';

// подразделы
if(isset($cfg['subsections'])){
	foreach($cfg['subsections'] as $id => $ss){
		$subsections[] = '<option value="'.$id.'" data="'.$id.'|'.
			implode('|', $ss).'">'.preg_replace('|.* » (.*)$|', '$1', $ss['title']).'</option>';
	}
	$subsections = implode('', $subsections);
} else $subsections = '';

// чекбоксы
$savesubdir = ($cfg['savesub_dir'] == 1 ? "checked" : "");
$retracker = ($cfg['retracker'] == 1 ? "checked" : "");
$proxy_activate = ($cfg['proxy_activate'] == 1 ? "checked" : "");
$avg_seeders = ($cfg['avg_seeders'] == 1 ? "checked" : "");
$topic_checked = (in_array(2, $cfg['topics_status']) ? "checked" : "");
$topic_not_checked = (in_array(0, $cfg['topics_status']) ? "checked" : "");
$topic_not_decoration = (in_array(3, $cfg['topics_status']) ? "checked" : "");
$topic_doubtfully = (in_array(8, $cfg['topics_status']) ? "checked" : "");
$topic_temporary = (in_array(10, $cfg['topics_status']) ? "checked" : "");

?>

<html>
	<head>
		<meta charset="utf-8" />
		<title>web-TLO-0.8.2.14</title>		
		<script src="jquery-ui-1.10.3.custom/js/jquery-1.9.1.js"></script>
		<script src="jquery-ui-1.10.3.custom/js/jquery-ui-1.10.3.custom.js"></script>
		<script src="jquery-ui-1.10.3.custom/development-bundle/external/jquery.mousewheel.js"></script>
		<link rel="stylesheet" href="css/reset.css" /> <!-- сброс стилей -->
		<link rel="stylesheet" href="jquery-ui-1.10.3.custom/css/smoothness/jquery-ui-1.10.3.custom.css" />
		<link rel="stylesheet" href="css/style.css" /> <!-- таблица стилей webtlo -->
	</head>
	<body>
		<div id="menutabs" class="menu">
			<ul class="menu">
				<li class="menu"><a href="#main" class="menu">Главная</a></li>
				<li class="menu"><a href="#settings" class="menu">Настройки</a></li>
				<li class="menu"><a href="#reports" class="menu">Отчёты</a></li>
				<li class="menu"><a href="#journal" class="menu">Журнал</a></li>
				<a id="help" href="manual.pdf" target="_blank" title="Открыть файл руководства">FAQ</a>
			</ul>
			<div id="content">
				<div id="main" class="content">
					<div id="btn-menu">
						<input id="update" name="update" type="button" class="btn-lock" title="Обновить сведения о раздачах" value="Обновить сведения" />
						<input id="startreports" name="startreports" type="button" class="btn-lock" title="Сформировать отчёты для вставки на форум" value="Создать отчёты" />
					</div>
					<img id="loading" src="loading.gif" title="Выполняется..." />
					<div id="topics"></div>
				</div>
				<div id="settings" class="content">
					<form id="config">
						<input id="savecfg" name="savecfg" type="button" value="Сохранить настройки" title="Записать настройки в файл">
						<br/><br/>
						<div class="sub_settings">
							<h2>Настройки авторизации на форуме</h2>
							<div>
								<div>
									<label>
										Используемый адрес форума:
										<select name="forum_url" id="forum_url" class="myinput">
											<option value="http://rutracker.cr" <? echo ($cfg['forum_url'] == 'http://rutracker.cr' ? "selected" : "") ?> >http://rutracker.cr</option>
											<option value="http://rutracker.org" <? echo ($cfg['forum_url'] == 'http://rutracker.org' ? "selected" : "") ?> >http://rutracker.org</option>
											<option value="https://rutracker.cr" <? echo ($cfg['forum_url'] == 'https://rutracker.cr' ? "selected" : "") ?> >https://rutracker.cr</option>
											<option value="https://rutracker.org" <? echo ($cfg['forum_url'] == 'https://rutracker.org' ? "selected" : "") ?> >https://rutracker.org</option>
										</select>
									</label>
								</div>
								<div>
									<label>
										Используемый адрес API:
										<select name="api_url" id="api_url" class="myinput">
											<option value="http://api.rutracker.cc" <? echo ($cfg['api_url'] == 'http://api.rutracker.cc' ? "selected" : "") ?> >http://api.rutracker.cc</option>
											<option value="http://api.rutracker.org" <? echo ($cfg['api_url'] == 'http://api.rutracker.org' ? "selected" : "") ?> >http://api.rutracker.org</option>
											<option value="https://api.rutracker.cc" <? echo ($cfg['api_url'] == 'https://api.rutracker.cc' ? "selected" : "") ?> >https://api.rutracker.cc</option>
											<option value="https://api.rutracker.org" <? echo ($cfg['api_url'] == 'https://api.rutracker.org' ? "selected" : "") ?> >https://api.rutracker.org</option>
										</select>
									</label>
								</div>
								<div>
									<label>
										Логин:
										<input name="TT_login" class="myinput" type="text" size="24" title="Логин на http://rutracker.org" value="<? echo $cfg['tracker_login'] ?>" />
									</label>
									<label>
										Пароль:
										<input name="TT_password" class="myinput" type="password" size="24" title="Пароль на http://rutracker.org" value="<? echo $cfg['tracker_paswd'] ?>" />
									</label>
								</div>																			
								<div>
									<label>
										Ключ bt:
										<input name="bt_key" class="myinput" type="password" size="24" title="Хранительский ключ bt" value="<? echo $cfg['bt_key'] ?>" />
									</label>
									<label>
										Ключ api:
										<input name="api_key" class="myinput" type="password" size="24" title="Хранительский ключ api" value="<? echo $cfg['api_key'] ?>" />
									</label>
								</div>
							</div>
							<h2>Настройки прокси-сервера</h2>
							<div>
								<div>
									<label title="Использовать при обращении к форуму прокси-сервер, например, для обхода блокировки.">
										<input name="proxy_activate" id="proxy_activate" type="checkbox" size="24" <? echo $proxy_activate ?> />
										использовать прокси-сервер (например, для обхода блокировки)
									</label>											
								</div>
								<div id="proxy_prop">
									<div>
										<label>
											Тип прокси-сервера:
											<select name="proxy_type" id="proxy_type" class="myinput" title="Тип прокси-сервера">
												<option value="http" <? echo ($cfg['proxy_type'] == 'http' ? "selected" : "") ?> >HTTP</option>
												<option value="socks4" <? echo ($cfg['proxy_type'] == 'socks4' ? "selected" : "") ?> >SOCKS4</option>
												<option value="socks4a" <? echo ($cfg['proxy_type'] == 'socks4a' ? "selected" : "") ?> >SOCKS4A</option>
												<option value="socks5" <? echo ($cfg['proxy_type'] == 'socks5' ? "selected" : "") ?> >SOCKS5</option>
											</select>
										</label>
									</div>
									<div>
										<label>
											IP-адрес/сетевое имя:
											<input name="proxy_hostname" id="proxy_hostname" class="myinput" type="text" size="24" title="IP-адрес или сетевое/доменное имя прокси-сервера." value="<? echo $cfg['proxy_hostname'] ?>" />
										</label>
										<label>
											Порт:
											<input name="proxy_port" id="proxy_port" class="myinput" type="text" size="24" title="Порт прокси-сервера." value="<? echo $cfg['proxy_port'] ?>" />
										</label>
									</div>
									<div>
										<label>
											Логин:
											<input name="proxy_login" id="proxy_login" class="myinput" type="text" size="24" title="Имя пользователя для доступа к прокси-серверу (необязательно)." value="<? echo $cfg['proxy_login'] ?>" />
										</label>
										<label>
											Пароль:
											<input name="proxy_paswd" id="proxy_paswd" class="myinput" type="text" size="24" title="Пароль для доступа к прокси-серверу (необязатально)." value="<? echo $cfg['proxy_paswd'] ?>" />
										</label>
									</div>
								</div>
							</div>
							<h2>Настройки торрент-клиентов</h2>
							<div>
								<p>
									<input name="add-tc" id="add-tc" type="button" value="Добавить" />
									<input name="del-tc" id="del-tc" type="button" value="Удалить" />
								</p>
								
								<div class="block-settings">											
									<select id="list-tcs" size=10>
										<option value=0 data="0" disabled>список торрент-клиентов</option>
										<? echo $tcs ?>
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
								<input id="ss-add" class="myinput" type="text" size="100" placeholder="Для добавления подраздела начните вводить его индекс или название" title="Добавление нового подраздела" />
								<div class="block-settings">											
									<select name="list-ss" id="list-ss" size=9>
										<option value=0 data="0" disabled>список подразделов</option>
										<? echo $subsections ?>
									</select>
								</div>
								<div class="block-settings" id="ss-prop">
									<label>
										Индекс:
										<input disabled id="ss-id" class="myinput ss-prop" type="text" size="10" title="Индекс подраздела" />
									</label>
									<label>
										Название:
										<input disabled id="ss-title" class="myinput ss-prop" type="text" size="70" title="Полное название подраздела" />
									</label>
									<label>
										Торрент-клиент:
										<select id="ss-client" class="myinput ss-prop" title="Добавлять раздачи текущего подраздела в торрент-клиент">
											<option value=0 disabled>не выбран</option>
										</select>
									</label>
									<label>
										Метка:
										<input id="ss-label" class="myinput ss-prop" type="text" size="50" title="При добавлении раздачи установить для неё метку (поддерживаются только uTorrent и qBittorrent)" />
									</label>
									<label>
										Каталог для данных:
										<input id="ss-folder" class="myinput ss-prop" type="text" size="57" title="При добавлении раздачи данные сохранять в каталог (поддерживаются все кроме KTorrent)" />
									</label>
								</div>
							</div>
							<h2>Настройки поиска раздач</h2>
							<div>
								<h3>Получать сведения о раздачах только со статусом</h3>
								<div id="tor_status">
									<div>
										<label title="не проверено">
											<input class="tor_status" name="topics_status[]" value="0" type="checkbox" size="24" <? echo $topic_not_checked ?> />
											не проверено
										</label>
									</div>
									<div>
										<label title="проверено">
											<input class="tor_status" name="topics_status[]" value="2" type="checkbox" size="24" <? echo $topic_checked ?> />
											проверено
										</label>
									</div>
									<div>
										<label title="недооформлено">
											<input class="tor_status" name="topics_status[]" value="3" type="checkbox" size="24" <? echo $topic_not_decoration ?> />
											недооформлено
										</label>
									</div>
									<div>
										<label title="сомнительно">
											<input class="tor_status" name="topics_status[]" value="8" type="checkbox" size="24" <? echo $topic_doubtfully ?> />
											сомнительно
										</label>
									</div>
									<div>
										<label title="временная">
											<input class="tor_status" name="topics_status[]" value="10" type="checkbox" size="24" <? echo $topic_temporary ?> />
											временная
										</label>
									</div>											
								</div>
								<h3>Предлагать для хранения раздачи с кол-вом сидов не более</h3>
								<div>
									<input name="TT_rule_topics" class="myinput" type="text" size="24" title="Укажите числовое значение" value="<? echo $cfg['rule_topics'] ?>" />
								</div>
								<label title="При поиске раздач использовать среднее значение количества сидов.">										
									<input name="avg_seeders" type="checkbox" size="24" <? echo $avg_seeders ?> />
									средние сиды
								</label>
								<h3>Вносить в отчёты раздачи с кол-вом сидов не более</h3>
								<div>
									<input name="TT_rule_reports" class="myinput" type="text" size="24" title="Укажите числовое значение" value="<? echo $cfg['rule_reports'] ?>" />
								</div>
							</div>
							<h2>Настройки загрузки торрент-файлов</h2>
							<div>
								<h3>Каталог для скачиваемых *.torrent файлов</h3>
								<div>
									<input id="savedir" name="savedir" class="myinput" type="text" size="53" title="Каталог, куда будут сохраняться новые *.torrent-файлы." value="<? echo $cfg['save_dir'] ?>" />
								</div>
								<label title="При установленной метке *.torrent-файлы дополнительно будут помещены в подкаталог.">										
									<input name="savesubdir" type="checkbox" size="24" <? echo $savesubdir ?> />
									создавать подкаталоги
								</label>
								<h3>Настройки retracker.local</h3>
								<label title="Добавлять retracker.local в скачиваемые *.torrent-файлы.">
									<input name="retracker" type="checkbox" size="24" <? echo $retracker ?> />
									добавлять retracker.local в скачиваемые *.torrent-файлы
								</label>
							</div>		
						</div>
					</form>
				</div>					
				<div id="reports" class="content"></div>
				<div id="journal" class="content">
					<div id="log"></div>
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
