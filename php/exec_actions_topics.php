<?php

include dirname(__FILE__) . '/../common.php';
include dirname(__FILE__) . '/../clients.php';

try {

	$result = "";

	// поддерживаемые действия
	$actions = array(
		"set_label",
		"start",
		"stop",
		"remove"
	);

	$action = isset( $_POST['action'] ) ? $_POST['action'] : "";
	$label = isset( $_POST['label'] ) ? $_POST['label'] : "";
	$remove_data = isset( $_POST['remove_data'] ) ? $_POST['remove_data'] : "";
	$force_start = isset( $_POST['force_start'] ) ? $_POST['force_start'] : "";

	if ( ! in_array( $action, $actions ) ) {
		$result = "Попытка выполнить неизвестное действие";
		throw new Exception();
	}

	if ( empty( $_POST['topics_ids'] ) ) {
		$result = "Выберите раздачи";
		throw new Exception();
	}

	if ( empty( $_POST['tor_clients'] ) ) {
		$result = "В настройках не найдены торрент-клиенты";
		throw new Exception();
	}

	$tor_clients = $_POST['tor_clients'];
	parse_str( $_POST['topics_ids'] );
	
	Log::append( 'Начато выполнение действия "' . $action . '" для выбранных раздач...' );
	
	Log::append( "Получение хэшей раздач с привязкой к торрент-клиенту..." );

	$topics_ids = implode( ',', $topics_ids );
	$hashes = Db::query_database(
		"SELECT cl,hs FROM Topics WHERE id IN ($topics_ids)",
		array(),
		true,
		PDO::FETCH_GROUP|PDO::FETCH_COLUMN
	);
	unset( $topics_ids );

	if ( empty( $hashes ) ) {
		$result = "Не получены данные о выбранных раздачах";
		throw new Exception();
	}

	Log::append( "Количество затрагиваемых торрент-клиентов: " . count( $hashes ) . "." );
	
	foreach ( $hashes as $tor_client_id => $hashes ) {

		if ( empty( $hashes ) ) {
			continue;
		}

		if ( empty( $tor_clients[ $tor_client_id ] ) ) {
			Log::append( "В настройках нет данных о торрент-клиенте с идентификатором \"$tor_client_id\"" );
			continue;
		}

		// данные текущего торрент-клиента
		$tor_client = $tor_clients[ $tor_client_id ];

		$client = new $tor_client['cl'] ( $tor_client['ht'], $tor_client['pt'], $tor_client['lg'], $tor_client['pw'], $tor_client['cm'] );

		// проверка доступности торрент-клиента
		if ( ! $client->is_online() ) {
			Log::append ( 'Error: торрент-клиент "' . $tor_client['cm'] . '" в данный момент недоступен.' );
			continue;
		}

		switch ( $action ) {
			
			case 'set_label':
				Log::append( $client->setLabel( $hashes, $label ) );
				break;
			
			case 'stop':
				Log::append( $client->torrentStop( $hashes ) );
				break;
			
			case 'start':
				Log::append( $client->torrentStart( $hashes, $force_start) );
				break;
			
			case 'remove':
				Log::append( $client->torrentRemove( $hashes, $remove_data) );
				// помечаем в базе удаление
				$hashes_remove = array_chunk( $hashes, 500 );
				foreach ( $hashes_remove as $hashes_remove ) {
					$in = str_repeat( '?,', count( $hashes_remove ) ) . '?';
					Db::query_database(
						"UPDATE Topics SET dl = 0, cl = '' WHERE hs IN ($in)",
						$hashes_remove
					);
					unset( $in );
				}
				break;
		}

		Log::append( 'Действие "' . $action . '" для торрент-клиента "' . $tor_client['cm'] . '" выполнено (' . count( $hashes ) . ').' );

		unset( $hashes_remove );
		unset( $tor_client );

	}
	
	$result = 'Действие "' . $action . '" выполнено. За подробностями обратитесь к журналу';

	Log::append( 'Выполнение действия "' . $action . '" завершено.' );

	echo json_encode( array(
		'log' => Log::get(),
		'result' => $result
	));
	
} catch ( Exception $e ) {
	Log::append( $e->getMessage() );
	echo json_encode( array(
		'log' => Log::get(),
		'result' => $result
	));
}

?>
