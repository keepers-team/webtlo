<?php

include dirname(__FILE__) . '/../../common.php';
include dirname(__FILE__) . '/../download.php';

try {
	
	$result = "";
	
	if ( empty( $_POST['topics_ids'] ) ) {
		$result = "Выберите раздачи";
		throw new Exception();
	}
	
	// парсим настройки
	if ( isset( $_POST['cfg'] ) ) {
		parse_str( $_POST['cfg'] );
	}
	
	if ( empty( $api_key ) ) {
		$result = "В настройках не указан хранительский ключ API";
		throw new Exception();
	}
	
	if ( empty( $user_id ) ) {
		$result = "В настройках не указан хранительский ключ ID";
		throw new Exception();
	}
	
	if ( isset( $_POST['replace_passkey'] ) ) {
		$replace_passkey = $_POST['replace_passkey'];
	}
	
	$retracker = isset( $retracker ) ? 1 : 0;
	$tor_for_user = isset( $tor_for_user ) ? 1 : 0;
	$forum_id = isset ( $_POST['forum_id'] ) ? $_POST['forum_id'] : 0;
	parse_str( $_POST['topics_ids'] );
	
	// дополнительный слэш в конце каталога
	if ( ! empty( $savedir ) && ! in_array( substr( $savedir, -1 ), array( '\\', '/' ) ) ) {
		$savedir .= strpos( $savedir, '/' ) === false ? '\\' : '/';
	}
	
	// подгтовка каталогов
	$savedir = empty ( $replace_passkey )
		? ! empty( $savedir ) && isset( $savesubdir )
			? $savedir . 'tfiles_' . $forum_id . '_' . date( "d.m.Y_H.i.s" ) . '_' . $rule_topics . substr( $savedir, -1 )
			: $savedir
		: $dir_torrents;
	
	if ( empty( $savedir ) ) {
		$result = "В настройках не указан каталог для скачивания торрент-файлов";
		throw new Exception();
	}
	
	// прокси
	$activate_forum = isset( $proxy_activate_forum ) ? 1 : 0;
	$activate_api = isset( $proxy_activate_api ) ? 1 : 0;
	$proxy_address = "$proxy_hostname:$proxy_port";
	$proxy_auth = "$proxy_login:$proxy_paswd";
	Proxy::options( $activate_forum, $activate_api, $proxy_type, $proxy_address, $proxy_auth );
	
	// создание каталогов
	if ( ! mkdir_recursive( $savedir ) ) {
		$result = "Не удалось создать каталог \"$savedir\": неверно указан путь или недостаточно прав";
		throw new Exception();
	}
	
	// скачивание торрент-файлов
	$start = microtime( true );
	$download = new Download( $api_key );
	$download->savedir = $savedir;
	$downloaded_files = $download->download_torrent_files( $forum_url, $user_id, $topics_ids, $retracker, $passkey, $replace_passkey, $tor_for_user );
	$downloaded_count = count( $downloaded_files );
	$end = microtime( true );
	
	$result = "Сохранено в каталоге \"$savedir\": $downloaded_count шт. (за " . round( $end - $start, 1 ). " с).";
	
	echo json_encode(array(
		'log' => Log::get(),
		'result' => $result
	));
	
} catch ( Exception $e ) {
	Log::append( $e->getMessage() );
	echo json_encode(array(
		'log' => Log::get(),
		'result' => $result
	));
}

?>
