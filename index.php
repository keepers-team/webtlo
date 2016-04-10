<?php
/*
 * web-TLO v.0.8.1.4 (Web Torrent List Organizer)
 * index.php
 * author: Cuser (cuser@yandex.ru)
 * previous change: 29.04.2014
 * editor: berkut_174 (webtlo@yandex.ru)
 * last change: 10.03.2016
 */ 

Header("Cache-Control: no-cache, no-store, must-revalidate, max-age=0");

error_reporting(0); //закоментить при отладке
mb_internal_encoding("UTF-8");

include dirname(__FILE__) . '/api.php';
include dirname(__FILE__) . '/talk_to_tcs.php';
include dirname(__FILE__) . '/gui.php';
include dirname(__FILE__) . '/common.php';
//~ include dirname(__FILE__) . '/simple_html_dom.php';

/*
 * api.php -- data from api.rutracker.org classes
 * talk_to_tcs.php -- data from torrent-client functions
 * gui.php -- output to html functions
 * common.php -- usage: index.php, gui.php, api.php, talt_to_tcs.php
 * simple_html_dom.php -- parser: http://sourceforge.net/projects/simplehtmldom/
 */
 
if(!ini_get('date.timezone'))
	date_default_timezone_set('Europe/Moscow');

// вывод первой страницы,
if(!isset($_POST['m'])) {
	output_main();
	return;
}

/* получаем настройки */

// общие с формы #config
if(isset($_POST['cfg'])) {
	parse_str($_POST['cfg']); 
	$savesubdir = isset($savesubdir) ? 1 : 0;
	$retracker = isset($retracker) ? 1 : 0;
	$proxy_activate = isset($proxy_activate) ? 1 : 0;
	$proxy_address = $proxy_hostname . ':' . $proxy_port;
	$proxy_auth = $proxy_login . ':' . $proxy_paswd;
}
	
// формирование списка т.-клиентов
if(isset($_POST['tcs'])) {
	//~ $tcs = array();
	foreach($_POST['tcs'] as $tc){
		list($comment, $client, $host, $port, $login, $paswd) = explode("|", $tc);
		$tcs[$comment]['cm'] = $comment;
		$tcs[$comment]['cl'] = $client;
		$tcs[$comment]['ht'] = $host;
		$tcs[$comment]['pt'] = $port;
		$tcs[$comment]['lg'] = $login;
		$tcs[$comment]['pw'] = $paswd;
	}
}

// статусы раздач
if(isset($_POST['tor_status'])) {
	foreach($_POST['tor_status'] as $value){
		list($status_name, $status_title, $status_value) = explode("|", $value);
		$tor_status['save'][$status_name] = $status_value;
		if($status_value)
			$tor_status['title'][] = $status_title;
	}
}

/* конец получения настроек */

/*
 * 'savecfg' - сохранение настроек,
 * 'reports' - формирование отчётов,
 * 'topics' - формирование списка раздач для хранения,
 * 'update' - обновление сведений о раздачах
 * 'download' - скачивание т.-файлов
*/

class ExceptionExt extends Exception { }

switch($_POST['m'])
{
	//------------------------------------------------------------------
	case 'savecfg':
		//~ print_r($proxy_address . $proxy_auth);
		write_config(
			dirname(__FILE__) . '/config.ini', $TT_login, $TT_password, $TT_subsections,
			$TT_rule_topics, $TT_rule_reports, $savedir, $savesubdir,
			$retracker,	$tcs, $bt_key, $api_key, $api_url, $tor_status['save'],
			$proxy_activate, $proxy_type, $proxy_address, $proxy_auth
		);
		break;
	//------------------------------------------------------------------
	case 'reports':
		try {
			$db = new FromDatabase();
			$subsections = $db->get_forums_details($TT_subsections);
			$topics = $db->get_topics($TT_rule_reports, 1);
			output_preparation($topics, $subsections);				
			output_reports($subsections, $TT_login, $db->log);
		} catch (Exception $e) {
			$db->log .= $e->getMessage();
			echo json_encode(array('log' => $db->log,
				'report' => '<br /><div>Нет или недостаточно данных для
				отображения. Выполните обновление сведений.</div><br />'
			));
		}
		break;
	//------------------------------------------------------------------
	case 'topics':
		try {
			$db = new FromDatabase();
			$subsections = $db->get_forums($TT_subsections);
			$topics = $db->get_topics($TT_rule_topics, 0);
			output_topics($topics, $subsections, $db->log);
		} catch (Exception $e) {
			$db->log .= $e->getMessage();
			echo json_encode(array('log' => $db->log,
				'topics' => '<br /><div>Нет или недостаточно данных для
				отображения. Выполните обновление сведений.</div><br />'
			));
		}
		break;
	//------------------------------------------------------------------
	case 'download':
		try {
			$subsection = $_POST['subsec'];
			try {
				// проверяем существование указанного каталога
				if(!is_writable($savedir)) {
				//~ if(!is_writable(mb_convert_encoding($savedir, 'Windows-1251', 'UTF-8'))) {
					throw new ExceptionExt('<span class="errors">Каталог "' .
						$savedir . '" не существует или недостаточно прав.
						Скачивание невозможно.</span><br />'
					);
				}
				
				// если задействованы подкаталоги
				if($savesubdir) {						
					$savedir .= 'tfiles_' . $subsection . '_' .
						date("(d.m.Y_H.i.s)") . '_' . $TT_rule_topics .
						substr($savedir, -1);
					$res = (is_writable($savedir) || mkdir($savedir)) ? true : false;
					// по сути такая проверка не нужна, маловероятно, что
					// созданный каталог не будет доступен на запись
					
					// создался ли подкаталог
					if(!$res) {	
						throw new ExceptionExt('<span class="errors">Ошибка при
							создании подкаталога: неверно указан путь или
							недостаточно прав. Скачивание невозможно.
							</span><br />'
						);
					}
				}					
			} catch (ExceptionExt $e) {
				$dl_log = $e->getMessage();
				throw new Exception;
			}
			
			$topics = $_POST['id']; // массив из идентификаторов топиков для скачивания
			
			// если нужные каталоги присутствуют,
			// то выполняем скачивание т.-файлов
			$dl = new Download($api_key, $proxy_activate, $proxy_type, $proxy_address, $proxy_auth);
			$dl->download_torrent_files($savedir, $TT_login, $TT_password, $topics,	$retracker, $dl_log);
			
		} catch (Exception $e) {
			$dl->log .= $e->getMessage();
			if(!isset($dl_log))
				$dl_log = 'Ошибка при скачивании торрент-файлов. Обратитесь
				к журналу событий за подробностями.<br />';
		}
		echo json_encode(array('log' => $dl->log,
			'dl_log' => $dl_log));
			
		break;
	//------------------------------------------------------------------
	case 'update':
		try {
			$log = '';
			$tc_topics = get_tor_client_data($tcs, $log); /* обновляем сведения от т.-клиентов */
			$webtlo = new Webtlo($api_key, $api_url, $proxy_activate, $proxy_type, $proxy_address, $proxy_auth);
			$status = $webtlo->get_tor_status_titles($tor_status['title']); /* статусы раздач на трекере */
			$subsections = $webtlo->get_cat_forum_tree($TT_subsections); /* обновляем дерево разделов */
			$ids = $webtlo->get_subsection_data($subsections, $status); /* получаем список раздач разделов */
			$topics = $webtlo->get_tor_topic_data($ids, $tc_topics, $TT_rule_topics, $TT_subsections); /* получаем подробные сведения о раздачах */
			output_topics($topics, $subsections, $log . $webtlo->log);
		} catch (Exception $e) {
			$webtlo->log .= $e->getMessage();
			echo json_encode(array('log' => $webtlo->log,
				'topics' => '<br />В процессе обновления сведений
				были ошибки.<br />Для получения подробностей
				обратитесь к журналу событий.<br />'
			));
		}
		break;
	//------------------------------------------------------------------
}

/*
 * "0": "не проверено",
 * "1": "закрыто",
 * "2": "проверено",
 * "3": "недооформлено",
 * "4": "не оформлено",
 * "5": "повтор",
 * "6": "закрыто правообладателем",
 * "7": "поглощено",
 * "8": "сомнительно",
 * "9": "проверяется",
 * "10": "временная",
 * "11": "премодерация"
 */

?>
