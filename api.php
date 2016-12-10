<?php

Db::create();

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
		    CURLOPT_SSL_VERIFYPEER => 0,
		    CURLOPT_SSL_VERIFYHOST => 0
		    //~ CURLOPT_CONNECTTIMEOUT => 60
		));
		curl_setopt_array($this->ch, Proxy::$proxy);
	}
	
	private function request_exec($url){
		$n = 1; // кол-во попыток
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
		
		$this->query_database('INSERT INTO `Forums` ( `id`,`na` ) SELECT * FROM temp.Forums1');
		$this->query_database('DELETE FROM `Forums` WHERE id IN ( SELECT Forums.id FROM Forums LEFT JOIN temp.Forums1 ON Forums.id = temp.Forums1.id WHERE temp.Forums1.id IS NULL )');
		
		return isset($tmp['subsec']) ? $tmp['subsec'] : array();
	}
	
	// список раздач раздела
	public function get_subsection_data($subsections, $status){
		Log::append ( 'Получение списка раздач...' );
		foreach($subsections as $subsection){
			$url = $this->api_url . '/v1/static/pvc/f/' . $subsection['id'] . '?api_key=' . $this->api_key;
			$data = $this->request_exec($url);
			$q = 0;
			foreach($data['result'] as $id => $val){
				// только раздачи с выбранными статусами
				if(isset($val[0]) && in_array($val[0], $status)){
					$ids[] = $id;
					$q++;
				}
			}
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
	
	private function sum_values($arr, $index = "") {
		$sum = 0;
		foreach($arr as $key => $value){
			if(preg_match("/^$index/", $key))
				$sum += $value;
		}
		return $sum;
	}
	
	private function sort_topics($a, $b){
		if($a['avg'] == $b['avg']) return 0;
		return $a['avg'] < $b['avg'] ? -1 : 1;
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
	
	private function insert_topics($ids, &$tc_topics, $subsec, $ud_old, $ud_current, $time, $rule, $avg_seeders) {
		$ids = array_chunk ( $ids, 500 );
		for($i = 0; $i <= $time - 1; $i++){
			$days_fields[] = 'd'.$i;
			$days_fields[] = 'q'.$i;
		}
		$topics = array();
		foreach ( $ids as $value ) {
			// получаем подробные сведения о раздачах
			$data = $this->get_tor_topic_data ( $value );
			if ( empty ( $data ) ) continue;
			
			// если включены "средние сиды" получаем данные за предыдущее обновление сведений
			if ( $avg_seeders ) {
				$in = str_repeat('?,', count($value) - 1) . '?';
				$topics_old = $this->query_database(
					"SELECT id,se,rt,ds FROM `Topics` WHERE id IN ($in)",
					$value, true, PDO::FETCH_GROUP|PDO::FETCH_ASSOC
				);
				$seeders = $this->query_database(
					"SELECT id," . implode(',', $days_fields) . " FROM `Seeders` WHERE id IN ($in)",
					$value, true, PDO::FETCH_GROUP|PDO::FETCH_ASSOC
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
				$avg = $sum_seeders / $sum_updates;
				if ( isset ( $topics_old[$topic_id] ) ) {
					// переносим старые значения
					$days = $topics_old[$topic_id][0]['ds'];
					if ( isset ( $seeders[$topic_id] ) ) $tmp['seeders'][$topic_id] = $seeders[$topic_id][0];
					if ( $ud_current->diff($ud_old)->format('%d' ) > 0 ) {
						$tmp['seeders'][$topic_id]['d'.$days % $time] = $topics_old[$topic_id][0]['se'];
						$tmp['seeders'][$topic_id]['q'.$days % $time] = $topics_old[$topic_id][0]['rt'];
						$tmp['seeders'][$topic_id] += array_fill_keys($days_fields, '');
						$avg = ($this->sum_values($tmp['seeders'][$topic_id], 'd') + $sum_seeders) / ($this->sum_values($tmp['seeders'][$topic_id], 'q') + $sum_updates);
						$days++;
					} else {
						$sum_updates = $topics_old[$topic_id][0]['rt'] + 1;
						$sum_seeders = $topics_old[$topic_id][0]['se'] + $info['seeders'];
						$avg = isset($tmp['seeders'][$topic_id]) ? ($this->sum_values($tmp['seeders'][$topic_id], 'd') + $sum_seeders) / ($this->sum_values($tmp['seeders'][$topic_id], 'q') + $sum_updates) : $sum_seeders / $sum_updates;
					}
				}
				$tmp['topics'][$topic_id]['se'] = $sum_seeders;
				$tmp['topics'][$topic_id]['si'] = $info['size'];
				$tmp['topics'][$topic_id]['st'] = $info['tor_status'];
				$tmp['topics'][$topic_id]['rg'] = $info['reg_time'];
				// "0" - не храню, "1" - храню (раздаю), "-1" - храню (качаю), "-2" - из других подразделов
				$tmp['topics'][$topic_id]['dl'] = !isset($tc_topics[$info['info_hash']]) ? 0 : (!$stored ? -2 : (empty($tc_topics[$info['info_hash']]['status']) ? -1 : 1));
				$tmp['topics'][$topic_id]['rt'] = $sum_updates;
				$tmp['topics'][$topic_id]['ds'] = $days;
				$tmp['topics'][$topic_id]['cl'] = isset($tc_topics[$info['info_hash']]) ? $tc_topics[$info['info_hash']]['client'] : '';
				$tmp['topics'][$topic_id]['avg'] = $avg;
				unset($tc_topics[$info['info_hash']]);
			}
			
			unset($topics_old);
			unset($seeders);
			unset($data);
			
			// формируем массив топиков для вывода на экран
			if ( isset ( $tmp['topics'] ) ) {
				foreach ( $tmp['topics'] as $id => &$topic ) {
					if ( $topic['avg'] <= $rule || $topic['dl'] == -2 )
						$topics[$id] = $topic;
					unset($topic['avg']);
				}
			}
			unset($topic);
			
			// пишем данные о топиках в базу
			if ( isset ( $tmp['topics'] ) ) {
				$select = $this->prepare_insert ( $tmp['topics'] );
				unset($tmp['topics']);
				$this->query_database( "INSERT INTO temp.Topics1 $select" );
				unset($select);
			}
			
			// пишем данные о средних сидах в базу
			if ( isset($tmp['seeders']) && $ud_current->diff($ud_old)->format('%d') > 0 ) {
				$select = $this->prepare_insert ( $tmp['seeders'] );
				unset($tmp['seeders']);
				$this->query_database( "INSERT INTO temp.Seeders1 (id," . implode ( ',', $days_fields ).") $select" );
				unset($select);
			}
			
			unset($tmp);
		}
		
		return $topics;
	}
	
	public function prepare_topics($ids, $tc_topics, $rule, $subsec, $avg_seeders, $time){
		//~ if ( empty ( $ids ) ) return;
		
		// получаем дату предыдущего обновления
		$last_update = $this->query_database(
			"SELECT ud FROM Other", array(), true, PDO::FETCH_COLUMN
		);
		$ud_current = new DateTime('now');
		$ud_old = new DateTime();
		$ud_old->setTimestamp($last_update[0])->setTime(0, 0, 0);
		
		if ( $avg_seeders ) {
			Log::append ( 'Задействован алгоритм поиска среднего значения количества сидов...' );
		}
		
		// раздачи из хранимых подразделов
		Log::append ( 'Получение подробных сведений о раздачах...' );
		$topics = empty ( $ids )
			? array()
			: $this->insert_topics($ids, $tc_topics, $subsec, $ud_old, $ud_current, $time, $rule, $avg_seeders);
		unset($ids);
		
		// раздачи из других подразделов
		if ( !empty ( $tc_topics ) ) {
			Log::append ( 'Поиск раздач из других подразделов...' );
			$ids = $this->get_topic_id ( array_keys ( $tc_topics ) );
			$topics += empty ( $ids )
				? array()
				: $this->insert_topics($ids, $tc_topics, $subsec, $ud_old, $ud_current, $time, $rule, $avg_seeders);
			unset($ids);
		}
		
		$q = $this->query_database("SELECT COUNT() FROM temp.Topics1", array(), true, PDO::FETCH_COLUMN);
		if ( $q[0] > 0 ) {
			Log::append ( 'Запись в базу данных сведений о раздачах...' );
			$this->query_database('INSERT INTO `Topics` SELECT * FROM temp.Topics1');
			$this->query_database('DELETE FROM `Topics` WHERE id IN ( SELECT Topics.id FROM Topics LEFT JOIN temp.Topics1 ON Topics.id = temp.Topics1.id WHERE temp.Topics1.id IS NULL )');
		}
		
		$q = $this->query_database("SELECT COUNT() FROM temp.Seeders1", array(), true, PDO::FETCH_COLUMN);
		if ( $q[0] > 0 ) {
			Log::append ( 'Запись в базу данных сведений о средних сидах...' );
			$this->query_database('INSERT INTO `Seeders` SELECT * FROM temp.Seeders1');
		}
		
		// если были отключены средние сиды
		if ( !$avg_seeders ) {
			$q = $this->query_database("SELECT COUNT() FROM Seeders", array(), true, PDO::FETCH_COLUMN);
			if ( $q[0] > 0 ) {
				$this->query_database('DELETE FROM `Seeders`');
			}
		}
		
		// время последнего обновления
		$this->query_database('UPDATE `Other` SET ud = ? WHERE id = 0', array($ud_current->format('U')));
		
		// сортируем топики по кол-ву сидов по возрастанию
		uasort($topics, array($this, 'sort_topics'));
		
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
		$this->query_database('CREATE TEMP TABLE Seeders1 AS SELECT * FROM Seeders WHERE 0 = 1');
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
		$subsections = $this->query_database("SELECT * FROM `Forums` WHERE `id` IN ($in)", $subsec);
		if(empty($subsections)) throw new Exception();
		return $subsections;
	}
	
	// ... из базы топики
	public function get_topics($seeders, $status, $time){
		Log::append ( 'Получение данных о раздачах...' );
		for($i = 0; $i <= $time - 1; $i++){
			$days_fields['d'][] = 'd'.$i;
			$days_fields['q'][] = 'q'.$i;
		}
		$avg_seeders = '(' . implode ( '+', preg_replace('|^(.*)$|', 'CASE WHEN $1 IS "" OR $1 IS NULL THEN 0 ELSE $1 END', $days_fields['d'] )) . ' + (`se` * 1.) ) /
			(' . implode('+', preg_replace('|^(.*)$|', 'CASE WHEN $1 IS "" OR $1 IS NULL THEN 0 ELSE $1 END', $days_fields['q'] )) . ' + `rt` )';
		$topics = $this->query_database("
			SELECT
				`Topics`.`id`,`ss`,`na`,`hs`,`si`,`st`,`rg`,`dl`,`rt`,`ds`,`ud`,`cl`,
				CASE
					WHEN `ds` IS 0
					THEN (`se` * 1.) / `rt`
					ELSE $avg_seeders
				END as `avg`
			FROM
				`Topics`
				LEFT JOIN
				`Seeders`
					ON `Topics`.`id` = `Seeders`.`id`
				LEFT JOIN `Other`
			WHERE `avg` <= CAST(:se as REAL) AND `dl` = :dl OR `dl` = -2
			ORDER BY `ss`, `avg`
		", array('se' => $seeders, 'dl' => $status));
		if(empty($topics)) throw new Exception();
		return $topics;
	}
	
	// ... из базы подразделы для отчётов
	public function get_forums_details($subsec){
		$subsections = $this->get_forums($subsec);
		foreach($subsections as $id => $subsection){
			$size = $this->query_database(
				"SELECT SUM(`si`) FROM `Topics` WHERE `ss` = :id",
				array('id' => $subsection['id']),
				PDO::FETCH_COLUMN
			);
			$qt = $this->query_database(
				"SELECT COUNT() FROM `Topics` WHERE `ss` = :id",
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
			CURLOPT_SSL_VERIFYPEER => 0,
			CURLOPT_SSL_VERIFYHOST => 0
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
		// проверяем существование указанного каталога
		if (!is_writable($savedir))
		{
			$dl_log = '<span class="errors">Каталог "'.$savedir.'" не существует или недостаточно прав.	Скачивание невозможно.</span>';
			throw new Exception( 'Ошибка при попытке скачать торрент-файлы.' );
		}
		// если задействованы подкаталоги
		if ($savesubdir && !$edit)
		{
			Log::append ( 'Попытка создать подкаталог...' );
			$savedir .= 'tfiles_' . $subsection . '_' . date("(d.m.Y_H.i.s)") . '_' . $rule . substr($savedir, -1);
			$result = (is_writable($savedir) || mkdir($savedir)) ? true : false;
			// создался ли подкаталог
			if (!$result)
			{
				$dl_log = '<span class="errors">Ошибка при создании подкаталога: неверно указан путь или недостаточно прав. Скачивание невозможно.</span>';
				throw new Exception( 'Ошибка при попытке скачать торрент-файлы.' );
			}
		}
		$this->savedir = $savedir;
	}
	
	// идентификатор пользователя
	private function get_user_id($forum_url, $login, $paswd, &$dl_log){
		Log::append ( 'Получение идентификатора пользователя...' );
		$paswd = mb_convert_encoding($paswd, 'Windows-1251', 'UTF-8');
		$login = mb_convert_encoding($login, 'Windows-1251', 'UTF-8');
		curl_setopt_array($this->ch, array(CURLOPT_URL => $forum_url . '/forum/login.php',
			CURLOPT_POSTFIELDS => http_build_query(array(
				'login_username' => "$login", 'login_password' => "$paswd",
				'login' => 'Вход'
			)),
			CURLOPT_HEADER => 1
		));
		$json = curl_exec($this->ch);
		if($json === false) {
			$dl_log = '<span class="errors">Ошибка при подключении к форуму. Обратитесь к журналу за подробностями.</span>';
			throw new Exception( 'CURL ошибка: ' . curl_error($this->ch) );
		}
		preg_match("/.*Set-Cookie: [^-]*-([0-9]*)/", $json, $tmp);
		if(!ctype_digit($tmp[1])){
			preg_match('|<title>(.*)</title>|sei', $json, $title);
			if(!empty($title)) {
				if($title[1] == 'rutracker.org'){
					preg_match('|<h4[^>]*?>(.*)</h4>|sei', $json, $text);
					if(!empty($text))
						Log::append ( 'Error: ' . $title[1] . ' - ' . mb_convert_encoding($text[1], 'UTF-8', 'Windows-1251') . '.' );
				} else {
					Log::append ( 'Error: ' . mb_convert_encoding($title[1], 'UTF-8', 'Windows-1251') . '.' );
				}
			}
			$dl_log = '<span class="errors">Ошибка при авторизации на форуме. Обратитесь к журналу за подробностями.</span>';
			throw new Exception( 'Получен некорректный идентификатор пользователя: "' .	(isset($tmp[1]) ? $tmp[1] : 'null') . '".');
		}
		return $tmp[1];
	}
	
	// скачивание т-.файлов
	public function download_torrent_files($forum_url, $login, $paswd, $topics, $retracker, &$dl_log, $passkey = "", $edit = false){
		$q = 0; // кол-во успешно скачанных торрент-файлов
		//~ $err = 0;
		$starttime = microtime(true);
		$user = $this->get_user_id($forum_url, $login, $paswd, $dl_log);
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
			    'keeper_user_id' => $user,
			    'keeper_api_key' => "$this->api_key",
			    't' => $topic['id'],
			    'add_retracker_url' => $retracker
		    )));
			$torrent_file = $this->savedir . '[webtlo].t' . $topic['id'] . '.torrent';
			//~ $torrent_file = mb_convert_encoding($this->savedir . '[webtlo].t' . $topic['id'] . '.torrent', 'Windows-1251', 'UTF-8');
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
