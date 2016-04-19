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

$log = "\nОбновление сведений\n" . date('d.m.Y / H:i:s') . "\n\n ====== Начало ======\n\n";

/* получение настроек */

$cfg = array();
$ini = new TIniFileEx(dirname(__FILE__) . '/../config.ini');

// формирование списка т.-клиентов
$cfg['qt'] = $ini->read("other", "qt", '');
if(isset($cfg['qt'])) {
	for($i = 1; $i <= $cfg['qt']; $i++) {
		$comment = $ini->read("torrent-client-$i","comment","");
		$cfg['tcs'][$comment]['cm'] = $comment;
		$cfg['tcs'][$comment]['cl'] = $ini->read("torrent-client-$i","client","");
		$cfg['tcs'][$comment]['ht'] = $ini->read("torrent-client-$i","hostname","");
		$cfg['tcs'][$comment]['pt'] = $ini->read("torrent-client-$i","port","");
		$cfg['tcs'][$comment]['lg'] = $ini->read("torrent-client-$i","login","");
		$cfg['tcs'][$comment]['pw'] = $ini->read("torrent-client-$i","password","");
	}
}

$cfg['proxy_activate'] = $ini->read('proxy','activate',0);
$cfg['proxy_address'] = $ini->read('proxy','hostname','195.82.146.100') . ':' . $ini->read('proxy','port','3128');
$cfg['proxy_auth'] = $ini->read('proxy','login','') . ':' . $ini->read('proxy','password','');
$cfg['proxy_type'] = $ini->read('proxy','type','http');

$cfg['lg'] = $ini->read('torrent-tracker','login','');
$cfg['pw'] = $ini->read('torrent-tracker','password','');
$cfg['api_key'] = $ini->read('torrent-tracker','api_key','');
$cfg['api_url'] = $ini->read('torrent-tracker','api_url','cc');
$cfg['ss'] = $ini->read('sections','subsections','');
$cfg['rt'] = $ini->read('sections','rule_topics',3);
$cfg['retracker'] = $ini->read('download','retracker',0);
$cfg['title'][] = (($ini->read('tor_status','tor_checked',1) == '1')?"проверено":"");
$cfg['title'][] = (($ini->read('tor_status','tor_not_checked','') == '1')?"не проверено":"");
$cfg['title'][] = (($ini->read('tor_status','tor_not_decoration','') == '1')?"недооформлено":"");
$cfg['title'][] = (($ini->read('tor_status','tor_doubtfully',1) == '1')?"сомнительно":"");
$cfg['title'][] = (($ini->read('tor_status','tor_temporary','') == '1')?"временная":"");

/* 
 * раскомментировать две строки ниже,
 * если получаем ошибку "Настройки некорректны."
 * см. пустые значения
 */
//~ print_r($cfg);
//~ return;

try {	
	
	/* проверяем настройки */
	if(in_array('', $cfg)) {
		throw new Exception (date("H:i:s") . " Настройки некорректны.\n");
	}	
	
	/* получение данных от т.-клиентов */
	$tc_topics = get_tor_client_data($cfg['tcs'], $log);
	
	/* получение данных с api.rutracker.org */
	$webtlo = new Webtlo($cfg['api_key'], $cfg['api_url'], $cfg['proxy_activate'], $cfg['proxy_type'], $cfg['proxy_address'], $cfg['proxy_auth']);
	$status = $webtlo->get_tor_status_titles($cfg['title']); /* статусы раздач на трекере */
	$subsections = $webtlo->get_cat_forum_tree($cfg['ss']); /* обновляем дерево разделов */
	$ids = $webtlo->get_subsection_data($subsections, $status); /* получаем список раздач разделов */
	$topics = $webtlo->get_tor_topic_data($ids, $tc_topics, $cfg['rt'], $cfg['ss']); /* получаем подробные сведения о раздачах */
	
} catch (Exception $e) {
	$webtlo->log .= $e->getMessage();
}

$log .= $webtlo->log;
$log .= "\n ====== Конец ======\n\nЗавершено\n" . date('d.m.Y / H:i:s');

$endtime1 = microtime(true);

$log .= "\n\nОбщее время выполнения: " . round($endtime1-$starttime, 1) . " с.\n";

$log = str_replace('<br />', ''."\n".'', $log);

$file_log = fopen(dirname(__FILE__) . "/update.log", "w");
fwrite($file_log, $log);
fclose($file_log);

?>
