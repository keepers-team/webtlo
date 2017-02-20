<?php

include dirname(__FILE__) . '/../common.php';

Log::append ( 'Обновление списка раздач...' );

try {
	if(!isset($_POST['success']))
		throw new Exception( 'Список не нуждается в обновлении.' );
	
	$status = $_POST['status'];
	$client = $_POST['client'];
	
	$update = array_chunk($_POST['success'], 500, false); // не более 500 за раз
	
	foreach( $update as &$topics ) {
		array_unshift($topics, $status, $client);
		$in = str_repeat('?,', count($topics) - 1) . '?';
		Db::query_database( "UPDATE Topics SET dl = ?, cl = ? WHERE id IN ($in)", $topics );
	}
	echo Log::get();
} catch (Exception $e) {
	Log::append ( $e->getMessage() );
	echo Log::get();
}

?>
