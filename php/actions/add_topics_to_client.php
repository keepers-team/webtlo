<?php

include dirname(__FILE__) . '/../../common.php';
include dirname(__FILE__) . '/../../clients.php';
include dirname(__FILE__) . '/../download.php';

try {

	$add_log = "";

	if ( empty( $_POST['forum'] ) || empty( $_POST['tor_client'] ) || empty( $_POST['topics_ids'] ) ) {
		throw new Exception();
	}
	
	if ( isset( $_POST['cfg'] ) ) {
		parse_str( $_POST['cfg'] );
	}
	
	if ( empty( $api_key ) ) {
		throw new Exception( "Error: Не указан хранительский ключ API." );
	}
	
	if ( empty( $user_id ) ) {
		throw new Exception( "Error: Не указан хранительский ключ ID." );
	}
	
	$forum = $_POST['forum'];
	$topics_ids = $_POST['topics_ids'];
	$tor_client = $_POST['tor_client'];
	$retracker = isset( $retracker ) ? 1 : 0;
	
	// прокси
	$activate = isset( $proxy_activate ) ? 1 : 0;
	$proxy_address = "$proxy_hostname:$proxy_port";
	$proxy_auth = "$proxy_login:$proxy_paswd";
	Proxy::options( $activate, $proxy_type, $proxy_address, $proxy_auth );

	Log::append( "Запущен процесс добавления раздач в торрент-клиент \"${tor_client['cm']}\" " );
	
	$tmpdir = dirname( __FILE__ ) . '/../../tfiles/';
	
	// очищаем временный каталог
	if ( is_dir( $tmpdir ) ) {
		rmdir_recursive( $tmpdir );
	}
	
	// скачиваем торрент-файлы
	$download = new Download ( $api_key );
	$download->create_directories( $tmpdir, $add_log );
	$downloaded_files = $download->download_torrent_files( $forum_url, $user_id, $topics_ids, $retracker, $add_log );
	$downloaded_count = preg_replace( "|.*<span[^>]*?>(.*)</span>.*|si", "$1", $add_log ); // кол-во
	if ( empty( $downloaded_files ) ) {
		$add_log = "Нет скачанных торрент-файлов для добавления их в торрент-клиент";
		throw new Exception();
	}
	
	// дополнительный слэш в конце каталога
	if ( ! in_array( substr( $forum['fd'], -1 ), array( '\\', '/' ) ) ) {
		$forum['fd'] .= strpos( $forum['fd'], '/' ) === false ? '\\' : '/';
	}
	
	Log::append( "Добавление раздач в торрент-клиент..." );
	
	$client = new $tor_client['cl'] ( $tor_client['ht'], $tor_client['pt'], $tor_client['lg'], $tor_client['pw'], $tor_client['cm'] );
	// проверяем доступность торрент-клиента
	if ( ! $client->is_online() ) {
		$add_log = "Указанный в настройках торрент-клиент недоступен";
		throw new Exception();
	}
	// добавляем раздачи
	$client->torrentAdd( $downloaded_files, $forum['fd'], $forum['lb'], $forum['sub_folder'] );
	// помечаем добавленные раздачи в базе
	$added_files = array_column_common( $downloaded_files, 'id' );
	$added_files = array_chunk( $added_files, 500 );
	foreach ( $added_files as $added_files ) {
		$in = str_repeat( '?,', count( $added_files ) - 1 ) . '?';
		Db::query_database(
			"UPDATE Topics SET dl = -1, cl = ${tor_client['id']} WHERE id IN ($in)",
			$added_files
		);
	}

	$add_log = "Добавлено в торрент-клиент \"${tor_client['cm']}\": <span class=\"rp-header\">$downloaded_count</span> шт.";

	Log::append( "Добавление торрент-файлов завершено." );
	
	// выводим на экран
	echo json_encode( array(
		'log' => Log::get(),
		'add_log' => $add_log
	));
	
} catch ( Exception $e ) {
	Log::append( $e->getMessage() );
	echo json_encode( array(
		'log' => Log::get(),
		'add_log' => $add_log
	));
}

?>
