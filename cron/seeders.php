<?php

include dirname(__FILE__) . '/../common.php';
include dirname(__FILE__) . '/../api.php';
include dirname(__FILE__) . '/../reports.php';

if(!ini_get('date.timezone'))
	date_default_timezone_set('Europe/Moscow');

try {
	
	Log::append( "Обновление информации о сидах для всех раздач трекера..." );
	
	$starttime = microtime(true);
	
	$filelog = "seeders.log";
	
	// получение настроек
	$cfg = get_settings();
	
	// если выключены средние сиды
	if( !$cfg['avg_seeders'] )
		throw new Exception( "Error: Средние сиды отключены в настройках." );
	
	// получение данных
	$webtlo = new Webtlo( $cfg['api_url'], $cfg['api_key'] );
	$forums = $webtlo->get_cat_forum_tree();
	$forums = Db::query_database( "SELECT id,id,na FROM Forums WHERE id NOT IN (${cfg['subsec']})", array(), true, PDO::FETCH_ASSOC|PDO::FETCH_UNIQUE );
	$forums = array_chunk( $forums, 50, true);
	
	if( empty( $forums ) )
		throw new Exception( "Error: Получен пустой список подразделов." );
	
	// получаем дату предыдущего обновления
	$se = Db::query_database( "SELECT se FROM Other", array(), true, PDO::FETCH_COLUMN );
	$current = new DateTime( 'now' );
	$last = new DateTime();
	$last->setTimestamp( $se[0] )->setTime( 0, 0, 0 );
	
	// создаём временную таблицу
	Db::query_database( "CREATE TEMP TABLE Topics1 AS SELECT id,ss,st,se,rg,dl,qt,ds FROM Topics WHERE 0 = 1" );
	
	foreach( $forums as $forums ) {
	
		$seeders = $webtlo->get_subsection_data( $forums, array(0,2,3,8,10), 'all' );
		$seeders = array_chunk( $seeders, 500, true );
		
		// записываем данные во временную таблицу
		foreach( $seeders as $seeders ) {
			$in = str_repeat( '?,', count( $seeders ) - 1 ) . '?';
			$values = Db::query_database( "SELECT id,se,rg,qt,ds FROM Topics WHERE id IN ($in)", array_keys( $seeders ), true, PDO::FETCH_ASSOC|PDO::FETCH_UNIQUE );
			foreach( $seeders as $topic_id => $value ) {
				// $info: 0 - tor_status, 1 - seeders, 2 - reg_time, 3 - subsection
				$info = explode( ',', $value );
				$days = 0;
				$sum_updates = 1;
				$sum_seeders = $info[1];
				if( isset( $values[$topic_id] ) ) {
					if( empty( $values[$topic_id]['rg'] ) || $values[$topic_id]['rg'] == $info[2] ) {
						$days = $values[$topic_id]['ds'];
						if ( !empty( $last ) && $current->diff($last)->format('%d') > 0 ) {
							$days++;
						} else {
							$sum_updates += $values[$topic_id]['qt'];
							$sum_seeders += $values[$topic_id]['se'];
						}
					} else {
						$delete[] = $topic_id;
					}
				}
				$tmp[$topic_id]['ss'] = $info[3];
				$tmp[$topic_id]['st'] = $info[0];
				$tmp[$topic_id]['se'] = $sum_seeders;
				$tmp[$topic_id]['rg'] = $info[2];
				$tmp[$topic_id]['dl'] = 0;
				$tmp[$topic_id]['qt'] = $sum_updates;
				$tmp[$topic_id]['ds'] = $days;
			}
			if( isset( $tmp ) ) {
				$select = Db::combine_set( $tmp );
				Db::query_database( "INSERT INTO temp.Topics1 $select" );
			}
			unset( $tmp );
		}
		unset( $seeders );
	}
	unset( $forums );
	
	// удаляем перерегистрированные раздачи
	if( !empty( $delete ) ) {
		$in = implode( ',', $delete );
		Db::query_database( "DELETE FROM Topics WHERE id IN ($in)" );
	}
	
	$q = Db::query_database( "SELECT COUNT() FROM temp.Topics1", array(), true, PDO::FETCH_COLUMN );
	if ( $q[0] > 0 ) {
		Log::append ( 'Запись в базу данных сведений о сидах...' );
		Db::query_database( "INSERT INTO Topics (id,ss,st,se,rg,dl,qt,ds) SELECT * FROM temp.Topics1 WHERE id NOT IN ( SELECT id FROM Topics WHERE dl = -2 )" );
		Db::query_database( "DELETE FROM Topics WHERE id IN ( SELECT Topics.id FROM Topics LEFT JOIN temp.Topics1 ON Topics.id = temp.Topics1.id WHERE temp.Topics1.id IS NULL AND Topics.ss NOT IN (${cfg['subsec']}) AND Topics.dl <> -2 )" );
	}
	
	// время последнего обновления
	Db::query_database( 'UPDATE Other SET se = ? WHERE id = 0', array( $current->format('U') ) );
	
	$endtime = microtime(true);
	
	Log::append( "Обновление завершено (общее время выполнения: " . round( $endtime - $starttime, 1 ) . " с)." );
	
	Log::write ( $filelog );
	
} catch (Exception $e) {
	Log::append( $e->getMessage() );
	Log::write ( $filelog );
}

?>
