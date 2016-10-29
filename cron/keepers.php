<?php

include dirname(__FILE__) . '/../api.php';
include dirname(__FILE__) . '/../common.php';
include dirname(__FILE__) . '/../reports.php';

if(!ini_get('date.timezone'))
	date_default_timezone_set('Europe/Moscow');
	
$starttime = microtime(true);

$filelog =  dirname(__FILE__) . "/update.log";

// получение настроек
$cfg = get_settings();

try {
	
	$log = get_now_datetime() . "Начато обновление списка раздач других хранителей...\n";
	$reports = new Reports($cfg['forum_url'], $cfg['tracker_login'], $cfg['tracker_paswd'], $cfg['proxy_activate'], $cfg['proxy_type'], $cfg['proxy_address'], $cfg['proxy_auth']);
	$keepers = $reports->search_keepers($cfg['subsections'], $log);
	
	// пишем в базу
	$db = new Database();
	$log .= get_now_datetime() . "Запись полученных данных в базу...\n";
	$db->set_keepers($keepers);
	
	// открываем файл лога
	if(!$filelog = fopen($filelog, "a"))
		throw new Exception (get_now_datetime() . "Не удалось создать файл лога.\n");
	
	$endtime = microtime(true);
	$log .= get_now_datetime() . "Обновление списка раздач других хранителей завершено (общее время выполнения: " . round($endtime-$starttime, 1) . " с).\n";
	$log = str_replace('<br />', ''."\n".'', $log);
	
	// записываем в файл
	fwrite($filelog, $log);
	fclose($filelog);
	
} catch (Exception $e) {
	$log .= $e->getMessage();
	$log = str_replace('<br />', ''."\n".'', $log);
	// пытаемся записать в файл
	if($filelog = fopen($filelog, "a")){
		fwrite($filelog, $log);
		fclose($filelog);
	}
}

?>
