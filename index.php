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
$proxy_activate = ($cfg['proxy_activate'] == 1 ? "checked" : "");
$avg_seeders = ($cfg['avg_seeders'] == 1 ? "checked" : "");
$topic_checked = (in_array(2, $cfg['topics_status']) ? "checked" : "");
$topic_not_checked = (in_array(0, $cfg['topics_status']) ? "checked" : "");
$topic_not_decoration = (in_array(3, $cfg['topics_status']) ? "checked" : "");
$topic_doubtfully = (in_array(8, $cfg['topics_status']) ? "checked" : "");
$topic_temporary = (in_array(10, $cfg['topics_status']) ? "checked" : "");
$leechers = $cfg['topics_control']['leechers'] ? "checked" : "";
$no_leechers = $cfg['topics_control']['no_leechers'] ? "checked" : "";

?>

<html>
	<head>
		<meta charset="utf-8" />
		<title>web-TLO-0.9.2.4</title>
		<script src="jquery-ui-1.12.1/jquery.js"></script>
		<script src="jquery-ui-1.12.1/jquery-ui.js"></script>
		<script src="jquery-ui-1.12.1/external/jquery.mousewheel.js"></script>
		<link rel="stylesheet" href="css/reset.css" /> <!-- сброс стилей -->
		<link rel="stylesheet" href="jquery-ui-1.12.1/jquery-ui.css" />
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
						<input id="sendreports" name="sendreports" type="button" class="btn-lock" title="Отправить отчёты на форум" value="Отправить отчёты" />
					</div>
					<img id="loading" src="img/loading.gif" title="Выполняется..." />
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
											<option value="http://rutracker.cr" <?php echo ($cfg['forum_url'] == 'http://rutracker.cr' ? "selected" : "") ?> >http://rutracker.cr</option>
											<option value="http://rutracker.org" <?php echo ($cfg['forum_url'] == 'http://rutracker.org' ? "selected" : "") ?> >http://rutracker.org</option>
											<option value="https://rutracker.cr" <?php echo ($cfg['forum_url'] == 'https://rutracker.cr' ? "selected" : "") ?> >https://rutracker.cr</option>
											<option value="https://rutracker.org" <?php echo ($cfg['forum_url'] == 'https://rutracker.org' ? "selected" : "") ?> >https://rutracker.org</option>
										</select>
									</label>
								</div>
								<div>
									<label>
										Используемый адрес API:
										<select name="api_url" id="api_url" class="myinput">
											<option value="http://api.rutracker.cc" <?php echo ($cfg['api_url'] == 'http://api.rutracker.cc' ? "selected" : "") ?> >http://api.rutracker.cc</option>
											<option value="http://api.rutracker.org" <?php echo ($cfg['api_url'] == 'http://api.rutracker.org' ? "selected" : "") ?> >http://api.rutracker.org</option>
											<option value="https://api.rutracker.cc" <?php echo ($cfg['api_url'] == 'https://api.rutracker.cc' ? "selected" : "") ?> >https://api.rutracker.cc</option>
											<option value="https://api.rutracker.org" <?php echo ($cfg['api_url'] == 'https://api.rutracker.org' ? "selected" : "") ?> >https://api.rutracker.org</option>
										</select>
									</label>
								</div>
								<div>
									<label>
										Логин:
										<input id="TT_login" name="TT_login" class="myinput" type="text" size="24" title="Логин на http://rutracker.org" value="<?php echo $cfg['tracker_login'] ?>" />
									</label>
									<label>
										Пароль:
										<input id="TT_password" name="TT_password" class="myinput" type="password" size="24" title="Пароль на http://rutracker.org" value="<?php echo $cfg['tracker_paswd'] ?>" />
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
									<label title="Использовать при обращении к форуму прокси-сервер, например, для обхода блокировки.">
										<input name="proxy_activate" id="proxy_activate" type="checkbox" size="24" <?php echo $proxy_activate ?> />
										использовать прокси-сервер (например, для обхода блокировки)
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
									<button name="online-tc" id="online-tc" type="button" title="Проверить доступность выбранного торрент-клиента в списке"><img hidden src="img/loading.gif" />Проверить</button>
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
								</fieldset>
							</div>
							<h2>Настройки управления раздачами</h2>
							<div>
								<h3>Статусы раздач</h3>
								<div id="tor_status">
									<div>
										<label title="не проверено">
											<input class="tor_status" name="topics_status[]" value="0" type="checkbox" size="24" <?php echo $topic_not_checked ?> />
											не проверено
										</label>
									</div>
									<div>
										<label title="проверено">
											<input class="tor_status" name="topics_status[]" value="2" type="checkbox" size="24" <?php echo $topic_checked ?> />
											проверено
										</label>
									</div>
									<div>
										<label title="недооформлено">
											<input class="tor_status" name="topics_status[]" value="3" type="checkbox" size="24" <?php echo $topic_not_decoration ?> />
											недооформлено
										</label>
									</div>
									<div>
										<label title="сомнительно">
											<input class="tor_status" name="topics_status[]" value="8" type="checkbox" size="24" <?php echo $topic_doubtfully ?> />
											сомнительно
										</label>
									</div>
									<div>
										<label title="временная">
											<input class="tor_status" name="topics_status[]" value="10" type="checkbox" size="24" <?php echo $topic_temporary ?> />
											временная
										</label>
									</div>											
								</div>
								<h3>Фильтрация раздач</h3>
								<label class="label" title="Укажите числовое значение количества сидов (по умолчанию: 3)">
									Предлагать для хранения раздачи с количеством сидов не более:
									<input id="TT_rule_topics" name="TT_rule_topics" type="text" size="2" value="<?php echo $cfg['rule_topics'] ?>" />
								</label>
								<label class="label" title="Укажите числовое значение количества сидов (по умолчанию: 10)">
									Вносить в отчёты раздачи с количеством сидов не более:
									<input id="TT_rule_reports" name="TT_rule_reports" type="text" size="2" value="<?php echo $cfg['rule_reports'] ?>" />
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
