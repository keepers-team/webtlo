<?php

include dirname(__FILE__) . '/../../common.php';

try {

	if ( empty( $_POST['topics_ids'] ) ) {
		$result = "Выберите раздачи";
		throw new Exception();
	}

	parse_str( $_POST['topics_ids'] );
	
	$value = empty( $_POST['value'] ) ? 0 : 1;
	
	$topics_ids = array_chunk( $topics_ids, 500 );
	
	foreach ( $topics_ids as $topics_ids ) {
		switch ( $value ) {
			case 0:
				$in = str_repeat( '?,', count( $topics_ids ) - 1 ) . '?';
				Db::query_database( "DELETE FROM Blacklist WHERE topic_id IN ($in)", $topics_ids );
				break;
			case 1:
				$select = str_repeat( 'SELECT ? UNION ALL ', count( $topics_ids ) - 1 ) . ' SELECT ?';
				Db::query_database( "INSERT INTO Blacklist (topic_id) $select", $topics_ids );
				break;
			default:
				throw new Exception( "Error: Неизвестное событие" );
		}
	}
	
	echo 'Обновление "чёрного списка" раздач успешно завершено';
	
} catch ( Exception $e ) {
	
	echo $e->getMessage();
	
}

?>
