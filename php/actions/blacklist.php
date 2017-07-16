<?php

include dirname(__FILE__) . '/../../common.php';

// массив раздач
$ids = isset( $_POST['topics'] )
	? array_column_common( $_POST['topics'], 'id' )
	: array();
	
$value = empty( $_POST['value'] ) ? 0 : 1;

try {
	
	if ( empty( $ids ) ) {
		throw new Exception( "Error: Не получены идентификаторы выделенных раздач." );
	}
	
	$ids = array_chunk( $ids, 500 );
	
	foreach ( $ids as $ids ) {
		
		
		switch ( $value ) {
			case 0:
				$in = str_repeat( '?,', count( $ids ) - 1 ) . '?';
				Db::query_database( "DELETE FROM Blacklist WHERE topic_id IN ($in)", $ids );
				break;
			case 1:
				$select = str_repeat( 'SELECT ? UNION ALL ', count( $ids ) - 1 ) . ' SELECT ?';
				Db::query_database( "INSERT INTO Blacklist (topic_id) $select", $ids );
				break;
			default:
				throw new Exception( "Error: Неизвестное событие." );
		}
	}
	
	echo 'Обновление "чёрного списка" раздач успешно завершено.';
	
} catch ( Exception $e ) {
	
	echo $e->getMessage();
	
}

?>
