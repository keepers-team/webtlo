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
	$forum_ids = array_keys( $cfg['subsections'] );
	$forum_links = array_column_common( $cfg['subsections'], 'ln' );
	
	// формирование отчётов
	$reports = create_reports( $forum_ids, $cfg['tracker_login'] );
	
	$send = new Reports( $cfg['forum_url'], $cfg['tracker_login'], $cfg['tracker_paswd'] );
	$send->send_reports( $cfg['api_key'], $cfg['api_url'], $reports, $forum_links );
	
	$endtime = microtime(true);
	
	Log::append( "Отправка отчётов завершена (общее время выполнения: " . round($endtime-$starttime, 1) . " с)." );
	
	Log::write( $filelog );
	
} catch (Exception $e) {
	Log::append( $e->getMessage() );
	Log::write( $filelog );
}

?>
