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
$leechers = $cfg['topics_control']['leechers'] ? "checked" : "";
$no_leechers = $cfg['topics_control']['no_leechers'] ? "checked" : "";
$tor_for_user = $cfg['tor_for_user'] == 1 ? "checked" : "";

?>

<!DOCTYPE html>
<html lang="ru">
	<head>
		<meta charset="utf-8">
		<meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
		<title>web-TLO-0.9.3.4</title>
		<link rel="stylesheet" href="css/bootstrap.min.css">
		<link rel="stylesheet" href="css/dataTables.bootstrap4.min.css">
		<link rel="stylesheet" href="css/bootstrap-datepicker3.css">
		<link rel="stylesheet" href="css/font-awesome.min.css">
		<link rel="stylesheet" href="css/style.css" /> <!-- таблица стилей webtlo -->
		<link rel="icon" href="img/favicon.ico">
	</head>
	<body>
		<div class="container-fluid">
			<ul id="main_menu" class="nav nav-tabs" role="tablist">
				<li class="nav-item">
					<a href="#main" class="nav-link" data-toggle="tab" role="tab">Главная</a>
				</li>
				<li class="nav-item">
					<a href="#settings" class="nav-link" data-toggle="tab" role="tab">Настройки</a>
				</li>
				<li class="nav-item">
					<a href="#reports" class="nav-link disabled" data-toggle="tab" role="tab">Отчёты</a>
				</li>
				<li class="nav-item">
					<a href="#statistics" class="nav-link" data-toggle="tab" role="tab">Статистика</a>
				</li>
				<li class="nav-item">
					<a href="#journal" class="nav-link" data-toggle="tab" role="tab">Журнал</a>
				</li>
				<li class="nav-item">
					<a href="#manual" title="Открыть файл руководства" class="nav-link" data-toggle="tab" role="tab">FAQ</a>
				</li>
			</ul>
			<div id="content" class="tab-content pl-2 pr-2 pb-2">
				<div id="main" class="tab-pane fade show active" role="tabpanel">
					<select id="subsections" class="form-control form-control-sm" title="Подраздел">
						<optgroup id="subsections_stored" label="">
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
					<div id="sub-data">
						<div id="topics_control">
							<div id="filter" class="btn-group" role="group">
								<!--<button type="button" id="filter_show" class="btn btn-sm btn-outline-dark" title="Скрыть или показать настройки фильтра">
									<i class="fa fa-filter"></i>
								</button> -->
								<button type="button" id="filter_reset" class="btn btn-sm btn-outline-dark" title="Сбросить настройки фильтра на значения по умолчанию">
									<i class="fa fa-undo"></i>
								</button>
							</div>
							<div id="select" class="btn-group" role="group">
								<button type="button" id="tor_select" class="btn btn-sm btn-outline-dark" value="select" title="Выделить все раздачи текущего подраздела">
									<i class="fa fa-check-square-o"></i>
								</button>
								<button type="button" id="tor_unselect" class="btn btn-sm btn-outline-dark" value="unselect" title="Снять выделение всех раздач текущего подраздела">
									<i class="fa fa-square-o"></i>
								</button>
							</div>
							<div id="new-torrents" class="btn-group" role="group">
								<button type="button" id="tor_add" class="btn btn-sm btn-outline-dark" title="Добавить выделенные раздачи текущего подраздела в торрент-клиент">
									<i class="fa fa-plus text-success"></i>
								</button>
								<button type="button" class="tor_download btn btn-sm btn-outline-dark" value="0" title="Скачать *.torrent файлы выделенных раздач текущего подраздела в каталог">
									<i class="fa fa-download"></i>
								</button>
								<button type="button" class="tor_download btn btn-sm btn-outline-dark" value="1" title="Скачать *.torrent-файлы выделенных раздач текущего подраздела в каталог с заменой Passkey">
									<i class="fa fa-download download-replace"></i>
									<i class="fa fa-asterisk download-replace-super"></i>
								</button>
								<button type="button" id="tor_blacklist" class="btn btn-sm btn-outline-dark" value="1" title="Включить выделенные раздачи в чёрный список или наоборот исключить">
									<i class="fa fa-ban text-danger"></i>
								</button>
							</div>
							<div id="control" class="btn-group" role="group">
								<button type="button" class="btn btn-sm btn-outline-dark torrent_action" id="set_label" title="Установить метку для выделенных раздач текущего подраздела в торрент-клиенте (удерживайте Ctrl для установки произвольной метки)">
									<i class="fa fa-tag"></i>
								</button>
								<button type="button" class="btn btn-sm btn-outline-dark torrent_action" id="start" title="Запустить выделенные раздачи текущего подраздела в торрент-клиенте">
									<i class="fa fa-play text-success"></i>
								</button>
								<button type="button" class="btn btn-sm btn-outline-dark torrent_action" id="stop" title="Приостановить выделенные раздачи текущего подраздела в торрент-клиенте">
									<i class="fa fa-pause text-warning"></i>
								</button>
								<button type="button" class="btn btn-sm btn-outline-dark torrent_action" id="remove_open_modal" title="Удалить выделенные раздачи текущего подраздела из торрент-клиента">
									<i class="fa fa-times text-danger"></i>
								</button>
							</div>
							<button id="update" class="btn btn-sm btn-outline-dark" name="update" type="button" title="Обновить сведения о раздачах">
								<i class="fa fa-refresh"></i> Обновить сведения
							</button>
							<button id="startreports" class="btn btn-sm btn-outline-dark" name="startreports" type="button" title="Сформировать отчёты для вставки на форум">
								<i class="fa fa-file-text-o"></i> Создать отчёты
							</button>
							<button id="sendreports" class="btn btn-sm btn-outline-dark" name="sendreports" type="button" title="Отправить отчёты на форум">
								<i class="fa fa-paper-plane-o"></i> Отправить отчёты
							</button>
							<div id="indication">
								<i id="loading" class="fa fa-spinner fa-pulse"></i>
								<div style="display:none;" id="process"></div>
							</div>
						</div>
						<form method="post" id="topics_filter" class="form-inline">
							<div class="topics_filter">

								<div class="btn-group" data-toggle="buttons" title="Статусы раздач в торрент-клиенте">
									<label class="btn btn-sm btn-outline-dark">
										<input type="radio" name="filter_status" value="1" autocomplete="off"> храню
									</label>
									<label class="btn btn-sm btn-outline-dark active">
										<input type="radio" name="filter_status" value="0" autocomplete="off" checked> не храню
									</label>
									<label class="btn btn-sm btn-outline-dark">
										<input type="radio" name="filter_status" value="-1" autocomplete="off"> качаю
									</label>
								</div>

								<div class="btn-group" data-toggle="buttons" title="Отображать только раздачи, для которых информация о сидах содержится за весь период, указанный в настройках (при использовании алгоритма нахождения среднего значения количества сидов)">
									<label class="btn btn-outline-dark btn-sm">
										<input type="checkbox" name="avg_seeders_complete" autocomplete="off"> "зелёные"
									</label>
								</div>
								<div class="btn-group">
									<button class="btn btn-outline-dark btn-sm dropdown-toggle" type="button" data-toggle="dropdown" title="Статусы раздач на трекере">
										Статусы
									</button>
									<div class="dropdown-menu">
										<a class="dropdown-item tor-status-resp" href="#">
											<label class="form-check-label">
												<span class="tor-icon tor-not-approved">*</span> не проверено
												<input value="0" type="checkbox" name="filter_tor_status[]">
												<span class="tor-status-checked">
													<i class="fa fa-check"></i>
												</span>
											</label>
										</a>
										<a class="dropdown-item tor-status-resp" href="#">
											<label class="form-check-label">
												<span class="tor-icon tor-approved">√</span> проверено
												<input value="2" type="checkbox" name="filter_tor_status[]" checked>
												<span class="tor-status-checked">
													<i class="fa fa-check"></i>
												</span>
											</label>
										</a>
										<a class="dropdown-item tor-status-resp" href="#">
											<label class="form-check-label">
												<span class="tor-icon tor-need-edit">?</span> недооформлено
												<input value="3" type="checkbox" name="filter_tor_status[]">
												<span class="tor-status-checked">
													<i class="fa fa-check"></i>
												</span>
											</label>
										</a>
										<a class="dropdown-item tor-status-resp" href="#">
											<label class="form-check-label">
												<span class="tor-icon tor-approved">#</span> сомнительно
												<input value="8" type="checkbox" name="filter_tor_status[]" checked>
												<span class="tor-status-checked">
													<i class="fa fa-check"></i>
												</span>
											</label>
										</a>
										<a class="dropdown-item tor-status-resp" href="#">
											<label class="form-check-label">
												<span class="tor-icon tor-dup">T</span> временная
												<input value="10" type="checkbox" name="filter_tor_status[]">
												<span class="tor-status-checked">
													<i class="fa fa-check"></i>
												</span>
											</label>
										</a>
									</div>
								</div>

								<div class="btn-group" data-toggle="buttons">
									<label class="btn btn-sm btn-outline-dark">
										<input type="radio" name="is_keepers" value="-1" autocomplete="off"> нет хранителей
									</label>
									<label class="btn btn-sm btn-outline-dark active">
										<input type="radio" name="is_keepers" value="0" autocomplete="off" checked> все
									</label>
									<label class="btn btn-sm btn-outline-dark">
										<input type="radio" name="is_keepers" value="1" autocomplete="off"> есть хранители
									</label>
								</div>

								<div class="filter_block">
									<label for="filter_avg_seeders_period" title="Выберите произвольный период средних сидов">
										<span class="mr-2">Период средних сидов:</span>
										<input type="number" id="filter_avg_seeders_period" class="form-control form-control-sm" name="avg_seeders_period" value="<?php echo $cfg['avg_seeders_period'] ?>" min="1" max="30"/>
									</label>
								</div>
							</div>
						</form>
						<div id="topics">
							<table id="topics_table" class="table table-hover" width="100%" cellspacing="0">
								<thead>
									<tr id="table_filter">
										<th colspan="4">
											<div class="input-group input-group-sm">
												<input title="Рег.дата от" data-toggle="tooltip" placeholder="От" class="form-control" value="" id="filter_date_release_from">
												<span class="input-group-addon">:</span>
												<input title="Рег.дата до" data-toggle="tooltip" placeholder="До" class="form-control" value="" id="filter_date_release_until" name="filter_date_release_until" data-registered-until="<?php echo $cfg['rule_date_release'] ?>">
											</div>
										</th>
										<th colspan="2">
											<div class="input-group input-group-sm">
												<input type="number" min="0" step="0.5" title="Сидов от" data-toggle="tooltip" placeholder="От" class="form-control" value="0" id="filter_seeders_from">
												<span class="input-group-addon">:</span>
												<input type="number" min="0" step="0.5" title="Сидов до" data-toggle="tooltip" placeholder="До" class="form-control" value="<?php echo $cfg['rule_topics'] ?>" id="filter_seeders_to">
											</div>
										</th>
										<th>
											<input title="Название раздачи" data-toggle="tooltip" placeholder="Название раздачи" class="form-control form-control-sm" id="filter_by_name">
										</th>
										<th></th>
										<th>
											<input title="Хранители" data-toggle="tooltip" placeholder="Хранители" class="form-control form-control-sm" id="filter_by_keeper">
										</th>
										<th></th>
									</tr>
									<tr>
										<th></th>
										<th></th>
										<th></th>
										<th>Рег.дата</th>
										<th>Размер</th>
										<th>Сидов</th>
										<th>Название раздачи</th>
										<th>Альт.</th>
										<th>Хранители</th>
										<th>Разд.</th>
									</tr>
								</thead>
								<tbody id="topics_table_body"></tbody>
							</table>
						</div>
						<div class="status_info">
							<div id="counter">Выбрано раздач: <b id="topics_count">0</b> (<span id="topics_size">0.00</span>) из <b id="filtered_topics_count">0</b> (<span id="filtered_topics_size">0.00</span>)</div>
							<div id="topics_result"></div>
						</div>
					</div>
				</div>

				<div id="settings" class="tab-pane fade" role="tabpanel">
					<div>
						<input id="savecfg" name="savecfg" type="button" value="Сохранить настройки" title="Записать настройки в файл" class="btn btn-outline-dark">
					</div>
					<form id="config">
						<div class="sub_settings" id="accordion" role="tablist">
							<div class="card">
								<div class="card-header" role="tab" data-toggle="collapse" data-parent="#accordion" data-target="#authentication">
									<h6 class="mb-0">
										<a href="#authentication">Настройки авторизации на форуме</a>
									</h6>
								</div>
								<div id="authentication" class="collapse show" role="tabpanel">
									<div class="card-body">
										<div class="row">
											<label for="forum_url" class="col-3 col-form-label">
												Используемый адрес форума:
											</label>
											<div class="col-2">
												<select name="forum_url" id="forum_url" class="form-control form-control-sm">
													<option value="http://rutracker.cr" <?php echo ($cfg['forum_url'] == 'http://rutracker.cr' ? "selected" : "") ?> >http://rutracker.cr</option>
													<option value="http://rutracker.net" <?php echo ($cfg['forum_url'] == 'http://rutracker.net' ? "selected" : "") ?> >http://rutracker.net</option>
													<option value="http://rutracker.org" <?php echo ($cfg['forum_url'] == 'http://rutracker.org' ? "selected" : "") ?> >http://rutracker.org</option>
													<option value="http://rutracker.nl" <?php echo ($cfg['forum_url'] == 'http://rutracker.nl' ? "selected" : "") ?> >http://rutracker.nl</option>
													<option value="https://rutracker.cr" <?php echo ($cfg['forum_url'] == 'https://rutracker.cr' ? "selected" : "") ?> >https://rutracker.cr</option>
													<option value="https://rutracker.net" <?php echo ($cfg['forum_url'] == 'https://rutracker.net' ? "selected" : "") ?> >https://rutracker.net</option>
													<option value="https://rutracker.org" <?php echo ($cfg['forum_url'] == 'https://rutracker.org' ? "selected" : "") ?> >https://rutracker.org</option>
													<option value="https://rutracker.nl" <?php echo ($cfg['forum_url'] == 'https://rutracker.nl' ? "selected" : "") ?> >https://rutracker.nl</option>
												</select>
											</div>
										</div>
										<div class="row">
											<label for="api_url" class="col-3 col-form-label">
												Используемый адрес API:
											</label>
											<div class="col-2">
												<select name="api_url" id="api_url" class="form-control form-control-sm">
													<option value="http://api.rutracker.cc" <?php echo ($cfg['api_url'] == 'http://api.rutracker.cc' ? "selected" : "") ?> >http://api.rutracker.cc</option>
													<option value="http://api.t-ru.org" <?php echo ($cfg['api_url'] == 'http://api.t-ru.org' ? "selected" : "") ?> >http://api.t-ru.org</option>
													<option value="http://api.rutracker.org" <?php echo ($cfg['api_url'] == 'http://api.rutracker.org' ? "selected" : "") ?> >http://api.rutracker.org</option>
													<option value="https://api.rutracker.cc" <?php echo ($cfg['api_url'] == 'https://api.rutracker.cc' ? "selected" : "") ?> >https://api.rutracker.cc</option>
													<option value="https://api.t-ru.org" <?php echo ($cfg['api_url'] == 'https://api.t-ru.org' ? "selected" : "") ?> >https://api.t-ru.org</option>
													<option value="https://api.rutracker.org" <?php echo ($cfg['api_url'] == 'https://api.rutracker.org' ? "selected" : "") ?> >https://api.rutracker.org</option>
												</select>
											</div>
										</div>
										<div class="row">
											<label for="TT_login" class="col-3 col-form-label">
												Логин:
											</label>
											<div class="col-2">
												<input id="TT_login" name="TT_login" class="form-control form-control-sm" size="24" title="Логин на http://rutracker.org" value="<?php echo $cfg['tracker_login'] ?>">
											</div>
										</div>
										<div class="row">
											<label for="TT_password" class="col-3 col-form-label">
												Пароль:
											</label>
											<div class="col-2">
												<input id="TT_password" name="TT_password" class="form-control form-control-sm" type="password" size="24" title="Пароль на http://rutracker.org" value="<?php echo $cfg['tracker_paswd'] ?>">
											</div>
										</div>
										<div class="row">
											<label for="bt_key" class="col-3 col-form-label">
												Ключ bt:
											</label>
											<div class="col-2">
												<input id="bt_key" name="bt_key" class="form-control form-control-sm" type="password" size="24" title="Хранительский ключ bt" value="<?php echo $cfg['bt_key'] ?>">
											</div>
										</div>
										<div class="row">
											<label for="api_key" class="col-3 col-form-label">
												Ключ api:
											</label>
											<div class="col-2">
												<input id="api_key" name="api_key" class="form-control form-control-sm" type="password" size="24" title="Хранительский ключ api" value="<?php echo $cfg['api_key'] ?>">
											</div>
										</div>
										<div class="row">
											<label for="user_id" class="col-3 col-form-label">
												Ключ id:
											</label>
											<div class="col-2">
												<input id="user_id" name="user_id" class="form-control form-control-sm" size="24" title="Идентификатор пользователя" value="<?php echo $cfg['user_id'] ?>">
											</div>
										</div>
									</div>
								</div>
							</div>

							<div class="card">
								<div class="card-header" role="tab" data-toggle="collapse" data-parent="#accordion" data-target="#proxy">
									<h6 class="mb-0">
										<a class="collapsed" href="#proxy">Настройки прокси-сервера</a>
									</h6>
								</div>
								<div id="proxy" class="collapse" role="tabpanel">
									<div class="card-body">
										<div>
											<label class="form-check-label" title="Использовать при обращении к форуму прокси-сервер, например, для обхода блокировки.">
												<input class="form-check-input" name="proxy_activate" id="proxy_activate" type="checkbox" size="24" <?php echo $proxy_activate ?>>
												использовать прокси-сервер (например, для обхода блокировки)
											</label>
										</div>
										<div id="proxy_prop">
											<div class="row">
												<label for="proxy_type" class="col-3 col-form-label">
													Тип прокси-сервера:
												</label>
												<div class="col-2">
													<select name="proxy_type" id="proxy_type" class="form-control form-control-sm" title="Тип прокси-сервера">
														<option value="http" <?php echo ($cfg['proxy_type'] == 'http' ? "selected" : "") ?> >HTTP</option>
														<option value="socks4" <?php echo ($cfg['proxy_type'] == 'socks4' ? "selected" : "") ?> >SOCKS4</option>
														<option value="socks4a" <?php echo ($cfg['proxy_type'] == 'socks4a' ? "selected" : "") ?> >SOCKS4A</option>
														<option value="socks5" <?php echo ($cfg['proxy_type'] == 'socks5' ? "selected" : "") ?> >SOCKS5</option>
													</select>
												</div>
											</div>
											<div class="row">
												<label for="proxy_hostname" class="col-3 col-form-label">
													IP-адрес/сетевое имя:
												</label>
												<div class="col-2">
													<input name="proxy_hostname" id="proxy_hostname" class="form-control form-control-sm" size="24" title="IP-адрес или сетевое/доменное имя прокси-сервера." value="<?php echo $cfg['proxy_hostname'] ?>">
												</div>
											</div>
											<div class="row">
												<label for="proxy_port" class="col-3 col-form-label">
													Порт:
												</label>
												<div class="col-1">
													<input name="proxy_port" id="proxy_port" class="form-control form-control-sm" size="24" title="Порт прокси-сервера." value="<?php echo $cfg['proxy_port'] ?>">
												</div>
											</div>
											<div class="row">
												<label for="proxy_login" class="col-3 col-form-label">
													Логин:
												</label>
												<div class="col-2">
													<input name="proxy_login" id="proxy_login" class="form-control form-control-sm" size="24" title="Имя пользователя для доступа к прокси-серверу (необязательно)." value="<?php echo $cfg['proxy_login'] ?>">
												</div>
											</div>
											<div class="row">
												<label for="proxy_paswd" class="col-3 col-form-label">
													Пароль:
												</label>
												<div class="col-2">
													<input name="proxy_paswd" id="proxy_paswd" class="form-control form-control-sm" size="24" title="Пароль для доступа к прокси-серверу (необязатально)." value="<?php echo $cfg['proxy_paswd'] ?>">
												</div>
											</div>
										</div>
									</div>
								</div>
							</div>

							<div class="card">
								<div class="card-header" role="tab" data-toggle="collapse" data-parent="#accordion" data-target="#clients">
									<h6 class="mb-0">
										<a class="collapsed" href="#clients">Настройки торрент-клиентов</a>
									</h6>
								</div>
								<div id="clients" class="collapse" role="tabpanel">
									<div class="card-body">
										<p>
											<input name="add-tc" id="add-tc" type="button" class="btn btn-sm btn-outline-dark" value="Добавить" title="Добавить новый торрент-клиент в список" />
											<input name="del-tc" id="del-tc" type="button" class="btn btn-sm btn-outline-dark" value="Удалить" title="Удалить выбранный торрент-клиент из списка" />
											<button name="online-tc" id="online-tc" type="button" class="btn btn-sm btn-outline-dark" title="Проверить доступность выбранного торрент-клиента в списке">
												<i id="checking" class="fa fa-spinner fa-spin"></i> Проверить
											</button>
											<span id="result-tc"></span>
										</p>
										<div class="row">
											<div class="col-5">
												<div class="form-group">
													<label for="list-tcs">Cписок торрент-клиентов</label>
													<select id="list-tcs" size=10 class="form-control form-control-sm">
														<?php echo $tcs ?>
													</select>
												</div>
											</div>
											<div class="col-5" id="tc-prop">
												<div class="row">
													<div class="col-6">
														<div class="form-group">
															<label for="TC_comment">Название (комментарий)</label>
															<input name="TC_comment" id="TC_comment" class="form-control form-control-sm tc-prop" size="24" title="Комментарий">
														</div>
														<div class="form-group">
															<label for="TC_hostname">IP-адрес/сетевое имя:</label>
															<input name="TC_hostname" id="TC_hostname" class="form-control form-control-sm tc-prop" size="24" title="IP-адрес или сетевое/доменное имя компьютера с запущенным торрент-клиентом.">
														</div>
														<div class="form-group">
															<label for="TC_login">Логин:</label>
															<input name="TC_login" id="TC_login" class="form-control form-control-sm tc-prop" size="24" title="Логин для доступа к веб-интерфейсу торрент-клиента (необязатально).">
														</div>
													</div>

													<div class="col-6">
														<div class="form-group">
															<label for="TC_client">Торрент-клиент</label>
															<select name="TC_client" id="TC_client" class="form-control form-control-sm tc-prop">
																<option value="utorrent">uTorrent</option>
																<option value="transmission">Transmission</option>
																<option value="vuze" title="Web Remote   plugin">Vuze</option>
																<option value="deluge" title="WebUi   plugin">Deluge</option>
																<option value="qbittorrent">qBittorrent</option>
																<option value="ktorrent">KTorrent</option>
																<option value="rtorrent">rTorrent 0.9.x</option>
															</select>
														</div>
														<div class="form-group">
															<label for="TC_port">Порт:</label>
															<input name="TC_port" id="TC_port" class="form-control form-control-sm tc-prop" size="24" title="Порт веб-интерфейса торрент-клиента.">
														</div>
														<div class="form-group">
															<label for="TC_password">Пароль:</label>
															<input name="TC_password" id="TC_password" class="form-control form-control-sm tc-prop" type="password" size="24" title="Пароль для доступа к веб-интерфейсу торрент-клиента (необязатально).">
														</div>
													</div>
												</div>
											</div>
										</div>
									</div>
								</div>
							</div>

							<div class="card">
								<div class="card-header" role="tab" data-toggle="collapse" data-parent="#accordion" data-target="#sub-sections">
									<h6 class="mb-0">
										<a class="collapsed" href="#sub-sections">Настройки сканируемых подразделов</a>
									</h6>
								</div>
								<div id="sub-sections" class="collapse" role="tabpanel">
									<div class="card-body">
										<div class="form-group row">
											<div class="col-12">
												<input id="ss-add" class="form-control form-control-sm" size="100" placeholder="Для добавления подраздела начните вводить его индекс или название" title="Добавить новый подраздел" autocomplete="off">
											</div>
										</div>
										<input id="ss-del" type="button" class="btn btn-sm btn-outline-dark" value="Удалить текущий подраздел" title="Удалить выбранный подраздел">
										<div class="row">
											<label for="list-ss" class="col-2 col-form-label">Подраздел:</label>
											<div class="col-10">
												<select name="list-ss" id="list-ss" class="form-control form-control-sm">
													<?php echo $subsections ?>
												</select>
											</div>
										</div>
										<div id="ss-prop">
											<div class="row">
												<label for="ss-id" class="col-2 col-form-label">Индекс:</label>
												<div class="col-1">
													<input disabled id="ss-id" class="form-control form-control-sm ss-prop" title="Индекс подраздела">
												</div>
											</div>
											<div class="row">
												<label for="ss-client" class="col-2 col-form-label">Торрент-клиент:</label>
												<div class="col-2">
													<select id="ss-client" class="form-control form-control-sm ss-prop" title="Добавлять раздачи текущего подраздела в торрент-клиент">
														<option value=0>не выбран</option>
													</select>
												</div>
											</div>
											<div class="row">
												<label for="ss-label" class="col-2 col-form-label">Метка:</label>
												<div class="col-5">
													<input id="ss-label" class="form-control form-control-sm ss-prop" size="50" title="При добавлении раздачи установить для неё метку (поддерживаются только Deluge, qBittorrent и uTorrent)">
												</div>
											</div>
											<div class="row">
												<label for="ss-folder" class="col-2 col-form-label">Каталог для данных:</label>
												<div class="col-6">
													<input id="ss-folder" class="form-control form-control-sm ss-prop" size="57" title="При добавлении раздачи данные сохранять в каталог (поддерживаются все кроме KTorrent)">
												</div>
											</div>
											<div class="row">
												<label for="ss-link" class="col-2 col-form-label">Ссылка на список:</label>
												<div class="col-2">
													<input id="ss-link" class="form-control form-control-sm ss-prop" size="55" title="Ссылка для отправки отчётов на форум (например, https://rutracker.org/forum/viewtopic.php?t=3572968)">
												</div>
											</div>
											<div class="row">
												<label for="ss-sub-folder" class="col-2 col-form-label">Создавать подкаталог для добавляемой раздачи:</label>
												<div class="col-2">
													<select id="ss-sub-folder" class="form-control form-control-sm ss-prop" title="Создавать подкаталог для данных добавляемой раздачи">
														<option value="0">Нет</option>
														<option value="1">С ID топика</option>
														<!-- <option value="2">Запрашивать</option> -->
													</select>
												</div>
											</div>
										</div>
									</div>
								</div>
							</div>

							<div class="card">
								<div class="card-header" role="tab" data-toggle="collapse" data-parent="#accordion" data-target="#torrents-control">
									<h6 class="mb-0">
										<a class="collapsed" href="#torrents-control">Настройки управления раздачами</a>
									</h6>
								</div>
								<div id="torrents-control" class="collapse" role="tabpanel">
									<div class="card-body">
										<h5>Фильтрация раздач</h5>
										<div class="form-group col-12">
											<div class="row">
												<label for="TT_rule_topics" class="col-6 col-form-label" title="Укажите числовое значение количества сидов (по умолчанию: 3)">
													Предлагать для хранения раздачи с количеством сидов не более:
												</label>
												<div class="col-1">
													<input id="TT_rule_topics" name="TT_rule_topics" class="form-control form-control-sm" type="number" min="0" step="0.5" size="2" value="<?php echo $cfg['rule_topics'] ?>">
												</div>
											</div>
											<div class="row">
												<label for="rule_date_release" class="col-6 col-form-label" title="Укажите необходимое количество дней">
													Предлагать для хранения раздачи старше
												</label>
												<div class="col-2">
													<div class="input-group input-group-sm">
														<input id="rule_date_release" name="rule_date_release" class="form-control" type="number" min="0" size="2" value="<?php echo $cfg['rule_date_release'] ?>">
														<span class="input-group-addon">дн.</span>
													</div>
												</div>
											</div>
											<div class="row">
												<label for="TT_rule_reports" class="col-6 col-form-label" title="Укажите числовое значение количества сидов (по умолчанию: 10)">
													Вносить в отчёты раздачи с количеством сидов не более:
												</label>
												<div class="col-1">
													<input id="TT_rule_reports" name="TT_rule_reports" class="form-control form-control-sm" type="number" min="0" step="0.5" size="2" value="<?php echo $cfg['rule_reports'] ?>">
												</div>
											</div>
											<div class="row">
												<label for="avg_seeders_period" class="col-6 col-form-label" title="При фильтрации раздач будет использоваться среднее значение количества сидов вместо мгновенного (по умолчанию: выключено)">
													находить среднее значение количества сидов за
												</label>
												<div class="col-2">
													<div class="input-group input-group-sm">
													<span class="input-group-addon">
														<input id="avg_seeders" name="avg_seeders" title="При фильтрации раздач будет использоваться среднее значение количества сидов вместо мгновенного (по умолчанию: выключено)" type="checkbox" size="24" <?php echo $avg_seeders ?>>
													</span>
														<input id="avg_seeders_period" name="avg_seeders_period" class="form-control" title="Укажите период хранения сведений о средних сидах, максимум 30 дней (по умолчанию: 14)" type="number" min="1" max="30" size="2" value="<?php echo $cfg['avg_seeders_period'] ?>">
														<span class="input-group-addon">дн.</span>
													</div>
												</div>
											</div>
										</div>
										<h5>Регулировка раздач<sup>1</sup></h5>
										<div class="form-group col-12">
											<div class="row">
												<label for="peers" class="col-6 col-form-label" title="Укажите числовое значение пиров, при котором требуется останавливать раздачи в торрент-клиентах (по умолчанию: 10)">
													Останавливать раздачи с количеством пиров более:
												</label>
												<div class="col-1">
													<input id="peers" name="peers" class="form-control form-control-sm" type="number" min="1" size="2" value="<?php echo $cfg['topics_control']['peers'] ?>" >
												</div>
											</div>
											<div class="form-check">
												<label class="form-check-label" title="Установите, если необходимо учитывать значение личей при регулировке, иначе будут браться только значения сидов (по умолчанию: выключено)">
													<input name="leechers" class="form-check-input" type="checkbox" <?php echo $leechers ?> >
													учитывать значение личей
												</label>
											</div>
											<div class="form-check">
												<label class="form-check-label" title="Выберите, если нужно запускать раздачи с 0 (нулём) личей, когда нет скачивающих (по умолчанию: включено)">
													<input name="no_leechers" class="form-check-input" type="checkbox" <?php echo $no_leechers ?> >
													запускать раздачи с 0 (нулём) личей
												</label>
											</div>
										</div>
										<p class="footnote"><sup>1</sup>Необходимо настроить запуск скрипта control.php. Обратитесь к п.5 <a target="_blank" href="manual.pdf">руководства</a> за подробностями.</p>
									</div>
								</div>
							</div>

							<div class="card">
								<div class="card-header" role="tab" data-toggle="collapse" data-parent="#accordion" data-target="#torrents-download">
									<h6 class="mb-0">
										<a class="collapsed" href="#torrents-download">Настройки загрузки торрент-файлов</a>
									</h6>
								</div>
								<div id="torrents-download" class="collapse" role="tabpanel">
									<div class="card-body">
										<h5>Каталог для скачиваемых *.torrent файлов</h5>
										<div class="form-group col-5">
											<input id="savedir" name="savedir" class="form-control form-control-sm" size="53" title="Каталог, куда будут сохраняться новые *.torrent-файлы." value="<?php echo $cfg['save_dir'] ?>" >
											<div class="form-check">
												<label class="form-check-label" title="При установленной метке *.torrent-файлы дополнительно будут помещены в подкаталог.">
													<input name="savesubdir" class="form-check-input" type="checkbox" size="24" <?php echo $savesubdir ?>>
													создавать подкаталоги
												</label>
											</div>
										</div>
										<h5>Настройки retracker.local</h5>
										<div class="form-group col-5">
											<div class="form-check">
												<label class="form-check-label" title="Добавлять retracker.local в скачиваемые *.torrent-файлы.">
													<input name="retracker" class="form-check-input" type="checkbox" size="24" <?php echo $retracker ?>>
													добавлять retracker.local в скачиваемые *.torrent-файлы
												</label>
											</div>
										</div>
										<h5>Скачивание *.torrent файлов с заменой Passkey</h5>
										<div class="form-group col-7">
											<div class="row">
												<label for="dir_torrents" class="col-2 col-form-label">
													Каталог:
												</label>
												<div class="col-10">
													<input id="dir_torrents" name="dir_torrents" class="form-control form-control-sm" size="53" title="Каталог, в который требуется сохранять торрент-файлы с изменённым Passkey." value="<?php echo $cfg['dir_torrents'] ?>" />
												</div>
											</div>
											<div class="row">
												<label for="passkey" class="col-2 col-form-label">
													Passkey:
												</label>
												<div class="col-10">
													<input id="passkey" name="passkey" class="form-control form-control-sm" size="15" title="Passkey, который необходимо вшить в скачиваемые торрент-файлы." value="<?php echo $cfg['user_passkey'] ?>" />
												</div>
											</div>

											<div class="form-check">
												<label class="form-check-label">
													<input name="tor_for_user" class="form-check-input" type="checkbox" size="24" <?php echo $tor_for_user ?>>
													скачать торрент-файлы для обычного пользователя
												</label>
											</div>
										</div>
									</div>
								</div>
							</div>
						</div>
					</form>
				</div>
				<div id="reports" class="tab-pane fade" role="tabpanel"></div>
				<div id="statistics" class="tab-pane fade" role="tabpanel">
					<div>
						<input id="get_statistics" type="submit" class="btn btn-sm btn-outline-dark mb-2" value="Отобразить статистику" title="Получить статистику по хранимым подразделам">
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
									<th colspan="12">&mdash;</th>
								</tr>
							</tbody>
							<tfoot></tfoot>
						</table>
					</div>
				</div>
				<div id="journal" class="tab-pane fade" role="tabpanel">
					<div id="log"></div>
				</div>
				<div id="manual" class="tab-pane fade" role="tabpanel">
					<object data="manual.pdf" type="application/pdf" width="100%" height="100%"></object>
				</div>
			</div>
		</div>

		<div class="modal fade" id="delete_torrent_modal" tabindex="-1" role="dialog">
			<div class="modal-dialog" role="document">
				<div class="modal-content">
					<div class="modal-header">
						<h5 class="modal-title">Удалить загруженные файлы раздач с диска ?</h5>
						<button type="button" class="close" data-dismiss="modal" aria-label="Close">
							<span>&times;</span>
						</button>
					</div>
					<div class="modal-footer">
						<button type="button" class="btn btn-danger" data-dismiss="modal" id="remove_data">Да</button>
						<button type="button" class="btn btn-primary" data-dismiss="modal" id="remove">Нет</button>
						<button type="button" class="btn btn-outline-dark" data-dismiss="modal">Отмена</button>
					</div>
				</div>
			</div>
		</div>

		<div class="modal fade" id="set_label_modal" tabindex="-1" role="dialog">
			<div class="modal-dialog" role="document">
				<div class="modal-content">
					<div class="modal-header">
						<h5 class="modal-title">Установить метку</h5>
						<button type="button" class="close" data-dismiss="modal" aria-label="Close">
							<span>&times;</span>
						</button>
					</div>
					<div class="modal-body">
						<label class="mr-sm-2" for="any_label">Метка:</label>
						<input class="form-control" id="any_label" size="27" title="Метка" />
					</div>
					<div class="modal-footer">
						<button type="button" class="btn btn-primary" data-dismiss="modal" id="set_custom_label">Ок</button>
						<button type="button" class="btn btn-outline-dark" data-dismiss="modal">Отмена</button>
					</div>
				</div>
			</div>
		</div>
		<!-- скрипты webtlo -->
		<script src="js-libs/jquery.js"></script>
		<script src="js-libs/popper.min.js"></script>
		<script src="js-libs/bootstrap.min.js"></script>
		<script src="js-libs/external/jquery.mousewheel.js"></script>
		<script src="js-libs/external/js.cookie.js"></script>
		<script src="js-libs/bootstrap3-typeahead.js"></script>
		<script src="js-libs/bootstrap-datepicker.js"></script>
		<script src="js-libs/bootstrap-datepicker.ru.min.js"></script>
		<script src="js-libs/jquery.dataTables.min.js"></script>
		<script src="js-libs/dataTables.bootstrap4.min.js"></script>

		<script type="text/javascript" src="js/common.js"></script>
		<script type="text/javascript" src="js/tor_clients.js"></script>
		<script type="text/javascript" src="js/subsections.js"></script>
		<script type="text/javascript" src="js/webtlo.js"></script>
		<script type="text/javascript" src="js/topics.js"></script>

	</body>
</html>
