<?php

include dirname(__FILE__) . '/../../common.php';
include dirname(__FILE__) . '/../download.php';

try {
	
	$dl_log = "";
	
	if ( empty ( $_POST['ids'] ) ) {
		throw new Exception();
	}
	
	// парсим настройки
	if ( isset ( $_POST['cfg'] ) ) {
		parse_str( $_POST['cfg'] );
	}
	
	if ( isset( $_POST['replace_passkey'] ) ) {
		$replace_passkey = $_POST['replace_passkey'];
	}
	
	if ( empty ( $api_key ) ) {
		throw new Exception( "Error: Не указан хранительский ключ API." );
	}
	
	if ( empty ( $user_id ) ) {
		throw new Exception( "Error: Не указан хранительский ключ ID." );
	}
	
	$retracker = isset( $retracker ) ? 1 : 0;
	$tor_for_user = isset( $tor_for_user ) ? 1 : 0;
	$forum_id = isset ( $_POST['forum_id'] ) ? $_POST['forum_id'] : 0;
	$ids = $_POST['ids'];
	
	$savedir = empty ( $replace_passkey )
		? isset( $savesubdir )
			? $savedir . 'tfiles_' . $forum_id . '_' . date( "d.m.Y_H.i.s" ) . '_' . $rule_topics . substr( $savedir, -1 )
			: $savedir
		: $dir_torrents;
	
	// прокси
	$activate = isset( $proxy_activate ) ? 1 : 0;
	$proxy_address = "$proxy_hostname:$proxy_port";
	$proxy_auth = "$proxy_login:$proxy_paswd";
	Proxy::options( $activate, $proxy_type, $proxy_address, $proxy_auth );
	
	// скачивание
	$dl = new Download( $api_key );
	$dl->create_directories( $savedir, $dl_log );
	$dl->download_torrent_files( $forum_url, $user_id, $ids, $retracker, $dl_log, $passkey, $replace_passkey, $tor_for_user );
	
	echo json_encode(array(
		'log' => Log::get(),
		'result' => $dl_log
	));
	
} catch ( Exception $e ) {
	Log::append( $e->getMessage() );
	echo json_encode(array(
		'log' => Log::get(),
		'result' => $dl_log
	));
}

?>
