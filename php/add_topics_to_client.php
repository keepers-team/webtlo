<?php

include dirname(__FILE__) . '/../clients.php';
include dirname(__FILE__) . '/../api.php';
include dirname(__FILE__) . '/../common.php';

if(isset($_POST['client'])) {
	list($comment, $client, $host, $port, $login, $paswd) = explode("|", $_POST['client']);
	$cl['cm'] = $comment;
	$cl['cl'] = $client;
	$cl['ht'] = $host;
	$cl['pt'] = $port;
	$cl['lg'] = $login;
	$cl['pw'] = $paswd;
}

if(isset($_POST['subsec'])) {
	list($ss_id, $ss_title, $ss_client, $ss_label, $ss_savepath) = explode("|", $_POST['subsec']);
	$subsection['id'] = $ss_id;
	$subsection['na'] = $ss_title;
	$subsection['cl'] = $ss_client;
	$subsection['lb'] = $ss_label;
	$subsection['sp'] = $ss_savepath;
}

if(isset($_POST['cfg'])) {
	parse_str($_POST['cfg']);
	$retracker = isset($retracker) ? 1 : 0;
	$proxy_activate = isset($proxy_activate) ? 1 : 0;
	$proxy_address = $proxy_hostname . ':' . $proxy_port;
	$proxy_auth = $proxy_login . ':' . $proxy_paswd;
}

$topics = $_POST['topics'];

$tmpdir = dirname(dirname(__FILE__)) . '/tfiles/';

$log = get_now_datetime() . 'Запущен процесс добавления раздач в торрент-клиент "' . $cl['cm'] . '"...<br />';

try {	
	// создаём временный каталог
	if(is_dir($tmpdir))
		rmdir_recursive($tmpdir);
	elseif(!mkdir($tmpdir))
		throw new Exception(get_now_datetime() . 'Не удалось создать каталог: "' . $tmpdir . '".<br />');
	
	// скачиваем торрент-файлы
	$dl = new Download($api_key, $proxy_activate, $proxy_type, $proxy_address, $proxy_auth);
	$success = $dl->download_torrent_files($tmpdir, $forum_url, $TT_login, $TT_password, $topics, $retracker, $dl_log);
	$log .= $dl->log;	
	$q = preg_replace("|.*<span[^>]*?>(.*)</span>.*|sei", '$1', $dl_log); // кол-во	
	if(empty($success)){
		$add_log = 'Нет раздач для добавления.<br />';
		throw new Exception();
	}
	
	// добавляем раздачи в торрент-клиент
	$log .= get_now_datetime() . 'Добавление раздач в торрент-клиент...<br />';
	$client = new $cl['cl']($cl['ht'], $cl['pt'], $cl['lg'], $cl['pw']);
	if($client->is_online()) {
		$client->torrentAdd($success, $subsection['sp'], $subsection['lb']);
		$success = array_column_common($success, 'id');
	}  else {
		$success = null;
		$add_log = 'Указанный в настройках торрент-клиент недоступен.<br />';
		throw new Exception(str_replace('{cm}', $cl['cm'], $client->log));
	}
	$add_log = 'Добавлено в торрент-клиент "' . $cl['cm'] . '": <span class="rp-header">'. $q . '</span> шт.<br />';
	$log .= str_replace('{cm}', $cl['cm'], $client->log) .
		get_now_datetime() . 'Добавление торрент-файлов завершено.<br />';
	
	// выводим на экран
	echo json_encode(array('log' => $log, 'add_log' => $add_log, 'success' => $success));
	//~ echo $log;
	
} catch (Exception $e) {
	$log .= $e->getMessage();
	echo json_encode(array('log' => $log, 'add_log' => $add_log, 'success' => $success));
	//~ echo $log;
}

?>
