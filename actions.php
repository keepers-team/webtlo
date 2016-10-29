<?php

include dirname(__FILE__) . '/api.php';
include dirname(__FILE__) . '/clients.php';
include dirname(__FILE__) . '/gui.php';
include dirname(__FILE__) . '/common.php';
include dirname(__FILE__) . '/reports.php';

// разбираем настройки

// общие с формы #config
if(isset($_POST['cfg'])) {
	parse_str($_POST['cfg']); 
	$savesubdir = isset($savesubdir) ? 1 : 0;
	$retracker = isset($retracker) ? 1 : 0;
	$avg_seeders = isset($avg_seeders) ? 1 : 0;
	$avg_seeders_period = $avg_seeders_period == 0 ? 1 : ($avg_seeders_period > 30 ? 30 : $avg_seeders_period); // жёсткое ограничение на 30 дн.
	$proxy_activate = isset($proxy_activate) ? 1 : 0;
	$proxy_address = $proxy_hostname . ':' . $proxy_port;
	$proxy_auth = $proxy_login . ':' . $proxy_paswd;
}

// подразделы
if(isset($_POST['subsec'])){
	$TT_subsections = $_POST['subsec'];
}

// торрент-клиенты
if(isset($_POST['tcs'])) {
	$tcs = $_POST['tcs'];
}

// замена passkey
if(isset($_POST['edit'])) {
	$edit = $_POST['edit'];
}

/*
 * 'savecfg' - сохранение настроек,
 * 'reports' - формирование отчётов,
 * 'topics' - формирование списка раздач для хранения,
 * 'update' - обновление сведений о раздачах
 * 'download' - скачивание т.-файлов
*/

switch($_POST['m'])
{
	//------------------------------------------------------------------
	case 'savecfg':
		write_config(dirname(__FILE__) . '/config.ini', $_POST['cfg'], $TT_subsections, $tcs);
		break;
	//------------------------------------------------------------------
	case 'reports':
		try {
			$db = new Database();
			$subsections = $db->get_forums_details($TT_subsections);
			$topics = $db->get_topics($TT_rule_reports, 1, $avg_seeders_period);
			$reports = create_reports($subsections, $topics); unset($topics);
			output_reports($reports, $TT_login, $db->log);
		} catch (Exception $e) {
			$db->log .= $e->getMessage();
			echo json_encode(array('log' => $db->log,
				'report' => '<br /><div>Нет или недостаточно данных для
				отображения.<br />Проверьте настройки и выполните обновление сведений.</div><br />'
			));
		}
		break;
	//------------------------------------------------------------------
	case 'send':
		try {
			$db = new Database();
			$subsec = array_column_common($TT_subsections, 'id');
			$subsections = $db->get_forums_details($subsec);
			$topics = $db->get_topics($TT_rule_reports, 1, $avg_seeders_period);
			$reports = create_reports($subsections, $topics); unset($topics);
			$send = new Reports($forum_url, $TT_login, $TT_password, $proxy_activate, $proxy_type, $proxy_address, $proxy_auth);
			$send->send_reports($api_key, $api_url, $reports, $TT_subsections);
			echo $db->log . $send->log;
		} catch (Exception $e) {
			echo $db->log . (isset($send->log) ? $send->log : '') . $e->getMessage();
		}
		break;
	//------------------------------------------------------------------
	case 'topics':
		try {
			$db = new Database();
			$subsections = $db->get_forums($TT_subsections);
			$topics = $db->get_topics($TT_rule_topics, 0, $avg_seeders_period);
			$keepers = $db->get_keepers();
			output_topics($forum_url, $topics, $subsections, $TT_rule_topics, $avg_seeders_period, $avg_seeders, $keepers, $db->log);
		} catch (Exception $e) {
			$db->log .= $e->getMessage();
			echo json_encode(array('log' => $db->log,
				'topics' => '<br /><div>Нет или недостаточно данных для
				отображения.<br />Проверьте настройки и выполните обновление сведений.</div><br />'
			));
		}
		break;
	//------------------------------------------------------------------
	case 'download':
		try {
			$topics = $_POST['topics']; // массив из идентификаторов топиков для скачивания
			$dl = new Download($api_key, $proxy_activate, $proxy_type, $proxy_address, $proxy_auth);
			$dl->create_directories($savedir, $savesubdir, $TT_subsections, $TT_rule_topics, $dir_torrents, $edit, $dl_log);
			$dl->download_torrent_files($forum_url, $TT_login, $TT_password, $topics, $retracker, $dl_log, $passkey, $edit);
			echo json_encode(array('log' => $dl->log, 'dl_log' => $dl_log));
		} catch (Exception $e) {
			$dl->log .= $e->getMessage();
			echo json_encode(array('log' => $dl->log, 'dl_log' => $dl_log));
		}
		break;
	//------------------------------------------------------------------
	case 'update':
		try {
			$subsec = array_column_common($TT_subsections, 'id');
			$tc_topics = get_tor_client_data($tcs, $log); /* обновляем сведения от т.-клиентов */
			$reports = new Reports($forum_url, $TT_login, $TT_password, $proxy_activate, $proxy_type, $proxy_address, $proxy_auth);
			$keepers = $reports->search_keepers($TT_subsections, $log);
			$db = new Database();
			$db->set_keepers($keepers);
			$webtlo = new Webtlo($api_key, $api_url, $proxy_activate, $proxy_type, $proxy_address, $proxy_auth);
			$subsections = $webtlo->get_cat_forum_tree($subsec); /* обновляем дерево разделов */
			$ids = $webtlo->get_subsection_data($subsections, $topics_status);
			$output = $webtlo->prepare_topics($ids, $tc_topics, $TT_rule_topics, $subsec, $avg_seeders, $avg_seeders_period);
			output_topics($forum_url, $output, $subsections, $TT_rule_topics, $avg_seeders_period, $avg_seeders, $keepers, $log . $webtlo->log);
		} catch (Exception $e) {
			$webtlo->log .= $e->getMessage();
			echo json_encode(array('log' => $log . $webtlo->log,
				'topics' => '<br />В процессе обновления сведений
				были ошибки.<br />Для получения подробностей
				обратитесь к журналу событий.<br />'
			));
		}
		break;
	//------------------------------------------------------------------
}

?>
