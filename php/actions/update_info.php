<?php

include dirname(__FILE__) . '/../../common.php';
include dirname(__FILE__) . '/../../api.php';
include dirname(__FILE__) . '/../../clients.php';
include dirname(__FILE__) . '/../../reports.php';

try {
	
	// парсим настройки
	if ( isset ( $_POST['cfg'] ) ) {
		parse_str( $_POST['cfg'] );
	}
	
	if ( empty ( $_POST['forum_ids'] ) || empty ( $_POST['forums'] ) ) {
		throw new Exception( "Error: Не выбраны хранимые подразделы." );
	}
	
	if ( empty ( $tracker_username ) ) {
		throw new Exception( "Error: Не указано имя пользователя на трекере." );
	}
	
	if ( empty ( $tracker_password ) ) {
		throw new Exception( "Error: Не указан пароль пользователя на трекере." );
	}
	
	$forums = $_POST['forums'];
	$forum_ids = $_POST['forum_ids'];
	$tor_clients = $_POST['tor_clients'];
	$avg_seeders = isset( $avg_seeders ) ? 1 : 0;
	
	// получение данных от торрент-клиентов
	$tor_clients_topics = get_tor_client_data( $tor_clients );
	
	// прокси
	$activate_forum = isset( $proxy_activate_forum ) ? 1 : 0;
	$activate_api = isset( $proxy_activate_api ) ? 1 : 0;
	$proxy_address = "$proxy_hostname:$proxy_port";
	$proxy_auth = "$proxy_login:$proxy_paswd";
	Proxy::options( $activate_forum, $activate_api, $proxy_type, $proxy_address, $proxy_auth );
	
	// получение раздач хранимых др. хранителями
	$reports = new Reports( $forum_url, $tracker_username, $tracker_password );
	$reports->search_keepers( $forums );
	
	// получение данных с api
	$api = new Api( $api_url, $api_key );
	$api->get_cat_forum_tree();
	$topic_ids = $api->get_subsection_data( $forum_ids );
	$api->prepare_topics( $topic_ids, $tor_clients_topics, $forum_ids, $avg_seeders );
	
	echo json_encode(array(
		'log' => Log::get(),
		'result' => ""
	));
	
} catch ( Exception $e ) {
	Log::append( $e->getMessage() );
	echo json_encode(array(
		'log' => Log::get(),
		'result' => "В процессе обновления сведений были ошибки. Для получения подробностей обратитесь к журналу событий."
	));
}

?>
