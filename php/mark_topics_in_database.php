<?php

include dirname(__FILE__) . '/../common.php';

try {
	
	if( empty($_POST['success']) ) throw new Exception();
	
	$status = $_POST['status'];
	//$client = $_POST['client'];
	$success = $_POST['success'];
	
	foreach ($success as $client_id => $topics) {
		$in = implode( ',', $topics );
		if( is_numeric($status) ) {
			Db::query_database(
				"UPDATE Topics SET dl = :dl, cl = :cl WHERE id IN ($in)",
				array( 'dl' => $status, 'cl' => $client_id )
			);
		} else {
			// удалить раздачу, если она не из хранимого подраздела
			Db::query_database( "DELETE FROM Topics WHERE id IN ($in)" );
		}

		echo Log::get();
	}

} catch (Exception $e) {
	Log::append ( $e->getMessage() );
	echo Log::get();
}

?>
