<?php

include dirname(__FILE__) . '/../common.php';
include dirname(__FILE__) . '/../clients.php';
include dirname(__FILE__) . '/../api.php';

$cfg = get_settings();

$clients = [];
if(isset($cfg['clients'])){
	foreach($cfg['clients'] as $id => &$client){
		$clients[$id]['cl'] = $client['cl'];
		$clients[$id]['cm'] = $client['cm'];
		$clients[$id]['ht'] = $client['ht'];
		$clients[$id]['pt'] = $client['pt'];
		$clients[$id]['lg'] = $client['lg'];
		$clients[$id]['pw'] = $client['pw'];

	}
} else $clients = '';

$subsections = [];
if(isset($cfg['subsections'])){
	foreach($cfg['subsections'] as $id => &$subsection){
		$subsections[$id]['cl'] = $subsection['cl'];
		$subsections[$id]['lb'] = $subsection['lb'];
		$subsections[$id]['df'] = $subsection[`df`];
		$subsections[$id]['ln'] = $subsection['ln'];
		$subsections[$id]['sub_folder'] = $subsection['sub_folder'];
		$subsections[$id]['id'] = $subsection['id'];

	}
} else $subsections = '';

Log::append ( 'Запущен процесс добавления раздач в торрент-клиент ...' );

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
	$dl                   = new Download ( $api_key, $tmpdir );
	$success              = $dl->download_torrent_files($forum_url, $user_id, $topics, $retracker, $add_log);
	$quantity_of_torrents = preg_replace("|.*<span[^>]*?>(.*)</span>.*|si", '$1', $add_log); // кол-во
	if ( empty ( $success ) ){
		$add_log = 'Нет скачанных торрент-файлов для добавления их в торрент-клиент.<br />';
		throw new Exception();
	}

	// добавляем раздачи в торрент-клиент
	Log::append ( 'Добавление раздач в торрент-клиент...' );

	$torrents_chunks_for_clients = array();
	foreach ($success as $torrent) {
		$torrents_chunks_for_clients[$torrent["subsection"]][] = $torrent;
	}
	$success = array();

	$clients_with_new_torrents = array();
	foreach ( $torrents_chunks_for_clients as $subsection_id => $torrents_chunk_for_client ) {
		$client_id = $subsections[$subsection_id]['cl'];
		if (!empty($clients[$client_id]['cl'])) {
			$client = new $clients[$client_id]['cl'] ( $clients[$client_id]['ht'],
				$clients[$client_id]['pt'], $clients[$client_id]['lg'], $clients[$client_id]['pw'], $clients[$client_id]['cm'] );
			if($client->is_online()) {
				$client->torrentAdd ( $torrents_chunk_for_client, $subsections[$subsection_id]['fd'], $subsections[$subsection_id]['lb'], $subsections[$subsection_id]['sub_folder'] );
				$success[$client_id] = array_column_common ( $torrents_chunk_for_client, 'id' );
			}  else {
				$add_log = 'Указанный в настройках торрент-клиент недоступен.<br />';
				throw new Exception();
			}
			$clients_with_new_torrents[] = $clients[$client_id]['cm'];
		} else {
			$add_log = 'Не задан клиент для раздела ' . $subsection_id;
		}
	}

	$add_log = 'Добавлено в торрент-клиент "' . implode(", ", $clients_with_new_torrents) . '": <span class="rp-header">' . $quantity_of_torrents . '</span> шт.<br />';

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
