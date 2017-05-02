<?php

include dirname(__FILE__) . '/../common.php';
include dirname(__FILE__) . '/../clients.php';
include dirname(__FILE__) . '/../api.php';

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
	list($ss_client, $ss_label, $ss_savepath, $ss_link) = explode("|", $_POST['subsec']);
	$subsection['cl'] = $ss_client;
	$subsection['lb'] = $ss_label;
	$subsection['fd'] = $ss_savepath;
	$subsection['ln'] = $ss_link;
}

Log::append ( 'Запущен процесс добавления раздач в торрент-клиент "' . $cl['cm'] . '"...' );

if(isset($_POST['cfg'])) {
	parse_str($_POST['cfg']);
	$retracker = isset($retracker) ? 1 : 0;
	$active = isset($proxy_activate) ? 1 : 0;
	$proxy_address = $proxy_hostname . ':' . $proxy_port;
	$proxy_auth = $proxy_login . ':' . $proxy_paswd;
	Proxy::options ( $active, $proxy_type, $proxy_address, $proxy_auth );
}

$topics = $_POST['topics'];

$tmpdir = dirname(dirname(__FILE__)) . '/tfiles/';

try {
	$success = null;
	
	// создаём временный каталог
	if(is_dir($tmpdir))
		rmdir_recursive($tmpdir);
	elseif(!mkdir($tmpdir))
		throw new Exception( 'Не удалось создать временный каталог: "' . $tmpdir . '".' );
	
	// скачиваем торрент-файлы
	$dl = new Download ( $api_key, $tmpdir );
	$success = $dl->download_torrent_files($forum_url, $user_id, $topics, $retracker, $add_log);
	preg_match("%(\d*)</span>%sei", $add_log, $q); // кол-во
	if ( empty ( $success ) ){
		$add_log = 'Нет скачанных торрент-файлов для добавления их в торрент-клиент.<br />';
		throw new Exception();
	}
	
	// добавляем раздачи в торрент-клиент
	Log::append ( 'Добавление раздач в торрент-клиент...' );
	$client = new $cl['cl'] ( $cl['ht'], $cl['pt'], $cl['lg'], $cl['pw'], $cl['cm'] );
	if($client->is_online()) {
		$client->torrentAdd ( $success, $subsection['fd'], $subsection['lb'] );
		$success = array_column_common ( $success, 'id' );
	}  else {
		$add_log = 'Указанный в настройках торрент-клиент недоступен.<br />';
		throw new Exception();
	}
	$add_log = 'Добавлено в торрент-клиент "' . $cl['cm'] . '": <span class="rp-header">'. $q . '</span> шт.<br />';
	Log::append ( 'Добавление торрент-файлов завершено.' );
	
	// выводим на экран
	echo json_encode(array('log' => Log::get(), 'add_log' => $add_log, 'success' => $success));
	//~ echo Log::get();
	
} catch (Exception $e) {
	Log::append ( $e->getMessage() );
	echo json_encode(array('log' => Log::get(), 'add_log' => $add_log, 'success' => $success));
	//~ echo Log::get();
}

?>
