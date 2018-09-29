<?php

include dirname(__FILE__) . '/../../common.php';
include dirname(__FILE__) . '/../../clients.php';
include dirname(__FILE__) . '/../download.php';

try {

	$result = "";
	
	if ( empty( $_POST['topics_ids'] ) ) {
		$result = "Выберите раздачи";
		throw new Exception();
	}

	if ( empty( $_POST['forums'] ) ) {
		$result = "В настройках не найдены хранимые подразделы";
		throw new Exception();
	}

	if ( empty( $_POST['tor_clients'] ) ) {
		$result = "В настройках не найдены торрент-клиенты";
		throw new Exception();
	}

	if ( isset( $_POST['cfg'] ) ) {
		parse_str( $_POST['cfg'] );
	}
	
	if ( empty( $api_key ) ) {
		$result = "В настройках не указан хранительский ключ API";
		throw new Exception();
	}
	
	if ( empty( $user_id ) ) {
		$result = "В настройках не указан хранительский ключ ID";
		throw new Exception();
	}
	
	$forums = $_POST['forums'];
	$tor_clients = $_POST['tor_clients'];
	$retracker = isset( $retracker ) ? 1 : 0;
	parse_str( $_POST['topics_ids'] );

	Log::append( "Запущен процесс добавления раздач в торрент-клиенты..." );

	Log::append( "Получение идентификаторов раздач с привязкой к подразделу..." );

	$in = implode( ',', $topics_ids );
	$topics_ids = Db::query_database(
		"SELECT ss,id FROM Topics WHERE id IN ($in)",
		array(),
		true,
		PDO::FETCH_GROUP|PDO::FETCH_COLUMN
	);
	unset( $in );
	
	if ( empty( $topics_ids ) ) {
		$result = "Не получены данные о выбранных раздачах";
		throw new Exception();
	}
	
	// прокси
	$activate_forum = isset( $proxy_activate_forum ) ? 1 : 0;
	$activate_api = isset( $proxy_activate_api ) ? 1 : 0;
	$proxy_address = "$proxy_hostname:$proxy_port";
	$proxy_auth = "$proxy_login:$proxy_paswd";
	Proxy::options( $activate_forum, $activate_api, $proxy_type, $proxy_address, $proxy_auth );
	
	$tmpdir = dirname( __FILE__ ) . '/../../tfiles/';
	
	// очищаем временный каталог
	rmdir_recursive( $tmpdir );

	// создаём временный каталог
	if ( ! mkdir_recursive( $tmpdir ) ) {
		$result = "Не удалось создать каталог \"$tmpdir\": неверно указан путь или недостаточно прав";
		throw new Exception();
	}
	
	$tor_clients_ids = array();
	$added_files_total = 0;
	$tor_clients_total = 0;

	foreach ( $topics_ids as $forum_id => $topics_ids ) {
		
		if ( empty( $topics_ids ) ) {
			continue;
		}
		
		if ( ! isset( $forums[ $forum_id ] ) ) {
			Log::append( "В настройках нет данных о подразделе с идентификатором \"$forum_id\"" );
			continue;
		}

		// данные текущего подраздела
		$forum = $forums[ $forum_id ];

		if ( empty( $forum['cl'] ) ) {
			Log::append( "К подразделу \"$forum_id\" не привязан торрент-клиент" );
			continue;
		}
		
		// идентификатор торрент-клиента
		$tor_client_id = $forum['cl'];
		
		if ( empty( $tor_clients[ $tor_client_id ] ) ) {
			Log::append( "В настройках нет данных о торрент-клиенте с идентификатором \"$tor_client_id\"" );
			continue;
		}
		
		// данные текущего торрент-клиента
		$tor_client = $tor_clients[ $tor_client_id ];
		
		// скачивание торрент-файлов
		$download = new Download ( $api_key );
		$download->savedir = $tmpdir;
		$downloaded_files = $download->download_torrent_files( $forum_url, $user_id, $topics_ids, $retracker );
		$downloaded_count = count( $downloaded_files );
		
		if ( empty( $downloaded_files ) ) {
			Log::append( 'Нет скачанных торрент-файлов для добавления их в торрент-клиент "' . $tor_client['cm'] . '"' );
			continue;
		}

		// дополнительный слэш в конце каталога
		if ( ! empty( $forum['fd'] ) && ! in_array( substr( $forum['fd'], -1 ), array( '\\', '/' ) ) ) {
			$forum['fd'] .= strpos( $forum['fd'], '/' ) === false ? '\\' : '/';
		}
		
		$client = new $tor_client['cl'] ( $tor_client['ht'], $tor_client['pt'], $tor_client['lg'], $tor_client['pw'], $tor_client['cm'] );
		
		// проверяем доступность торрент-клиента
		if ( ! $client->is_online() ) {
			Log::append ( 'Error: торрент-клиент "' . $tor_client['cm'] . '" в данный момент недоступен.' );
			continue;
		}
		
		Log::append( "Получение хэшей скачанных раздач..." );
		
		$in = implode( ',', $downloaded_files );
		$added_files = Db::query_database(
			"SELECT id,hs FROM Topics WHERE id IN ($in)",
			array(),
			true,
			PDO::FETCH_GROUP|PDO::FETCH_COLUMN
		);
		unset( $in );
		
		// формирование пути до файла на сервере
		$basename = $_SERVER['SERVER_ADDR'] . str_replace(
			'php/', '', substr(
				$_SERVER['SCRIPT_NAME'], 0, strpos(
					$_SERVER['SCRIPT_NAME'], '/' , 1
				) + 1
			)
		) . basename( $tmpdir );

		$filename = "http://$basename/[webtlo].t%s.torrent";

		array_walk( $added_files, function( &$value, $key, $filename ) {
			$value = array(
				'hash' => $value[0],
				'filename' => sprintf( $filename, $key )
			);
		}, $filename );

		Log::append( 'Добавление раздач в торрент-клиент "' . $tor_client['cm'] . '"...' );

		// добавляем раздачи
		$client->torrentAdd( $added_files, $forum['fd'], $forum['lb'], $forum['sub_folder'] );
		
		// помечаем добавленные раздачи в базе
		$uploaded_files = array_chunk( $downloaded_files, 500 );
		foreach ( $uploaded_files as $uploaded_files ) {
			$in = str_repeat( '?,', count( $uploaded_files ) - 1 ) . '?';
			Db::query_database(
				"UPDATE Topics SET dl = -1, cl = $tor_client_id WHERE id IN ($in)",
				$uploaded_files
			);
		}
		
		Log::append( 'Добавлено раздач в торрент-клиент "' . $tor_client['cm'] . '": ' . $downloaded_count . ' шт.' );

		if ( ! in_array( $tor_client_id, $tor_clients_ids ) ) {
			$tor_clients_ids[] = $tor_client_id;
		}

		$added_files_total += $downloaded_count;

		unset( $downloaded_files );
		unset( $uploaded_files );
		unset( $added_files );
		unset( $tor_client );
		unset( $forum );
		
	}

	$tor_clients_total = count( $tor_clients_ids );

	$result = "Задействовано торрент-клиентов — $tor_clients_total, добавлено раздач всего — $added_files_total шт.";

	Log::append( "Процесс добавления раздач в торрент-клиенты завершён." );
	
	// выводим на экран
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
