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
	$tc_topics = get_tor_client_data ( $cfg['clients'] );
	
	// получение данных с api.rutracker.org
	$subsec = array_keys ( $cfg['subsections'] );
	$webtlo = new Webtlo ( $cfg['api_url'], $cfg['api_key'] );
	$subsections = $webtlo->get_cat_forum_tree ( $subsec );
	$ids = $webtlo->get_subsection_data ( $subsections, $cfg['topics_status'] );
	$output = $webtlo->prepare_topics($ids, $tc_topics, $cfg['rule_topics'], $subsec, $cfg['avg_seeders'], $cfg['avg_seeders_period']);
	
	$endtime = microtime(true);
	Log::append ( "Обновление сведений завершено (общее время выполнения: " . round($endtime-$starttime, 1) . " с)." );
	
	Log::write ( $filelog );

} catch (Exception $e) {
	Log::append ( $e->getMessage() );
	Log::write ( $filelog );
}

?>
