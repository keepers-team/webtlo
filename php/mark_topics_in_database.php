<?php

include dirname(__FILE__) . '/../common.php';

$log = get_now_datetime() . 'Обновление списка топиков для хранения...<br />';

try {
	if(!isset($_POST['success']))
		throw new Exception(get_now_datetime() . 'Список топиков не нуждается в обновлении.<br />');
	
	$status = $_POST['status'];
	$client = $_POST['client'];
	$db = new PDO('sqlite:' . dirname(dirname(__FILE__)) . '/webtlo.db');
	$update = array_chunk($_POST['success'], 500, false); // не более 500 за раз
	foreach($update as $topics){
		array_unshift($topics, $status, $client);
		$in = str_repeat('?,', count($topics) - 1) . '?';
		$sql = "UPDATE `Topics` SET `dl` = ?, `cl` = ? WHERE `id` IN ($in)";
		$sth = $db->prepare($sql);
		if($db->errorCode() != '0000') {
			$db_error = $db->errorInfo();
			throw new Exception(get_now_datetime() . 'SQL ошибка: ' . $db_error[2] . '<br />');
		}
		$sth->execute($topics);
	}
	echo $log;
} catch (Exception $e) {
	$log .= $e->getMessage();
	echo $log;
}

?>
