<?php

include dirname(__FILE__) . '/../common.php';
include dirname(__FILE__) . '/../clients.php';

if(isset($_POST['topics'])){
	$topics = $_POST['topics'];
	if(!empty($topics) && is_array($topics)){
		$cm = array_diff( array_unique(array_column_common($topics, 'client')), array('') );
		foreach($topics as $topic){
			if(!empty($topic['client'])){
				$hashes[$topic['client']]['hash'][] = $topic['hash'];
				$hashes[$topic['client']]['id'][] = $topic['id'];
			}
		}
	}
}

if(isset($_POST['clients'])) {
	$clients = $_POST['clients'];
	if(!empty($clients) && is_array($clients)){
		foreach($clients as $id => $client){
			if(in_array($id, $cm))
				$tcs[$id] = $client;
		}
	}
}

$action = isset($_POST['action']) ? $_POST['action'] : '';
$data = isset($_POST['remove_data']) ? $_POST['remove_data'] : '';
$force = isset($_POST['force_start']) ? $_POST['force_start'] : '';
$label = isset($_POST['label']) ? $_POST['label'] : '';

$ids = array();

try {
	
	if(empty($action)){
		$result = 'Не указано действие, которое требуется выполнить.<br />';
		throw new Exception();
	}
	if(empty($cm)){
		$result = 'Выбранные раздачи не привязаны ни к одному из торрент-клиентов.<br />';
		throw new Exception();
	}
	if(!isset($hashes)){
		$result = 'Не получены данные о выбранных раздачах.<br />';
		throw new Exception();
	}
	if(!isset($tcs)){
		$result = 'Не удалось найти ни одного из необходимых торрент-клиентов в настройках.<br />';
		throw new Exception();
	}
	if(empty($label) && $action == 'set_label'){
		$result = 'Попытка установить пустую метку.<br />';
		throw new Exception();
	}
	
	Log::append ( 'Начато выполнение действия "'.$action.'" для выбранных раздач...' );
	Log::append ( 'Количество затрагиваемых торрент-клиентов: '.count($cm).'.' );
	
	foreach ( $cm as $cm ) {
		if ( isset( $tcs[$cm] ) ) {
			$client = new $tcs[$cm]['cl'] ( $tcs[$cm]['ht'], $tcs[$cm]['pt'], $tcs[$cm]['lg'], $tcs[$cm]['pw'], $tcs[$cm]['cm'] );
			if ( $client->is_online() ) {
				switch ( $action ) {
					case 'set_label':
						Log::append ( $client->setLabel($hashes[$cm]['hash'], $label) );
						break;
					case 'stop':
						Log::append ( $client->torrentStop($hashes[$cm]['hash']) );
						break;
					case 'start':
						Log::append ( $client->torrentStart($hashes[$cm]['hash'], $force) );
						break;
					case 'remove':
						Log::append ( $client->torrentRemove($hashes[$cm]['hash'], $data) );
						break;
					default:
						$result = 'Невозможно выполнить действие: "'.$action.'".<br />';
						throw new Exception();
				}
				$ids[] = $hashes[$cm]['id'];
				Log::append ( 'Действие "'.$action.'" для "'.$tcs[$cm]['cm'].'" выполнено ('.count($hashes[$cm]['id']).').' );
			} else {
				Log::append ( 'Error: действие "'.$action.'" для "'.$tcs[$cm]['cm'].'" не выполнено.' );
				continue;
			}
		}
	}
	$result = 'Действие "'.$action.'" выполнено. За подробностями обратитесь к журналу.<br />';
	Log::append ( 'Выполнение действия "'.$action.'" завершено.' );
	// выводим на экран
	echo json_encode(array('log' => Log::get(), 'result' => $result, 'ids' => empty($ids) ? null : $ids));
	//~ echo Log::get();
	
} catch (Exception $e) {
	Log::append ( $e->getMessage() );
	echo json_encode(array('log' => Log::get(), 'result' => $result, 'ids' => null));
	//~ echo Log::get();
}

?>
