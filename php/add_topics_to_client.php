<?php

include dirname(__FILE__) . '/../common.php';
include dirname(__FILE__) . '/../clients.php';
include dirname(__FILE__) . '/download.php';

$settings = get_settings();

$clients = [];
if(isset( $settings['clients'])){
	foreach( $settings['clients'] as $id => &$client){
		foreach($client as $parameter => $value) {
			$clients[$id][$parameter] = $value;
		}

		/*$clients[$id]['cl'] = $client['cl'];
		$clients[$id]['cm'] = $client['cm'];
		$clients[$id]['ht'] = $client['ht'];
		$clients[$id]['pt'] = $client['pt'];
		$clients[$id]['lg'] = $client['lg'];
		$clients[$id]['pw'] = $client['pw'];*/
	}
};

$subsections = [];
if(isset( $settings['subsections'])){
	foreach( $settings['subsections'] as $id => &$subsection){
		foreach($subsection as $parameter => $value) {
			$subsections[$id][$parameter] = $value;
		}
		/*$subsections[$id]['cl'] = $subsection['cl'];
		$subsections[$id]['lb'] = $subsection['lb'];
		$subsections[$id]['df'] = $subsection['df'];
		$subsections[$id]['ln'] = $subsection['ln'];
		$subsections[$id]['sub_folder'] = $subsection['sub_folder'];
		$subsections[$id]['id'] = $subsection['id'];*/
	}
};

Log::append ( 'Запущен процесс добавления раздач в торрент-клиент ...' );

$retracker     = $settings['retracker'];
$active        = $settings['proxy_activate'];
$proxy_address = $settings['proxy_hostname'] . ':' . $settings['proxy_port'];
$proxy_auth    = $settings['proxy_login'] . ':' . $settings['proxy_paswd'];
$api_key       = $settings['api_key'];
$forum_url     = $settings['forum_url'];
$user_id       = $settings['user_id'];
Proxy::options( $active, $settings['proxy_type'], $proxy_address, $proxy_auth );

$topics = $_POST['topics'];

$tmpdir = dirname(__FILE__) . '/../tfiles/';

try {
	$success = null;
	
	// очищаем временный каталог
	if ( is_dir ( $tmpdir ) ) {
		rmdir_recursive( $tmpdir );
	}
	
	// скачиваем торрент-файлы
	$dl                   = new Download ( $api_key );
	$dl->create_directories( $tmpdir, $add_log );
	$success              = $dl->download_torrent_files($forum_url, $user_id, $topics, $retracker, $add_log);
	$quantity_of_torrents = preg_replace("|.*<span[^>]*?>(.*)</span>.*|si", '$1', $add_log); // кол-во
	if ( empty ( $success ) ){
		$add_log = 'Нет скачанных торрент-файлов для добавления их в торрент-клиент.<br />';
		throw new Exception();
	}

	// добавляем раздачи в торрент-клиент
	Log::append ( 'Добавление раздач в торрент-клиент...' );

	$torrents_chunks_for_clients = [];
	foreach ($success as $torrent) {
		$torrents_chunks_for_clients[$torrent["subsection"]][] = $torrent;
	}
	$success = [];

	$clients_with_new_torrents = [];
	foreach (
		$torrents_chunks_for_clients as $subsection_id =>
		$torrents_chunk_for_client
	) {
		if ( ! array_key_exists( $subsection_id, $subsections ) ) {
			$add_log = 'В настройках подразделов нет раздела с ID: '
			           . $subsection_id;
			throw new Exception();
		}
		$client_id = $subsections[ $subsection_id ]['cl'];
		if ( ! array_key_exists( $client_id, $clients ) ) {
			$add_log = 'В настройках у раздела ' . $subsection_id
			           . 'нет торрент-клиента c ID: ' . $client_id;
			throw new Exception();
		}
		if (!empty($clients[$client_id]['cl'])) {
			$client
				= new $clients[ $client_id ]['cl'] ( $clients[ $client_id ]['ht'],
				$clients[ $client_id ]['pt'], $clients[ $client_id ]['lg'],
				$clients[ $client_id ]['pw'], $clients[ $client_id ]['cm'] );
			if($client->is_online()) {
				// дополнительный слэш в конце каталога
				if ( ! in_array( substr( $subsections[ $subsection_id ]['df'], -1 ), array( '\\', '/' ) ) ) {
					$subsections[ $subsection_id ]['df'] .= strpos( $subsections[ $subsection_id ]['df'], '/' ) === false ? '\\' : '/';
				}
				$client->torrentAdd ( $torrents_chunk_for_client, $subsections[$subsection_id]['df'], $subsections[$subsection_id]['lb'], $subsections[$subsection_id]['sub_folder'] );
				$success[$client_id] = array_column_common ( $torrents_chunk_for_client, 'id' );
			}  else {
				$add_log = 'Указанный в настройках торрент-клиент недоступен.<br />';
				throw new Exception();
			}
			$clients_with_new_torrents[] = $clients[$client_id]['cm'];
		} else {
			Log::append ( 'Не задан клиент для раздела ' . $subsection_id );
		}
	}

	$add_log = 'Добавлено в торрент-клиент "' . implode(", ", $clients_with_new_torrents) . '": <b>' . $quantity_of_torrents . '</b> шт.<br />';

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
