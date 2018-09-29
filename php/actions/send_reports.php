<?php

include dirname(__FILE__) . '/../../common.php';
include dirname(__FILE__) . '/../../api.php';
include dirname(__FILE__) . '/../../reports.php';

try {
	
	// парсим настройки
	if ( isset ( $_POST['cfg'] ) ) {
		parse_str( $_POST['cfg'] );
	}
	
	if ( empty ( $_POST['forum_ids'] ) ) {
		throw new Exception( "Error: Не выбраны хранимые подразделы." );
	}
	
	if ( empty ( $tracker_username ) ) {
		throw new Exception( "Error: Не указано имя пользователя на трекере." );
	}
	
	if ( empty ( $tracker_password ) ) {
		throw new Exception( "Error: Не указан пароль пользователя на трекере." );
	}
	
	$forum_ids = $_POST['forum_ids'];
	$forum_links = $_POST['forum_links'];
	
	// формирование отчётов
	$reports = create_reports( $forum_ids, $tracker_username );
	
	// прокси
	$activate_forum = isset( $proxy_activate_forum ) ? 1 : 0;
	$activate_api = isset( $proxy_activate_api ) ? 1 : 0;
	$proxy_address = "$proxy_hostname:$proxy_port";
	$proxy_auth = "$proxy_login:$proxy_paswd";
	Proxy::options( $activate_forum, $activate_api, $proxy_type, $proxy_address, $proxy_auth );
	
	// отправка отчётов на форум
	$send = new Reports( $forum_url, $tracker_username, $tracker_password );
	$send->send_reports( $api_key, $api_url, $reports, $forum_links );
	
	echo Log::get();
	
} catch ( Exception $e ) {
	Log::append( $e->getMessage() );
	echo Log::get();
}

?>
