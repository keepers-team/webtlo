<?php
/*
 * web-TLO (Web Torrent List Organizer)
 * update.php
 * author: berkut_174 (webtlo@yandex.ru)
 * last change: 11.02.2016
 */
	
include dirname(__FILE__) . '/../api.php';
include dirname(__FILE__) . '/../clients.php';
include dirname(__FILE__) . '/../common.php';

/*
 * читаем настройки
 * получаем данные от т.-клиентов
 * получаем данные с api.rutracker.org
 * пишем лог в файл
 */

if(!ini_get('date.timezone'))
	date_default_timezone_set('Europe/Moscow');
	
$starttime = microtime(true);

$log = get_now_datetime() . "Начато обновление сведений о раздачах...\n";
$filelog =  dirname(__FILE__) . "/update.log";

// получение настроек
$cfg = get_settings();

try {
	
	if(!isset($cfg['subsections'])){
		throw new Exception (get_now_datetime() . "В настройках не указаны сканируемые подразделы.\n");
	}
	
	// получение данных от т.-клиентов
	$tc_topics = get_tor_client_data($cfg['clients'], $log);
	
	// получение данных с api.rutracker.org
	$webtlo = new Webtlo($cfg['api_key'], $cfg['api_url'], $cfg['proxy_activate'], $cfg['proxy_type'], $cfg['proxy_address'], $cfg['proxy_auth']);
	$subsections = $webtlo->get_cat_forum_tree($cfg['subsections_line']);
	$ids = $webtlo->get_subsection_data($subsections, $cfg['topics_status']);
	$topics = $webtlo->get_tor_topic_data($ids);
	$ids = $webtlo->get_topic_id(array_diff(array_keys($tc_topics), array_column_common($topics, 'info_hash')));
	$topics += $webtlo->get_tor_topic_data($ids);
	$output = $webtlo->preparation_of_topics($topics, $tc_topics, $cfg['rule_topics'], $cfg['subsections_line'], $cfg['avg_seeders'], $cfg['avg_seeders_period'], $cfg['topics_status']);
	
	// переименовываем файл лога, если он больше 5 Мб
	if(file_exists($filelog) && filesize($filelog) >= 5242880){
		if(!rename($filelog, preg_replace('|.log$|', '.1.log', $filelog)))
			throw new Exception (get_now_datetime() . "Не удалось переименовать файл лога.\n");
	}
	
	// открываем файл лога
	if(!$filelog = fopen($filelog, "a"))
		throw new Exception (get_now_datetime() . "Не удалось создать файл лога.\n");
	
	$log .= $webtlo->log;
	$endtime = microtime(true);
	$log .= get_now_datetime() . "Обновление сведений завершено (общее время выполнения: " . round($endtime-$starttime, 1) . " с).\n";
	$log = str_replace('<br />', ''."\n".'', $log);
	
	// записываем в файл
	fwrite($filelog, $log);
	fclose($filelog);

} catch (Exception $e) {
	if(isset($webtlo->log)) $log .= $webtlo->log;
	$log .= $e->getMessage();
	$log = str_replace('<br />', ''."\n".'', $log);
	// пытаемся записать в файл
	if($filelog = fopen($filelog, "a")){
		fwrite($filelog, $log);
		fclose($filelog);
	}
}

?>
