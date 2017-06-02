<?php

include_once dirname(__FILE__) . '/../common.php';
include_once dirname(__FILE__) . '/../api.php';
include_once dirname(__FILE__) . '/../reports.php';

try {
	
	$starttime = microtime(true);
	
	Log::append ( "Начато выполнение процесса отправки отчётов..." );
	
	$filelog = "reports.log";
	
	// получение настроек
	$cfg = get_settings();
	$subsec = array_keys( $cfg['subsections'] );
	
	// получение данных из базы
	$db = new Database();
	$subsections = $db->get_forums_details( $subsec );
	$topics = $db->get_topics( $subsec, 1, $cfg['avg_seeders'], $cfg['avg_seeders_period'], 'na' );
	
	// формирование отчётов
	$reports = create_reports( $subsections, $topics, $cfg['tracker_login'], $cfg['rule_reports'] );
	unset($subsections);
	unset($topics);
	
	$send = new Reports( $cfg['forum_url'], $cfg['tracker_login'], $cfg['tracker_paswd'] );
	$send->send_reports( $cfg['api_key'], $cfg['api_url'], $reports, $cfg['subsections'] );
	
	$endtime = microtime(true);
	
	Log::append( "Отправка отчётов завершена (общее время выполнения: " . round($endtime-$starttime, 1) . " с)." );
	
	Log::write( $filelog );
	
} catch (Exception $e) {
	Log::append( $e->getMessage() );
	Log::write( $filelog );
}

?>
