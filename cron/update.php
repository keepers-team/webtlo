<?php
	
include dirname(__FILE__) . '/../common.php';
include dirname(__FILE__) . '/../api.php';
include dirname(__FILE__) . '/../clients.php';
include dirname(__FILE__) . '/../reports.php';

if(!ini_get('date.timezone'))
	date_default_timezone_set('Europe/Moscow');

try {
	
	Log::append ( "Начато обновление сведений о раздачах..." );
	
	$starttime = microtime(true);
	$filelog = "update.log";
	
	// получение настроек
	$cfg = get_settings();
	
	if(!isset($cfg['subsections']))
		throw new Exception ( "В настройках не указаны сканируемые подразделы." );
	
	// получение данных от т.-клиентов
	$tor_clients_topics = get_tor_client_data ( $cfg['clients'] );
	
	// получение данных с api.rutracker.org
	$forum_ids = array_keys ( $cfg['subsections'] );
	$api = new Api ( $cfg['api_url'], $cfg['api_key'] );
	$api->get_cat_forum_tree ( $forum_ids );
	$topic_ids = $api->get_subsection_data ( $forum_ids );
	$api->prepare_topics( $topic_ids, $tor_clients_topics, $forum_ids, $cfg['avg_seeders'] );
	
	$endtime = microtime(true);
	Log::append ( "Обновление сведений завершено (общее время выполнения: " . round($endtime-$starttime, 1) . " с)." );
	
	Log::write ( $filelog );

} catch (Exception $e) {
	Log::append ( $e->getMessage() );
	Log::write ( $filelog );
}

?>
