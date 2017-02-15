<?php

class Webtlo {
	
	public $limit;
	
	public static $db;
	
	protected $ch;
	protected $api_key;
	protected $api_url;
	
	public function __construct($api_url, $api_key = ""){
		Log::append ( 'Получение данных с ' . $api_url . '...' );
		$this->api_key = $api_key;
		$this->api_url = $api_url;
		$this->make_database();
		$this->init_curl();
		$this->get_limit();
	}
	
	private function init_curl(){
		$this->ch = curl_init();
		curl_setopt_array($this->ch, array(
		    CURLOPT_RETURNTRANSFER => 1,
		    CURLOPT_ENCODING => "gzip",
		    CURLOPT_SSL_VERIFYPEER => false,
		    CURLOPT_SSL_VERIFYHOST => 2,
		    CURLOPT_USERAGENT => "Mozilla/5.0 (Windows NT 6.1) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/55.0.2883.87 Safari/537.36"
		    //~ CURLOPT_CONNECTTIMEOUT => 60
		));
		curl_setopt_array($this->ch, Proxy::$proxy);
	}
	
	private function request_exec($url){
		$n = 1; // кол-во попыток
		$data = array();
		curl_setopt($this->ch, CURLOPT_URL, $url);
		while(true){
			$json = curl_exec($this->ch);
			if($json === false) {
				throw new Exception( 'CURL ошибка: ' . curl_error($this->ch) );
			}
			$data = json_decode($json, true);
			if(isset($data['error'])){
				if($data['error']['code'] == '503' && $n <= 3){
					Log::append ( 'Повторная попытка ' . $n . '/3 получить данные.' );
					sleep(20);
					$n++;
					continue;
				}
				if( $data['error']['code'] == '404' ) break;
				throw new Exception( 'API ошибка: ' . $data['error']['text'] );
			}
			break;
		}
		return $data;
	}
	
	// ограничение на запрашиваемые данные
	private function get_limit(){
		$url = $this->api_url . '/v1/get_limit?api_key=' . $this->api_key;
		$data = $this->request_exec($url);
		$this->limit = isset ( $data['result']['limit'] ) ? $data['result']['limit'] : 100;
	}
	
	// статусы раздач
	public function get_tor_status_titles($tor_status){
		if(!is_array($tor_status))
			throw new Exception( 'В настройках не выбран статус раздач на трекере.' );
		$url = $this->api_url . '/v1/get_tor_status_titles?api_key=' . $this->api_key;
		$data = $this->request_exec($url);
		$status = array();
		foreach($data['result'] as $key => $value){
			if(in_array($value, $tor_status))
				$status[] = $key;
		}
		return $status;
	}
	
	// дерево разделов
	public function get_cat_forum_tree ( $subsec = array() ) {
		Log::append ( 'Получение дерева разделов...' );
		$url = $this->api_url . '/v1/static/cat_forum_tree?api_key=' . $this->api_key;
		$data = $this->request_exec($url);
		foreach($data['result']['c'] as $cat_id => $cat_title){
		    foreach($data['result']['tree'][$cat_id] as $forum_id => $subforum){
				// разделы
				$forum_title = $cat_title.' » '.$data['result']['f'][$forum_id];
				$tmp['subsections'][$forum_id]['id'] = $forum_id;
	            $tmp['subsections'][$forum_id]['na'] = $forum_title;
				if(in_array($forum_id, $subsec)){
					$tmp['subsec'][$forum_id]['id'] = $forum_id;
		            $tmp['subsec'][$forum_id]['na'] = $forum_title;
				}
		        // подразделы
		        foreach($subforum as $subforum_id){
					$subforum_title = $cat_title.' » '.$data['result']['f'][$forum_id].' » '.$data['result']['f'][$subforum_id];
		            $tmp['subsections'][$subforum_id]['id'] = $subforum_id;
		            $tmp['subsections'][$subforum_id]['na'] = $subforum_title;
		            if(in_array($subforum_id, $subsec)){
			            $tmp['subsec'][$subforum_id]['id'] = $subforum_id;
			            $tmp['subsec'][$subforum_id]['na'] = $subforum_title;
					}
		        }
		    }
		}
		
		$tmp['subsections'] = array_chunk ( $tmp['subsections'], 500 );
		// отправляем в базу данных
		foreach ( $tmp['subsections'] as $value ) {
			$select = $this->prepare_insert ( $value );
			$this->query_database ( "INSERT INTO temp.Forums1 (id,na) $select" );
		}
		
		$this->query_database('INSERT INTO Forums ( id,na ) SELECT id,na FROM temp.Forums1');
		$this->query_database('DELETE FROM Forums WHERE id IN ( SELECT Forums.id FROM Forums LEFT JOIN temp.Forums1 ON Forums.id = temp.Forums1.id WHERE temp.Forums1.id IS NULL )');
		
		return isset($tmp['subsec']) ? $tmp['subsec'] : array();
	}
	
	// список раздач раздела
	public function get_subsection_data( $subsections, $status = array(2,8), $get = 'ids' ) {
		//~ Log::append ( 'Получение списка раздач...' );
		$ids = array();
		foreach($subsections as $subsection){
			$url = $this->api_url . '/v1/static/pvc/f/' . $subsection['id'] . '?api_key=' . $this->api_key;
			$data = $this->request_exec($url);
			$q = 0;
			if( empty( $data['result'] ) ) continue;
			foreach( $data['result'] as $id => $val ) {
				// только раздачи с выбранными статусами
				if( !empty( $val ) && in_array( $val[0], $status ) ) {
					if( count( $val ) < 3 ) throw new Exception( "Error: Недостаточно элементов в ответе." );
					switch( $get ) {
						case 'ids':
							$ids[] = $id;
							break;
						case 'status':
							$ids[$id] = $val[0];
							break;
						case 'seeders':
							$ids[$id] = $val[1];
							break;
						case 'all':
							$ids[$id] = implode( ',', $val ) . ",${subsection['id']}";
							break;
					}
					$q++;
				}
			}
			Db::query_database( "UPDATE Forums SET qt = $q, si = ${data['total_size_bytes']} WHERE id = ${subsection['id']}" );
			Log::append ( 'Список раздач раздела № ' . $subsection['id'] . ' получен (' . $q . ' шт.).' );
		}
		return $ids;
	}
	
	// получить значения пиров по id раздачи
	public function get_peer_stats( $ids ) {
		if( empty( $ids ) ) return;
		$ids = array_chunk( $ids, $this->limit, false );
		foreach( $ids as $ids ) {
			$value = implode( ',', $ids );
			$url = $this->api_url . '/v1/get_peer_stats?by=topic_id&api_key=' . $this->api_key . '&val=' . $value;
			$data = $this->request_exec( $url );
			foreach( $data['result'] as $topic_id => $topic ) {
				if( !empty( $topic ) ) {
					$topics[$topic_id] = array_combine( array( 'seeders', 'leechers', 'seeder_last_seen' ), $topic );
				}
			}
		}
		return $topics;
	}
	
	// получить id раздачи по hash
	public function get_topic_id($hashes){
		if(empty($hashes)) return;
		$hashes = array_chunk($hashes, $this->limit, false);
		foreach($hashes as $hashes){
			$value = implode(',', $hashes);
			$url = $this->api_url . '/v1/get_topic_id?by=hash&api_key=' . $this->api_key . '&val=' . $value;
			$data = $this->request_exec($url);
			foreach($data['result'] as $hash => $id){
				if(!empty($id)) $ids[$hash] = $id;
			}
		}
		return $ids;
	}
	
	// сведения о каждой раздаче
	public function get_tor_topic_data($ids){
		if(empty($ids)) return;
		$ids = array_chunk($ids, $this->limit, false);
		foreach($ids as &$value){
			$value = implode(',', $value);
			$url = $this->api_url . '/v1/get_tor_topic_data?by=topic_id&api_key=' . $this->api_key . '&val=' . $value;
			$data = $this->request_exec($url);
			foreach($data['result'] as $topic_id => $info){
				if(is_array($info)) $topics[$topic_id] = $info;
			}
		}
		return $topics;
	}
	
	private function prepare_insert($data) {
		foreach ( $data as $id => &$value ) {
			$value = array_map ( function ($e) {
				return is_numeric($e) ? $e : Webtlo::$db->quote($e);
			}, $value);
			$value = (empty($value['id']) ? $id . ',' : '') . implode (',', $value);
		}
		$str = 'SELECT ' . implode (' UNION ALL SELECT ', $data);
		return $str;
	}
	
	private function insert_topics($ids, &$tc_topics, $subsec, $last, $current, $time, $rule, $avg_seeders) {
		$ids = array_chunk ( $ids, 500 );
		for($i = 0; $i <= $time - 1; $i++){
			$avg['sum_se'][] = "CASE WHEN d$i IS \"\" OR d$i IS NULL THEN 0 ELSE d$i END";
			$avg['sum_qt'][] = "CASE WHEN q$i IS \"\" OR q$i IS NULL THEN 0 ELSE q$i END";
			$avg['q'][] = "CASE WHEN q$i IS \"\" OR q$i IS NULL THEN 0 ELSE 1 END";
		}
		$sum_se = implode( '+', $avg['sum_se'] );
		$sum_qt = implode( '+', $avg['sum_qt'] );
		$q = implode( '+', $avg['q'] );
		$topics = array();
		foreach ( $ids as $value ) {
			// получаем подробные сведения о раздачах
			$data = $this->get_tor_topic_data ( $value );
			if ( empty ( $data ) ) continue;
			
			// если включены "средние сиды" получаем данные за предыдущее обновление сведений
			if ( $avg_seeders ) {
				$in = str_repeat('?,', count($value) - 1) . '?';
				$topics_old = $this->query_database(
					"SELECT Topics.id,se,rg,qt,ds,$sum_se as sum_se,$sum_qt as sum_qt,$q as q FROM Topics LEFT JOIN Seeders ON Topics.id = Seeders.id WHERE Topics.id IN ($in)",
					$value, true, PDO::FETCH_ASSOC|PDO::FETCH_UNIQUE
				);
			}
			
			// разбираем полученные с api данные
			foreach ( $data as $topic_id => $info ) {
				$stored = in_array($info['forum_id'], $subsec);
				// для отправки дальше
				$tmp['topics'][$topic_id]['id'] = $topic_id;
				$tmp['topics'][$topic_id]['ss'] = $stored ? $info['forum_id'] : 0;
				$tmp['topics'][$topic_id]['na'] = $info['topic_title'];
				$tmp['topics'][$topic_id]['hs'] = $info['info_hash'];
				// средние сиды
				$days = 0;
				$sum_updates = 1;
				$sum_seeders = $info['seeders'];
				if ( isset ( $topics_old[$topic_id] ) ) {
					if( empty( $topics_old[$topic_id]['rg'] ) || $topics_old[$topic_id]['rg'] == $info['reg_time'] ) {
						// переносим старые значения
						$days = $topics_old[$topic_id]['ds'];
						// по прошествии дня
						if ( !empty( $last ) && $current->diff($last)->format('%d' ) > 0 ) {
							$days++;
						} else {
							$sum_updates += $topics_old[$topic_id]['qt'];
							$sum_seeders += $topics_old[$topic_id]['se'];
						}
					} else {
						$topics_del[] = $topic_id;
					}
				}
				$tmp['topics'][$topic_id]['se'] = $sum_seeders;
				$tmp['topics'][$topic_id]['si'] = $info['size'];
				$tmp['topics'][$topic_id]['st'] = $info['tor_status'];
				$tmp['topics'][$topic_id]['rg'] = $info['reg_time'];
				// "0" - не храню, "1" - храню (раздаю), "-1" - храню (качаю), "-2" - из других подразделов
				$tmp['topics'][$topic_id]['dl'] = !isset($tc_topics[$info['info_hash']]) ? 0 : (!$stored ? -2 : (empty($tc_topics[$info['info_hash']]['status']) ? -1 : 1));
				$tmp['topics'][$topic_id]['qt'] = $sum_updates;
				$tmp['topics'][$topic_id]['ds'] = $days;
				$tmp['topics'][$topic_id]['cl'] = isset($tc_topics[$info['info_hash']]) ? $tc_topics[$info['info_hash']]['client'] : '';
				$tmp['topics'][$topic_id]['avg'] = !empty( $topics_old[$topic_id] )
					? ($topics_old[$topic_id]['sum_se'] + $sum_seeders) / ($topics_old[$topic_id]['sum_qt'] + $sum_updates)
					: $sum_seeders / $sum_updates;
				unset($tc_topics[$info['info_hash']]);
			}
			unset($data);
			
			// формируем массив топиков для вывода на экран
			if ( isset ( $tmp['topics'] ) ) {
				foreach ( $tmp['topics'] as $topic_id => &$topic ) {
					if ( $topic['avg'] <= $rule || $topic['dl'] == -2 ) {
						$topics[$topic_id] = $topic;
						$topics[$topic_id]['ds'] = isset( $topics_old[$topic_id]['q'] )
							? $topics_old[$topic_id]['q']
							: 0;
					}
					unset($topic['avg']);
				}
			}
			unset($topics_old);
			unset($topic);
			
			// пишем данные о топиках в базу
			if ( isset ( $tmp['topics'] ) ) {
				$select = $this->prepare_insert ( $tmp['topics'] );
				unset($tmp['topics']);
				$this->query_database( "INSERT INTO temp.Topics1 $select" );
				unset($select);
			}
			
			unset($tmp);
		}
		
		// удаляем перерегистрированные раздачи
		if( !empty( $topics_del ) ) {
			$in = implode( ',', $topics_del );
			$this->query_database( "DELETE FROM Topics WHERE id IN ($in)" );
		}
		
		return $topics;
	}
	
	public function prepare_topics($ids, $tc_topics, $rule, $subsec, $avg_seeders, $time){
		
		// получаем дату предыдущего обновления
		$ud = $this->query_database(
			"SELECT ud FROM Other", array(), true, PDO::FETCH_COLUMN
		);
		$current = new DateTime('now');
		$last = new DateTime();
		$last->setTimestamp($ud[0])->setTime(0, 0, 0);
		
		if ( $avg_seeders ) {
			Log::append ( 'Задействован алгоритм поиска среднего значения количества сидов...' );
		}
		
		// раздачи из хранимых подразделов
		Log::append ( 'Получение подробных сведений о раздачах...' );
		$topics = empty ( $ids )
			? array()
			: $this->insert_topics($ids, $tc_topics, $subsec, $last, $current, $time, $rule, $avg_seeders);
		unset($ids);
		
		// раздачи из других подразделов
		if ( !empty ( $tc_topics ) ) {
			Log::append ( 'Поиск раздач из других подразделов...' );
			$ids = $this->get_topic_id ( array_keys ( $tc_topics ) );
			$topics += empty ( $ids )
				? array()
				: $this->insert_topics($ids, $tc_topics, $subsec, $last, $current, $time, $rule, $avg_seeders);
			unset($ids);
		}
		
		$q = $this->query_database("SELECT COUNT() FROM temp.Topics1", array(), true, PDO::FETCH_COLUMN);
		if ( $q[0] > 0 ) {
			Log::append ( 'Запись в базу данных сведений о раздачах...' );
			$in = str_repeat( '?,', count( $subsec ) - 1 ) . '?';
			$this->query_database("INSERT INTO Topics SELECT * FROM temp.Topics1");
			$this->query_database("DELETE FROM Topics WHERE id IN ( SELECT Topics.id FROM Topics LEFT JOIN temp.Topics1 ON Topics.id = temp.Topics1.id WHERE temp.Topics1.id IS NULL AND ( Topics.ss IN ($in) OR Topics.dl = -2 ) )", $subsec);
		}
		
		// время последнего обновления
		$this->query_database('UPDATE `Other` SET ud = ? WHERE id = 0', array($current->format('U')));
		
		// сортируем топики по кол-ву сидов по возрастанию
		uasort( $topics, function( $a, $b ) {
			return $a['avg'] != $b['avg']
				? $a['avg'] < $b['avg']
					? -1 : 1
				: 0;
		});
		
		return $topics;
	}
	
	private function query_database($sql, $param = array(), $fetch = false, $pdo = PDO::FETCH_ASSOC){
		$sth = Webtlo::$db->prepare($sql);
		if(Webtlo::$db->errorCode() != '0000') {
			$db_error = Webtlo::$db->errorInfo();
			throw new Exception( 'SQL ошибка: ' . $db_error[2] );
		}
		$sth->execute($param);
		return $fetch ? $sth->fetchAll($pdo) : true;
	}
	
	private function make_database(){
		
		Webtlo::$db = new PDO('sqlite:' . dirname(__FILE__) . '/webtlo.db');
		
		// временные таблицы
		$this->query_database('CREATE TEMP TABLE Forums1 AS SELECT * FROM Forums WHERE 0 = 1');
		$this->query_database('CREATE TEMP TABLE Topics1 AS SELECT * FROM Topics WHERE 0 = 1');
		$this->query_database('CREATE TEMP TABLE Keepers1 AS SELECT * FROM Keepers WHERE 0 = 1');
		
	}
	
	public function __destruct(){
		curl_close($this->ch);
	}
}

class Database {
	
	public static $db;
	
	public function __construct(){
		Database::$db = new PDO('sqlite:' . dirname(__FILE__) . '/webtlo.db');
	}
	
	private function query_database($sql, $param = array(), $pdo = PDO::FETCH_ASSOC, $fetch = true){
		$sth = Database::$db->prepare($sql);
		if(Database::$db->errorCode() != '0000') {
			$db_error = Database::$db->errorInfo();
			throw new Exception( 'SQL ошибка: ' . $db_error[2] );
		}
		$sth->execute($param);
		return $fetch ? $sth->fetchAll($pdo) : true;
	}
	
	// ... из базы подразделы для списка раздач на главной
	public function get_forums($subsec){
		Log::append ( 'Получение данных о подразделах...' );
		if(!is_array($subsec)) $subsec = explode(',', $subsec);
		$in = str_repeat('?,', count($subsec) - 1) . '?';
		$subsections = $this->query_database("SELECT * FROM `Forums` WHERE `id` IN ($in) ORDER BY na", $subsec);
		if(empty($subsections)) throw new Exception();
		return $subsections;
	}
	
	// ... из базы топики
	public function get_topics( $subsec, $status, $avg_seeders, $period_seeders, $sort = 'avg' ) {
		Log::append ( 'Получение данных о раздачах...' );
		if( $avg_seeders ) {
			for($i = 0; $i < $period_seeders; $i++){
				$avg['sum_se'][] = "CASE WHEN d$i IS \"\" OR d$i IS NULL THEN 0 ELSE d$i END";
				$avg['sum_qt'][] = "CASE WHEN q$i IS \"\" OR q$i IS NULL THEN 0 ELSE q$i END";
				$avg['qt'][] = "CASE WHEN q$i IS \"\" OR q$i IS NULL THEN 0 ELSE 1 END";
			}
			$qt = implode( '+', $avg['qt'] );
			$sum_qt = implode( '+', $avg['sum_qt'] );
			$sum_se = implode( '+', $avg['sum_se'] );
			$avg = "CASE WHEN $qt IS 0 THEN (se * 1.) / qt ELSE ( se * 1. + $sum_se) / ( qt + $sum_qt) END";
		} else {
			$qt = "ds";
			$avg = "se";
		}
		if( is_array( $subsec ) ) $subsec = implode( ',', $subsec );
		$topics = $this->query_database(
			"SELECT Topics.id,ss,na,hs,si,st,rg,dl,cl,$qt as ds,$avg as avg ".
			"FROM Topics LEFT JOIN Seeders on Seeders.id = Topics.id ".
			"WHERE ss IN($subsec) AND dl = :dl OR dl = -2",
			array( 'dl' => $status ), PDO::FETCH_ASSOC, true
		);
		// сортировка раздач
		uasort( $topics, function( $a, $b ) use ( $sort ) {
			return $a[$sort] != $b[$sort]
				? $a[$sort] < $b[$sort]
					? -1 : 1
				: 0;
		});
		if( empty( $topics ) ) throw new Exception();
		return $topics;
	}
	
	// ... из базы подразделы для отчётов
	public function get_forums_details($subsec){
		$subsections = $this->get_forums($subsec);
		foreach($subsections as $id => $subsection){
			$size = $this->query_database(
				"SELECT SUM(`si`) FROM `Topics` WHERE `ss` = :id AND st IN (2,8)",
				array('id' => $subsection['id']),
				PDO::FETCH_COLUMN
			);
			$qt = $this->query_database(
				"SELECT COUNT() FROM `Topics` WHERE `ss` = :id AND st IN (2,8)",
				array('id' => $subsection['id']),
				PDO::FETCH_COLUMN
			);
			$subsections[$id]['si'] = $size[0];
			$subsections[$id]['qt'] = $qt[0];
		}
		return $subsections;
	}
	
	// ... из базы хранители
	public function get_keepers(){
		$keepers = $this->query_database(
			"SELECT topic_id,nick FROM `Keepers`",
			array(), PDO::FETCH_COLUMN|PDO::FETCH_GROUP
		);
		return $keepers;
	}
	
	private function prepare_insert ( $data ) {
		foreach ( $data as $id => &$value ) {
			$value = array_map ( function ($e) {
				return is_numeric($e) ? $e : Database::$db->quote($e);
			}, $value);
			$value = (empty($value['id']) ? $id . ',' : '') . implode (',', $value);
		}
		$str = 'SELECT ' . implode (' UNION ALL SELECT ', $data);
		return $str;
	}
	
	// в базу хранители
	public function set_keepers ( $keepers ) {
		Log::append ( 'Запись в базу данных списка раздач других хранителей...' );
		$insert = array();
		$this->query_database("CREATE TEMP TABLE Keepers1 AS SELECT * FROM Keepers WHERE 0 = 1");
		foreach ( $keepers as $topic_id => $nick ) {
			foreach ( $nick as $nick ) {
				$insert[] = array ( 'id' => $topic_id, 'nick' => $nick );
			}
		}
		$insert = array_chunk ( $insert, 500 );
		foreach ( $insert as $value ) {
			$select = $this->prepare_insert ( $value );
			$this->query_database( "INSERT INTO temp.Keepers1 (topic_id,nick) $select" );
		}
		$this->query_database("INSERT INTO `Keepers` SELECT * FROM temp.Keepers1");
		$this->query_database("DELETE FROM `Keepers` WHERE id NOT IN (SELECT Keepers.id FROM temp.Keepers1 LEFT JOIN Keepers ON temp.Keepers1.topic_id  = Keepers.topic_id AND temp.Keepers1.nick = Keepers.nick WHERE Keepers.id IS NOT NULL)");
	}
	
}

class Download {

	protected $ch;
	protected $api_key;
	
	private $savedir;
	
	public function __construct($api_key, $savedir = ""){
		$this->api_key = $api_key;
		$this->savedir = $savedir;
		$this->init_curl();
	}
	
	private function init_curl(){
		$this->ch = curl_init();
		curl_setopt_array($this->ch, array(
			CURLOPT_RETURNTRANSFER => 1,
			CURLOPT_SSL_VERIFYPEER => false,
			CURLOPT_SSL_VERIFYHOST => 2,
			CURLOPT_USERAGENT => "Mozilla/5.0 (Windows NT 6.1) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/55.0.2883.87 Safari/537.36"
			//~ CURLOPT_CONNECTTIMEOUT => 60
		));
		curl_setopt_array($this->ch, Proxy::$proxy);
	}
	
	// подготовка каталогов
	public function create_directories($savedir, $savesubdir, $subsection, $rule, $dir_torrents, $edit, &$dl_log){
		$savedir = $edit ? $dir_torrents : $savedir;
		if (empty($savedir))
		{
			$dl_log = '<span class="errors">Не указан каталог для скачивания, проверьте настройки. Скачивание невозможно.</span>';
			throw new Exception( 'Ошибка при попытке скачать торрент-файлы.' );
		}
		$winsavedir = mb_convert_encoding( $savedir, 'Windows-1251', 'UTF-8' );
		// проверяем существование указанного каталога
		if (!is_writable($savedir) && !is_writable($winsavedir))
		{
			$dl_log = '<span class="errors">Каталог "'.$savedir.'" не существует или недостаточно прав.	Скачивание невозможно.</span>';
			throw new Exception( 'Ошибка при попытке скачать торрент-файлы.' );
		}
		// если задействованы подкаталоги
		if ($savesubdir && !$edit)
		{
			Log::append ( 'Попытка создать подкаталог...' );
			$savedir .= 'tfiles_' . $subsection . '_' . date("(d.m.Y_H.i.s)") . '_' . $rule . substr($savedir, -1);
			$winsavedir = mb_convert_encoding( $savedir, 'Windows-1251', 'UTF-8' );
			$result = PHP_OS == 'WINNT'
				? (is_writable($winsavedir) || mkdir($winsavedir))
				: (is_writable($savedir) || mkdir($savedir));
			// создался ли подкаталог
			if (!$result)
			{
				$dl_log = '<span class="errors">Ошибка при создании подкаталога: неверно указан путь или недостаточно прав. Скачивание невозможно.</span>';
				throw new Exception( 'Ошибка при попытке скачать торрент-файлы.' );
			}
		}
		$this->savedir = $savedir;
	}
	
	// скачивание т-.файлов
	public function download_torrent_files($forum_url, $user_id, $topics, $retracker, &$dl_log, $passkey = "", $edit = false){
		if( empty($user_id) ) {
			$dl_log = "Необходимо указать в настройках авторизации \"Ключ id\".";
			throw new Exception();
		}
		$q = 0; // кол-во успешно скачанных торрент-файлов
		//~ $err = 0;
		$starttime = microtime(true);
		Log::append ( $edit
			? 'Выполняется скачивание торрент-файлов с заменой Passkey...'
			: 'Выполняется скачивание торрент-файлов...'
		);
		curl_setopt_array($this->ch, array(
		    CURLOPT_URL => $forum_url . '/forum/dl.php',
		    CURLOPT_HEADER => 0
		));
		//~ $topics = array_chunk($topics, 30, true);
		foreach($topics as $topic){
		    curl_setopt($this->ch, CURLOPT_POSTFIELDS, http_build_query(array(
			    'keeper_user_id' => $user_id,
			    'keeper_api_key' => "$this->api_key",
			    't' => $topic['id'],
			    'add_retracker_url' => $retracker
		    )));
			$torrent_file = $this->savedir . '[webtlo].t' . $topic['id'] . '.torrent';
			if( PHP_OS == 'WINNT' ) $torrent_file = mb_convert_encoding( $torrent_file, 'Windows-1251', 'UTF-8' );
			$n = 1; // кол-во попыток
			while(true) {
				
				// выходим после 3-х попыток
				if($n >= 4) {
					Log::append ( 'Не удалось скачать торрент-файл для ' . $topic['id'] . '.' );
					break;
				}
				
				$json = curl_exec($this->ch);
				
				if($json === false) {
					Log::append ( 'CURL ошибка: ' . curl_error($this->ch) . ' (раздача ' . $topic['id'] . ').' );
					break;
				}
				
				// проверка "торрент не зарегистрирован" и т.д.
				preg_match('|<center.*>(.*)</center>|sei', mb_convert_encoding($json, 'UTF-8', 'Windows-1251'), $forbidden);
				if(!empty($forbidden)) {
					preg_match('|<title>(.*)</title>|sei', mb_convert_encoding($json, 'UTF-8', 'Windows-1251'), $title);
					Log::append ( 'Error: ' . (empty($title) ? $forbidden[1] : $title[1]) . ' (' . $topic['id'] . ').' );
					break;
				}
				
				// проверка "ошибка 503" и т.д.
				preg_match('|<title>(.*)</title>|sei', mb_convert_encoding($json, 'UTF-8', 'Windows-1251'), $error);
				if(!empty($error)) {
					Log::append ( 'Error: ' . $error[1] . ' (' . $topic['id'] . ').' );
					Log::append ( 'Повторная попытка ' . $n . '/3 скачать торрент-файл (' . $topic['id'] . ').' );
					sleep(40);
					$n++;
					continue;
				}
				
				// меняем passkey
				if($edit){
					include_once dirname(__FILE__) . '/php/torrenteditor.php';
					$torrent = new Torrent();
					if($torrent->load($json) == false)
					{
						Log::append ( $torrent->error . '(' . $topic_id . ').' );
						break;
					}
					$trackers = $torrent->getTrackers();
					foreach($trackers as &$tracker){
						$tracker = preg_replace('/(?<==)\w+$/', $passkey, $tracker);
					}
					unset($tracker);
					$torrent->setTrackers($trackers);
					$content = $torrent->bencode();
					if(file_put_contents($torrent_file, $content) === false)
						Log::append ( 'Произошла ошибка при сохранении файла: '.$torrent_file.'.' );
					else $q++;
					break;
				}
				
				// сохраняем в файл
				if(!file_put_contents($torrent_file, $json) === false) {
					$success[$q]['id'] = $topic['id'];
					$success[$q]['hash'] = $topic['hash'];
					$success[$q]['filename'] = 'http://' . $_SERVER['SERVER_ADDR'] . '/' . basename($this->savedir) . '/[webtlo].t'.$topic['id'].'.torrent';
					$q++;
					//~ Log::append ( 'Успешно сохранён торрент-файл для ' . $topic['id'] . '.' );
				}
				
				break;
			}
		}
		$endtime1 = microtime(true);
		$dl_log = 'Сохранено в каталоге "' . $this->savedir . '": <span class="rp-header">' . $q . '</span> шт. (за ' . round($endtime1-$starttime, 1). ' с).'; //, ошибок: ' . $err . '.';
		Log::append ( 'Скачивание торрент-файлов завершено.' );
		return isset($success) ? $success : null;
	}
	
	public function __destruct(){
		curl_close($this->ch);
	}
	
}
	
?>
