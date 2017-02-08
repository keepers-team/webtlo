<?php

include dirname(__FILE__) . '/../common.php';
include dirname(__FILE__) . '/../api.php';
include dirname(__FILE__) . '/../clients.php';

if(!ini_get('date.timezone'))
	date_default_timezone_set('Europe/Moscow');

try {
	
	Log::append( "Начат процесс регулировки раздач в торрент-клиентах..." );
	
	$starttime = microtime(true);
	$filelog = "control.log";
	
	// получение настроек
	$cfg = get_settings();
	Proxy::options( $cfg['proxy_activate'], $cfg['proxy_type'], $cfg['proxy_address'], $cfg['proxy_auth'] );
	
	// получение данных от т.-клиентов
	$tc_topics = get_tor_client_data( $cfg['clients'] );
	
	if( empty( $tc_topics ) )
		throw new Exception( 'Не получены данные от торрент-клиентов.' );
	
	if( is_array( $tc_topics ) )
		$hashes = array_keys( $tc_topics );
	
	// получаем данные с api
	$webtlo = new Webtlo( $cfg['api_url'], $cfg['api_key'] );
	Log::append( 'Получение идентификаторов хранимых раздач...' );
	$ids = $webtlo->get_topic_id( $hashes );
	Log::append( 'Получение сведений о пирах для хранимых раздач...' );
	$topics = $webtlo->get_peer_stats( $ids );
	
	if( empty( $topics ) )
		throw new Exception( 'Не получены данные о мгновенных пирах.' );
		
	// выполняем регулировку раздач
	topics_control( $topics, $tc_topics, $ids, $cfg['topics_control'], $cfg['clients'] );
	
	$endtime = microtime(true);
	Log::append( "Регулировка раздач в торрент-клиентах завершена (общее время выполнения: " . round( $endtime - $starttime, 1 ) . " с)." );
	
	Log::write( $filelog );
	
} catch (Exception $e) {
	Log::append( $e->getMessage() );
	Log::write( $filelog );
}

?>
