<?php

include dirname(__FILE__) . '/../clients.php';
include dirname(__FILE__) . '/../common.php';

if(isset($_POST['client'])) {
	list($comment, $client, $host, $port, $login, $paswd) = explode("|", $_POST['client']);
	$cl['cm'] = $comment;
	$cl['cl'] = $client;
	$cl['ht'] = $host;
	$cl['pt'] = $port;
	$cl['lg'] = $login;
	$cl['pw'] = $paswd;
}

if(isset($_POST['subsec'])) {
	list($ss_id, $ss_title, $ss_client, $ss_label, $ss_savepath) = explode("|", $_POST['subsec']);
	$subsection['id'] = $ss_id;
	$subsection['na'] = $ss_title;
	$subsection['cl'] = $ss_client;
	$subsection['lb'] = $ss_label;
	$subsection['sp'] = $ss_savepath;
}

if(isset($_POST['topics'])){
	$topics = $_POST['topics'];
	$hashes = array_column_common($topics, 'hash');
	$ids = array_column_common($topics, 'id');
}

if(isset($_POST['action'])) $action = $_POST['action'];

$data = $_POST['remove_data'];
$force = $_POST['force_start'];
$label = empty($_POST['label']) ? $subsection['lb'] : $_POST['label'];

try {
	
	$log = get_now_datetime() . 'Начато выполнение действия "'.$action.'" для выбранных раздач...<br />';
	$client = new $cl['cl']($cl['ht'], $cl['pt'], $cl['lg'], $cl['pw']);
	if($client->is_online()) {
		switch($action) {
			case 'set_label':
				$client->log .= $client->setLabel($hashes, $label);
				break;
			case 'stop':
				$client->log .= $client->torrentStop($hashes);
				break;
			case 'start':
				$client->log .= $client->torrentStart($hashes, $force);
				break;
			case 'remove':
				$client->log .= $client->torrentRemove($hashes, $data);
				break;
			default:
				throw new Exception();
		}
	} else {
		$ids = null;
		$result = 'Указанный в настройках торрент-клиент недоступен.<br />';
		throw new Exception(str_replace('{cm}', $cl['cm'], $client->log));
	}
	$result = 'Запрос на выполнение действия "'.$action.'" успешно отправлен.<br />';
	$log .= str_replace('{cm}', $cl['cm'], $client->log) .
		get_now_datetime() . 'Выполнение действия "'.$action.'" завершено.<br />';
	
	// выводим на экран
	echo json_encode(array('log' => $log, 'result' => $result, 'ids' => $ids));
	//~ echo $log;
	
} catch (Exception $e) {
	$log .= $e->getMessage();
	echo json_encode(array('log' => $log, 'result' => $result, 'ids' => $ids));
	//~ echo $log;
}

?>
