<?php

include dirname(__FILE__) . '/../common.php';
include dirname(__FILE__) . '/../api.php';
include dirname(__FILE__) . '/../reports.php';

if(!ini_get('date.timezone'))
	date_default_timezone_set('Europe/Moscow');

try {
	
	Log::append ( "Начато обновление списка раздач других хранителей..." );
	
	$starttime = microtime(true);
	$filelog =  dirname(__FILE__) . "/update.log";
	
	// получение настроек
	$cfg = get_settings();
	Proxy::setting ( $cfg['proxy_type'], $cfg['proxy_address'], $cfg['proxy_auth'] );
	Proxy::init ( $cfg['proxy_activate'] );
	
	// получаем данные
	$reports = new Reports ( $cfg['forum_url'], $cfg['tracker_login'], $cfg['tracker_paswd'] );
	$keepers = $reports->search_keepers ( $cfg['subsections'] );
	
	// пишем в базу
	$db = new Database();
	$db->set_keepers ( $keepers );
	
	$endtime = microtime(true);
	Log::append ( "Обновление списка раздач других хранителей завершено (общее время выполнения: " . round($endtime-$starttime, 1) . " с)." );
	
	Log::write ( $filelog );
	
} catch (Exception $e) {
	Log::append ( $e->getMessage() );
	Log::write ( $filelog );
}

?>
