<?php

class Api {
	
	public $limit;
	
	protected $ch;
	protected $api_key;
	protected $api_url;
	protected $request_count = 0;
	
	public function __construct($api_url, $api_key = ""){
		Log::append ( 'Получение данных с ' . $api_url . '...' );
		$this->api_key = $api_key;
		$this->api_url = $api_url;
		$this->init_curl();
		$this->get_limit();
	}
	
	private function init_curl(){
		$this->ch = curl_init();
		curl_setopt_array( $this->ch, array(
			CURLOPT_RETURNTRANSFER => 1,
			CURLOPT_ENCODING => "gzip",
			CURLOPT_SSL_VERIFYPEER => false,
			CURLOPT_SSL_VERIFYHOST => 2,
			CURLOPT_USERAGENT => "Mozilla/5.0 (Windows NT 6.1) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/55.0.2883.87 Safari/537.36",
			CURLOPT_CONNECTTIMEOUT => 20,
			CURLOPT_TIMEOUT => 20
		));
		curl_setopt_array( $this->ch, Proxy::$proxy['api_url'] );
	}
	
	private function request_exec( $url ) {
		
		// таймаут запросов
		if ( $this->request_count == 3 ) {
			sleep( 1 );
			$this->request_count = 0;
		}
		$this->request_count++;
		
		$n = 1; // номер попытки
		$try_number = 1; // номер попытки
		$try = 3; // кол-во попыток
		$data = array();
		curl_setopt( $this->ch, CURLOPT_URL, $url );
		while ( true ) {
			$json = curl_exec( $this->ch );
			if ( $json === false ) {
				$http_code = curl_getinfo( $this->ch, CURLINFO_HTTP_CODE );
				if ( $http_code < 300 && $try_number <= $try ) {
					Log::append( "Повторная попытка $try_number/$try получить данные." );
					sleep( 5 );
					$try_number++;
					continue;
				}
				throw new Exception( 'CURL ошибка: ' . curl_error( $this->ch ) . " [$http_code]" );
			}
			$data = json_decode( $json, true );
			if ( isset( $data['error'] ) ) {
				if ( $data['error']['code'] == '503' && $n <= $try ) {
					Log::append ( "Повторная попытка $n/$try получить данные." );
					sleep( 20 );
					$n++;
					continue;
				}
				if ( $data['error']['code'] == '404' ) {
					break;
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
	public function get_cat_forum_tree () {
		Log::append ( 'Получение дерева разделов...' );
		$url = $this->api_url . '/v1/static/cat_forum_tree?api_key=' . $this->api_key;
		$data = $this->request_exec($url);
		foreach ( $data['result']['c'] as $cat_id => $cat_title ) {
		    foreach ( $data['result']['tree'][$cat_id] as $forum_id => $subforum ) {
				// разделы
				$forum_title = $cat_title.' » '.$data['result']['f'][$forum_id];
				$forums[ $forum_id ] = array(
					'id' => $forum_id,
					'na' => $forum_title
				);
				// подразделы
				foreach ( $subforum as $subforum_id ) {
					$subforum_title = $cat_title.' » '.$data['result']['f'][$forum_id].' » '.$data['result']['f'][$subforum_id];
					$forums[ $subforum_id ] = array(
						'id' => $subforum_id,
						'na' => $subforum_title
					);
				}
			}
		}
		
		$forums = array_chunk ( $forums, 500 );
		// отправляем в базу данных
		Db::query_database('CREATE TEMP TABLE Forums1 AS SELECT * FROM Forums WHERE 0 = 1');
		foreach ( $forums as $value ) {
			$select = Db::combine_set( $value );
			Db::query_database ( "INSERT INTO temp.Forums1 (id,na) $select" );
		}
		Db::query_database('INSERT INTO Forums ( id,na ) SELECT id,na FROM temp.Forums1');
		Db::query_database('DELETE FROM Forums WHERE id IN ( SELECT Forums.id FROM Forums LEFT JOIN temp.Forums1 ON Forums.id = temp.Forums1.id WHERE temp.Forums1.id IS NULL )');
	}
	
	// список раздач раздела
	public function get_subsection_data( $forum_ids, $get = 'ids' ) {
		//~ Log::append ( 'Получение списка раздач...' );
		$ids = array();
		$status = array( 0,2,3,8,10 );
		foreach( $forum_ids as $forum_id ) {
			$url = $this->api_url . '/v1/static/pvc/f/' . $forum_id . '?api_key=' . $this->api_key;
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
							$ids[$id] = implode( ',', $val ) . ",$forum_id";
							break;
					}
					$q++;
				}
			}
			Db::query_database( "UPDATE Forums SET qt = $q, si = ${data['total_size_bytes']} WHERE id = $forum_id" );
			Log::append ( 'Список раздач раздела № ' . $forum_id . ' получен (' . $q . ' шт.).' );
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
		$topics = array();
		if ( empty( $ids ) ) {
			return $topics;
		}
		$ids = array_chunk($ids, $this->limit, false);
		foreach($ids as &$value){
			$value = implode(',', $value);
			$url = $this->api_url . '/v1/get_tor_topic_data?by=topic_id&api_key=' . $this->api_key . '&val=' . $value;
			$data = $this->request_exec($url);
			if ( empty( $data['result'] ) ) {
				continue;
			}
			foreach($data['result'] as $topic_id => $info){
				if(is_array($info)) $topics[$topic_id] = $info;
			}
		}
		return $topics;
	}
	
	private function insert_topics($ids, &$tc_topics, $subsec, $last, $current, $avg_seeders) {
		$ids = array_chunk ( $ids, 500 );
		$topics = array();
		foreach ( $ids as $value ) {
			// получаем подробные сведения о раздачах
			$data = $this->get_tor_topic_data ( $value );
			if ( empty ( $data ) ) continue;
			
			// если включены "средние сиды" получаем данные за предыдущее обновление сведений
			if ( $avg_seeders ) {
				$in = str_repeat('?,', count($value) - 1) . '?';
				$topics_old = Db::query_database(
					"SELECT Topics.id,se,rg,qt,ds FROM Topics WHERE Topics.id IN ($in)",
					$value, true, PDO::FETCH_ASSOC|PDO::FETCH_UNIQUE
				);
			}
			
			// разбираем полученные с api данные
			foreach ( $data as $topic_id => $info ) {
				$stored = in_array($info['forum_id'], $subsec);
				// для отправки дальше
				$tmp['topics'][$topic_id]['id'] = $topic_id;
				$tmp['topics'][$topic_id]['ss'] = $info['forum_id'];
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
				unset($tc_topics[$info['info_hash']]);
			}
			unset($topics_old);
			unset($data);
			
			// пишем данные о топиках в базу
			if ( isset ( $tmp['topics'] ) ) {
				$select = Db::combine_set( $tmp['topics'] );
				unset($tmp['topics']);
				Db::query_database( "INSERT INTO temp.Topics1 $select" );
				unset($select);
			}
			
			unset($tmp);
		}
		
		// удаляем перерегистрированные раздачи
		if( !empty( $topics_del ) ) {
			$in = implode( ',', $topics_del );
			Db::query_database( "DELETE FROM Topics WHERE id IN ($in)" );
		}
		
	}
	
	public function prepare_topics($ids, $tc_topics, $subsec, $avg_seeders){
		
		// создаём временные таблицы
		Db::query_database('CREATE TEMP TABLE Topics1 AS SELECT * FROM Topics WHERE 0 = 1');
		
		// получаем дату предыдущего обновления
		$ud = Db::query_database(
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
		if( !empty($ids) )
			$this->insert_topics($ids, $tc_topics, $subsec, $last, $current, $avg_seeders);
		unset($ids);
		
		// раздачи из других подразделов
		if ( !empty ( $tc_topics ) ) {
			Log::append ( 'Поиск раздач из других подразделов...' );
			$ids = $this->get_topic_id ( array_keys ( $tc_topics ) );
			if( !empty($ids) )
				$this->insert_topics($ids, $tc_topics, $subsec, $last, $current, $avg_seeders);
			unset($ids);
		}
		
		$q = Db::query_database("SELECT COUNT() FROM temp.Topics1", array(), true, PDO::FETCH_COLUMN);
		if ( $q[0] > 0 ) {
			Log::append ( 'Запись в базу данных сведений о раздачах...' );
			$in = str_repeat( '?,', count( $subsec ) - 1 ) . '?';
			Db::query_database("INSERT INTO Topics SELECT * FROM temp.Topics1");
			Db::query_database("DELETE FROM Topics WHERE id IN ( SELECT Topics.id FROM Topics LEFT JOIN temp.Topics1 ON Topics.id = temp.Topics1.id WHERE temp.Topics1.id IS NULL AND ( Topics.ss IN ($in) OR Topics.dl = -2 ) )", $subsec);
		}
		
		// время последнего обновления
		Db::query_database('UPDATE `Other` SET ud = ? WHERE id = 0', array($current->format('U')));
		
	}
	
	public function __destruct(){
		curl_close($this->ch);
	}
}

?>
