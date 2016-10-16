<?php

class Webtlo {
	
	public $ch;
	public $db;
	public $api_key;
	public $api_url;
	public $log;
	public $limit;
	
	public function __construct($api_key, $api_url, $proxy_activate, $proxy_type = 0, $proxy_address = "", $proxy_auth = ""){
		$this->log = get_now_datetime() . 'Получение данных с ' . $api_url . '...<br />';
		$this->api_key = $api_key;
		$this->api_url = $api_url;
		$this->init_curl();
		if(is_array($proxy_activate)) curl_setopt_array($this->ch, $proxy_activate);
		else $this->init_proxy($proxy_activate, $proxy_type, $proxy_address, $proxy_auth);
		$this->limit = $this->get_limit();
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
	}
	
	private function init_proxy($proxy_activate = false, $proxy_type, $proxy_address, $proxy_auth){
		$this->make_database();
		if($proxy_activate) {
			$this->log .= get_now_datetime() . 'Используется ' . mb_strtoupper($proxy_type) . '-прокси: "' . $proxy_address . '".<br />';
			$proxy_array = array( 'http' => 0, 'socks4' => 4, 'socks4a' => 6, 'socks5' => 5	);
			$proxy_type = (array_key_exists($proxy_type, $proxy_array) ? $proxy_array[$proxy_type] : null);
			$proxy_address = (in_array(null, explode(':', $proxy_address)) ? null : $proxy_address);
			$proxy_auth = (in_array(null, explode(':', $proxy_auth)) ? null : $proxy_auth);
			curl_setopt_array($this->ch, array(
				CURLOPT_PROXYTYPE => $proxy_type,
				CURLOPT_PROXY => $proxy_address,
				CURLOPT_PROXYUSERPWD => $proxy_auth
			));
		} else {
			$this->log .= get_now_datetime() . 'Прокси-сервер не используется.<br />';
		}
	}
	
	private function request_exec($url){
		$n = 1; // кол-во попыток
		curl_setopt($this->ch, CURLOPT_URL, $url);
		while(true){
			$json = curl_exec($this->ch);
			if($json === false) {
				throw new Exception(get_now_datetime() . 'CURL ошибка: ' . curl_error($this->ch) . '<br />');
			}
			$data = json_decode($json, true);
			if(isset($data['error'])){
				if($data['error']['code'] == '503' && $n <= 3){
					$this->log .= get_now_datetime() . 'Повторная попытка ' . $n . '/3 получить данные.<br />';
					sleep(20);
					$n++;
					continue;
				}
				throw new Exception(get_now_datetime() . 'API ошибка: ' . $data['error']['text'] . '<br />');
			}
			break;
		}
		return $data;
	}
	
	// ограничение на запрашиваемые данные
	private function get_limit(){
		$url = $this->api_url . '/v1/get_limit?api_key=' . $this->api_key;
		$data = $this->request_exec($url);
		return $data['result']['limit'];
	}
	
	// статусы раздач
	public function get_tor_status_titles($tor_status){
		if(!is_array($tor_status))
			throw new Exception(get_now_datetime() . 'В настройках не выбран статус раздач на трекере.<br />');
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
	public function get_cat_forum_tree($subsections_use){
		$this->log .= get_now_datetime() . 'Получение дерева разделов...<br />';
		$url = $this->api_url . '/v1/static/cat_forum_tree?api_key=' . $this->api_key;
		$data = $this->request_exec($url);
		$tmp = array();
		$subsections_use = explode(',', $subsections_use);
		foreach($data['result']['c'] as $cat_id => $cat_title){
		    foreach($data['result']['tree'][$cat_id] as $forum_id => $subforum){
				// разделы
				if(in_array($forum_id, $subsections_use)){
					$tmp['subsections'][$forum_id]['id'] = $forum_id;
		            $tmp['subsections'][$forum_id]['na'] = $cat_title.' » '.$data['result']['f'][$forum_id];
				}
	            $tmp['insert'][] = 'SELECT '.$forum_id.','.$this->db->quote($cat_title.' » '.$data['result']['f'][$forum_id]).' UNION ALL';
		        // подразделы
		        foreach($subforum as $subforum_id){
		            if(in_array($subforum_id, $subsections_use)){
			            $tmp['subsections'][$subforum_id]['id'] = $subforum_id;
			            $tmp['subsections'][$subforum_id]['na'] = $cat_title.' » '.$data['result']['f'][$forum_id].' » '.$data['result']['f'][$subforum_id];
					}
		            $tmp['insert'][] = 'SELECT '.$subforum_id.','.$this->db->quote($cat_title.' » '.$data['result']['f'][$forum_id].' » '.$data['result']['f'][$subforum_id]).' UNION ALL';
		        }
		    }
		}
		
		// разбираем $tmp
		$tree = array_chunk($tmp['insert'], 500, true);
		$subsections = $tmp['subsections'];
		
		// отправляем в базу данных
		foreach($tree as $value){
			$this->query_database('INSERT INTO temp.Forums1 (`id`,`na`) ' . rtrim(implode(' ', $value), ' UNION ALL'));
		}
		
		$this->query_database('INSERT INTO `Forums` ( `id`,`na` ) SELECT * FROM temp.Forums1');
		$this->query_database('DELETE FROM `Forums` WHERE id IN ( SELECT Forums.id FROM Forums LEFT JOIN temp.Forums1 ON Forums.id = temp.Forums1.id WHERE temp.Forums1.id IS NULL )');
		
		return $subsections;
	}
	
	// список раздач раздела
	public function get_subsection_data($subsections, $status){
		$this->log .= get_now_datetime() . 'Получение списка раздач...<br />';
		foreach($subsections as $subsection){
			$url = $this->api_url . '/v1/static/pvc/f/' . $subsection['id'] . '?api_key=' . $this->api_key;
			$data = $this->request_exec($url);
			$this->log .= get_now_datetime() . 'Список раздач раздела № ' . $subsection['id'] . ' получен (' . count($data['result']) . ' шт.).<br />';
			foreach($data['result'] as $id => $val){
				// только раздачи с выбранными статусами
				if(isset($val[0]) && in_array($val[0], $status)){
					$ids[] = $id;
				}
			}
		}
		return $ids;
	}
	
	// получить id раздачи по hash
	public function get_topic_id($hashes){
		if(empty($hashes)) return;
		$this->log .= get_now_datetime() . 'Поиск раздач из других подразделов...<br />';
		$hashes = array_chunk($hashes, $this->limit, false);
		foreach($hashes as $hashes){
			$value = implode(',', $hashes);
			$url = $this->api_url . '/v1/get_topic_id?by=hash&api_key=' . $this->api_key . '&val=' . $value;
			$data = $this->request_exec($url);
			foreach($data['result'] as $id){
				if(!empty($id)) $ids[] = $id;
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
		if(empty($ids)) return array();
		$this->log .= get_now_datetime() . 'Получение подробных сведений о раздачах...<br />';
		$ids = array_chunk($ids, $this->limit, false);
		foreach($ids as $ids){
			$value = implode(',', $ids);
			$url = $this->api_url . '/v1/get_tor_topic_data?by=topic_id&api_key=' . $this->api_key . '&val=' . $value;
			$data = $this->request_exec($url);
			foreach($data['result'] as $topic_id => $info){
				if(is_array($info)) $topics[$topic_id] = $info;
			}
		}
		return $topics;
	}
	
	public function preparation_of_topics($data, $tc_topics, $rule, $subsec, $avg_seeders, $time){
		if(empty($data)) return;
		$subsec = explode(',', $subsec);
		if($avg_seeders){
			$in = str_repeat('?,', count($subsec) - 1) . '?';
			$this->log .= get_now_datetime() . 'Задействован алгоритм поиска среднего значения количества сидов...<br />';
			$this->log .= get_now_datetime() . 'Получение информации о раздачах за предыдущее обновление...<br />';
			$topics_old = $this->query_database("SELECT Topics.id,se,rt,ds,ud FROM `Topics` INNER JOIN Other WHERE `ss` IN ($in)", $subsec, true, PDO::FETCH_GROUP|PDO::FETCH_ASSOC);
			$this->log .= get_now_datetime() . 'Получение информации о средних сидах за предыдущее обновление...<br />';
			for($i = 0; $i <= $time - 1; $i++){
				$days_fields[] = 'd'.$i;
				$days_fields[] = 'q'.$i;
			}
			$seeders = $this->query_database('SELECT `id`,'.implode(',',$days_fields).' FROM `Seeders`', array(), true, PDO::FETCH_GROUP|PDO::FETCH_ASSOC);
		}
		
		$ud_current = new DateTime('now'); // текущая дата обновления сведений
		$ud_old = new DateTime(); // прошлое значение даты обновления сведений
		
		// разбираем полученные с api данные
		foreach($data as $topic_id => $info){
			$stored = in_array($info['forum_id'], $subsec);
			// для отправки дальше
			$tmp['topics'][$topic_id]['id'] = $topic_id;
			$tmp['topics'][$topic_id]['ss'] = $stored ? $info['forum_id'] : 0;
			$tmp['topics'][$topic_id]['na'] = $info['topic_title'];
			$tmp['topics'][$topic_id]['hs'] = $info['info_hash'];
			$tmp['topics'][$topic_id]['si'] = $info['size'];
			$tmp['topics'][$topic_id]['st'] = $info['tor_status'];
			$tmp['topics'][$topic_id]['rg'] = $info['reg_time'];
			// средние сиды
			$days = 0;
			$sum_updates = 1;
			$sum_seeders = $info['seeders'];
			$avg_seeders = $sum_seeders / $sum_updates;
			if(isset($topics_old[$topic_id])){
				// переносим старые значения
				$days = $topics_old[$topic_id][0]['ds'];
				if(isset($seeders[$topic_id])) $tmp['seeders'][$topic_id] = $seeders[$topic_id][0];
				$ud_old->setTimestamp($topics_old[$topic_id][0]['ud'])->setTime(0, 0, 0);
				if($ud_current->diff($ud_old)->format('%d') > 0 && $stored){
					$tmp['seeders'][$topic_id]['d'.$days % $time] = $topics_old[$topic_id][0]['se'];
					$tmp['seeders'][$topic_id]['q'.$days % $time] = $topics_old[$topic_id][0]['rt'];
					$tmp['seeders'][$topic_id] += array_fill_keys($days_fields, '');
					$seeders_insert = preg_replace('/^$/', "''", $tmp['seeders'][$topic_id]);
					$avg_seeders = ($this->sum_values($tmp['seeders'][$topic_id], 'd') + $sum_seeders) / ($this->sum_values($tmp['seeders'][$topic_id], 'q') + $sum_updates);
					$days++;
					// формирование массива для вставки средних сидов
					$tmp['insert_seeders'][] = "SELECT " .
					    "{$topic_id},".
					    implode(",", $seeders_insert) . " UNION ALL";
				} else {
					$sum_updates = $topics_old[$topic_id][0]['rt'] + 1;
					$sum_seeders = $topics_old[$topic_id][0]['se'] + $info['seeders'];
					$avg_seeders = isset($tmp['seeders'][$topic_id]) ? ($this->sum_values($tmp['seeders'][$topic_id], 'd') + $sum_seeders) / ($this->sum_values($tmp['seeders'][$topic_id], 'q') + $sum_updates) : $sum_seeders / $sum_updates;
				}
			}
			$tmp['topics'][$topic_id]['ud'] = $ud_current->format('U');
			$tmp['topics'][$topic_id]['ds'] = $days;
			$tmp['topics'][$topic_id]['se'] = $sum_seeders;
			$tmp['topics'][$topic_id]['rt'] = $sum_updates;
			$tmp['topics'][$topic_id]['avg'] = $avg_seeders;
			$tmp['topics'][$topic_id]['cl'] = isset($tc_topics[$info['info_hash']]) ? $tc_topics[$info['info_hash']]['client'] : '';
			// "0" - не храню, "1" - храню (раздаю), "-1" - храню (качаю)
			$tmp['topics'][$topic_id]['dl'] = !isset($tc_topics[$info['info_hash']]) ? 0 : (!$stored ? -2 : ($tc_topics[$info['info_hash']]['status'] == 1 ? 1 : -1));
			
			// для вставки в базу
			$tmp['insert_topics'][] = "SELECT " .
			    "{$topic_id},
			    {$tmp['topics'][$topic_id]['ss']},
			    {$this->db->quote($info['topic_title'])},
			    '{$info['info_hash']}',
			    {$sum_seeders},
			    {$info['size']},
			    {$info['tor_status']},
			    {$info['reg_time']},
			    {$tmp['topics'][$topic_id]['dl']},
			    {$sum_updates},
			    {$days},
			    '{$tmp['topics'][$topic_id]['cl']}'" .
		    " UNION ALL";
		}
		
		// разбираем $tmp
		$insert_topics = array_chunk($tmp['insert_topics'], 500, false);
		foreach($tmp['topics'] as $id => $topic){
			if($topic['avg'] <= $rule || $topic['dl'] == -2) $topics[$id] = $topic;
		}
		// сортируем топики по кол-ву сидов по возрастанию
		uasort($topics, array($this, 'sort_topics'));
		
		// отправляем в базу данных топики
		$this->log .= get_now_datetime() . 'Запись в базу данных сведений о раздачах...<br />';
		foreach($insert_topics as $value){
			$this->query_database('INSERT INTO temp.Topics1 (`id`,`ss`,`na`,`hs`,`se`,`si`,`st`,`rg`,`dl`,`rt`,`ds`,`cl`) ' . rtrim(implode(' ', $value), ' UNION ALL'));
		}
		
		$this->query_database('INSERT INTO `Topics` SELECT * FROM temp.Topics1');
		$this->query_database('DELETE FROM `Topics` WHERE id IN ( SELECT Topics.id FROM Topics LEFT JOIN temp.Topics1 ON Topics.id = temp.Topics1.id WHERE temp.Topics1.id IS NULL )');

		// отправляем в базу данных средние сиды
		if(isset($tmp['insert_seeders'])){
			$this->log .= get_now_datetime() . 'Запись в базу данных сведений о средних сидах...<br />';
			$insert_seeders = array_chunk($tmp['insert_seeders'], 500, false);

			foreach($insert_seeders as $value){
				$this->query_database('INSERT INTO temp.Seeders1 (`id`,'.implode(',',$days_fields).') ' . rtrim(implode(' ', $value), ' UNION ALL'));
			}
			
			$this->query_database('INSERT INTO `Seeders` SELECT * FROM temp.Seeders1');
		}
		
		// время последнего обновления
		$this->query_database('UPDATE Other SET ud = ? WHERE id = 0', array($ud_current->format('U')));
		
		return $topics;
	}
	
	private function query_database($sql = "", $param = array(), $fetch = false, $pdo = PDO::FETCH_ASSOC){
		$sth = $this->db->prepare($sql);
		if($this->db->errorCode() != '0000') {
			$db_error = $this->db->errorInfo();
			throw new Exception(get_now_datetime() . 'SQL ошибка: ' . $db_error[2] . '<br />');
		}
		$sth->execute($param);
		return $fetch ? $sth->fetchAll($pdo) : true;
	}
	
	private function make_database(){
		
		$this->log .= get_now_datetime() . 'Подготовка структуры базы данных...<br />';
		$this->db = new PDO('sqlite:' . dirname(__FILE__) . '/webtlo.db');
		
		// таблицы
		
		// список подразделов
		$this->query_database('CREATE TABLE IF NOT EXISTS Forums (
				id INT NOT NULL PRIMARY KEY,
				na VARCHAR NOT NULL
		)');
		
		// разное
		$this->query_database('CREATE TABLE IF NOT EXISTS Other AS SELECT 0 AS "id", 0 AS "ud"');
		
		// топики
		$this->query_database('CREATE TABLE IF NOT EXISTS Topics (
				id INT NOT NULL PRIMARY KEY,
				ss INT NOT NULL,
				na VARCHAR NOT NULL,
				hs VARCHAR NOT NULL,
				se INT NOT NULL,
				si INT NOT NULL,
				st INT NOT NULL,
				rg INT NOT NULL,
				dl INT NOT NULL DEFAULT 0
		)');
		
		// средние сиды
		$this->query_database('CREATE TABLE IF NOT EXISTS Seeders (
			id INT NOT NULL PRIMARY KEY,
			d0 INT, d1 INT,d2 INT,d3 INT,d4 INT,d5 INT,d6 INT,
			d7 INT,d8 INT,d9 INT,d10 INT,d11 INT,d12 INT,d13 INT,
			d14 INT,d15 INT,d16 INT,d17 INT,d18 INT,d19 INT,
			d20 INT,d21 INT,d22 INT,d23 INT,d24 INT,d25 INT,
			d26 INT,d27 INT,d28 INT,d29 INT,
			q0 INT, q1 INT,q2 INT,q3 INT,q4 INT,q5 INT,q6 INT,
			q7 INT,q8 INT,q9 INT,q10 INT,q11 INT,q12 INT,q13 INT,
			q14 INT,q15 INT,q16 INT,q17 INT,q18 INT,q19 INT,
			q20 INT,q21 INT,q22 INT,q23 INT,q24 INT,q25 INT,
			q26 INT,q27 INT,q28 INT,q29 INT
		)');
		
		// триггеры
		
		// удалить сведения о средних сидах при удалении раздачи
		$this->query_database('CREATE TRIGGER IF NOT EXISTS Seeders_delete
			BEFORE DELETE ON Topics FOR EACH ROW
			BEGIN
				DELETE FROM Seeders WHERE id = OLD.id;
			END;
		');
		
		// обновить при вставке такой же записи
		$this->query_database('CREATE TRIGGER IF NOT EXISTS Forums_update
			BEFORE INSERT ON Forums
	        WHEN EXISTS (SELECT id FROM Forums WHERE id = NEW.id)
			BEGIN
			    UPDATE Forums SET na = NEW.na
			    WHERE id = NEW.id;
			    SELECT RAISE(IGNORE);
			END;
		');
		
		$this->query_database('CREATE TRIGGER IF NOT EXISTS Topics_update
	        BEFORE INSERT ON Topics
	        WHEN EXISTS (SELECT id FROM Topics WHERE id = NEW.id)
			BEGIN
			    UPDATE Topics SET
					ss = NEW.ss, na = NEW.na, hs = NEW.hs, se = NEW.se,
					si = NEW.si, st = NEW.st, rg = NEW.rg, dl = NEW.dl,
					rt = NEW.rt, ds = NEW.ds, cl = NEW.cl
			    WHERE id = NEW.id;
			    SELECT RAISE(IGNORE);
			END;
		');
	
		$this->query_database('CREATE TRIGGER IF NOT EXISTS Seeders_update
	        BEFORE INSERT ON Seeders
	        WHEN EXISTS (SELECT id FROM Seeders WHERE id = NEW.id)
			BEGIN
			    UPDATE Seeders SET
				    d0 = NEW.d0, d1 = NEW.d1, d2 = NEW.d2, d3 = NEW.d3,
				    d4 = NEW.d4, d5 = NEW.d5, d6 = NEW.d6, d7 = NEW.d7,
				    d8 = NEW.d8, d9 = NEW.d9, d10 = NEW.d10, d11 = NEW.d11,
				    d12 = NEW.d12, d13 = NEW.d13, d14 = NEW.d14, d15 = NEW.d15,
				    d16 = NEW.d16, d17 = NEW.d17, d18 = NEW.d18, d19 = NEW.d19,
				    d20 = NEW.d20, d21 = NEW.d21, d22 = NEW.d22, d23 = NEW.d23,
				    d24 = NEW.d24, d25 = NEW.d25, d26 = NEW.d26, d27 = NEW.d27,
				    d28 = NEW.d28, d29 = NEW.d29,
				    q0 = NEW.q0, q1 = NEW.q1, q2 = NEW.q2, q3 = NEW.q3,
				    q4 = NEW.q4, q5 = NEW.q5, q6 = NEW.q6, q7 = NEW.q7,
				    q8 = NEW.q8, q9 = NEW.q9, q10 = NEW.q10, q11 = NEW.q11,
				    q12 = NEW.q12, q13 = NEW.q13, q14 = NEW.q14, q15 = NEW.q15,
				    q16 = NEW.q16, q17 = NEW.q17, q18 = NEW.q18, q19 = NEW.q19,
				    q20 = NEW.q20, q21 = NEW.q21, q22 = NEW.q22, q23 = NEW.q23,
				    q24 = NEW.q24, q25 = NEW.q25, q26 = NEW.q26, q27 = NEW.q27,
				    q28 = NEW.q28, q29 = NEW.q29
			    WHERE id = NEW.id;
			    SELECT RAISE(IGNORE);
			END;
		');
		
		// совместимость со старыми версиями базы данных
		$version = $this->query_database('PRAGMA user_version', array(), true);
		if($version[0]['user_version'] < 1){
				$this->query_database('ALTER TABLE Topics ADD COLUMN rt INT DEFAULT 1');
				$this->query_database('ALTER TABLE Topics ADD COLUMN ds INT DEFAULT 0');
				$this->query_database('ALTER TABLE Topics ADD COLUMN cl VARCHAR');
				$this->query_database('PRAGMA user_version = 1');
		}
		
		// временные таблицы
		$this->query_database('CREATE TEMP TABLE Forums1 AS SELECT * FROM Forums WHERE 0 = 1');
		$this->query_database('CREATE TEMP TABLE Topics1 AS SELECT * FROM Topics WHERE 0 = 1');
		$this->query_database('CREATE TEMP TABLE Seeders1 AS SELECT * FROM Seeders WHERE 0 = 1');
		
	}
	
	public function __destruct(){
		curl_close($this->ch);
		$this->db = null;
	}
}

class FromDatabase {
	
	public $db;
	public $log;
	
	public function __construct(){
		$this->log = '';
		$this->db = new PDO('sqlite:' . dirname(__FILE__) . '/webtlo.db');
	}
	
	private function query_database($sql = "", $param = array(), $pdo = PDO::FETCH_ASSOC, $fetch = true){
		$sth = $this->db->prepare($sql);
		if($this->db->errorCode() != '0000') {
			$db_error = $this->db->errorInfo();
			throw new Exception(get_now_datetime() . 'SQL ошибка: ' . $db_error[2] . '<br />');
		}
		$sth->execute($param);
		return $fetch ? $sth->fetchAll($pdo) : true;
	}
	
	// ... из базы подразделы для списка раздач на главной
	public function get_forums($subsec){
		$this->log .= get_now_datetime() . 'Получение данных о подразделах...<br />';
		if(!is_array($subsec)) $subsec = explode(',', $subsec);
		$in = str_repeat('?,', count($subsec) - 1) . '?';
		$subsections = $this->query_database("SELECT * FROM `Forums` WHERE `id` IN ($in)", $subsec);
		if(empty($subsections)) throw new Exception();
		return $subsections;
	}
	
	// ... из базы топики
	public function get_topics($seeders, $status, $time){
		$this->log .= get_now_datetime() . 'Получение данных о раздачах...<br />';
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
		
}

class Download {

	public $ch;
	public $api_key;
	public $log;
	
	public function __construct($api_key, $proxy_activate, $proxy_type, $proxy_address, $proxy_auth){
		$this->log = get_now_datetime() . 'Скачивание торрент-файлов...<br />';
		$this->api_key = $api_key;
		$this->ch = curl_init();
		curl_setopt_array($this->ch, array(
			CURLOPT_RETURNTRANSFER => 1,
			CURLOPT_SSL_VERIFYPEER => 0,
			CURLOPT_SSL_VERIFYHOST => 0
			//~ CURLOPT_CONNECTTIMEOUT => 60
		));
		// прокси
		if($proxy_activate) {
			$this->log .= get_now_datetime() . 'Используется ' . mb_strtoupper($proxy_type) . '-прокси: "' . $proxy_address . '".<br />';
			$this->init_proxy($proxy_type, $proxy_address, $proxy_auth);
		} else {
			$this->log .= get_now_datetime() . 'Прокси-сервер не используется.<br />';
		}
	}
	
	private function init_proxy($proxy_type, $proxy_address, $proxy_auth){
		$proxy_array = array(
			'http' => 0,
			'socks4' => 4,
			'socks4a' => 6,
			'socks5' => 5
		);		
		$proxy_type = (array_key_exists($proxy_type, $proxy_array) ? $proxy_array[$proxy_type] : null);
		$proxy_address = (in_array(null, explode(':', $proxy_address)) ? null : $proxy_address);
		$proxy_auth = (in_array(null, explode(':', $proxy_auth)) ? null : $proxy_auth);		
		curl_setopt_array($this->ch, array(
			CURLOPT_PROXYTYPE => $proxy_type,
			CURLOPT_PROXY => $proxy_address,
			CURLOPT_PROXYUSERPWD => $proxy_auth
		));
	}
	
	// идентификатор пользователя
	private function get_user_id($forum_url, $login, $paswd){
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
		if($json === false)
			throw new Exception(get_now_datetime() . 'CURL ошибка: ' . curl_error($this->ch) . '<br />');
		preg_match("/.*Set-Cookie: [^-]*-([0-9]*)/", $json, $tmp);
		if(!ctype_digit($tmp[1])){
			preg_match('|<title>(.*)</title>|sei', $json, $title);
			if(!empty($title))
				if($title[1] == 'rutracker.org'){
					preg_match('|<h4[^>]*?>(.*)</h4>|sei', $json, $text);
					if(!empty($text))
						$this->log .= get_now_datetime() . 'Error: ' . $title[1] . ' - ' . mb_convert_encoding($text[1], 'UTF-8', 'Windows-1251') . '.<br />';
				} else
					$this->log .= get_now_datetime() . 'Error: ' . mb_convert_encoding($title[1], 'UTF-8', 'Windows-1251') . '.<br />';
			throw new Exception(				
				get_now_datetime() .
				'Получен некорректный идентификатор пользователя: "' .
				(isset($tmp[1]) ? $tmp[1] : 'null') . '".<br />'
			);
		}
		return $tmp[1];
	}
	
	// скачивание т-.файлов
	public function download_torrent_files($savedir, $forum_url, $login, $paswd, $topics, $retracker, &$dl_log){
		$q = 0; // кол-во успешно скачанных торрент-файлов
		//~ $err = 0;
		$starttime = microtime(true);
		$user = $this->get_user_id($forum_url, $login, $paswd);
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
			$torrent_file = $savedir . '[webtlo].t' . $topic['id'] . '.torrent';
			//~ $torrent_file = mb_convert_encoding($savedir . '[webtlo].t' . $topic['id'] . '.torrent', 'Windows-1251', 'UTF-8');
			$n = 1; // кол-во попыток
			while(true) {
				
				// выходим после 3-х попыток
				if($n >= 4) {
					$this->log .= get_now_datetime() . 'Не удалось скачать торрент-файл для ' . $topic['id'] . '.<br />';
					break;
				}
				
				$json = curl_exec($this->ch);
				
				if($json === false) {
					$this->log .= get_now_datetime() . 'CURL ошибка: ' . curl_error($this->ch) . ' (раздача ' . $topic['id'] . ').<br />';
					break;
				}
				
				// проверка "торрент не зарегистрирован" и т.д.
				preg_match('|<center.*>(.*)</center>|sei', mb_convert_encoding($json, 'UTF-8', 'Windows-1251'), $forbidden);
				if(!empty($forbidden)) {
					preg_match('|<title>(.*)</title>|sei', mb_convert_encoding($json, 'UTF-8', 'Windows-1251'), $title);
					$this->log .= get_now_datetime() . 'Error: ' . (empty($title) ? $forbidden[1] : $title[1]) . ' (' . $topic['id'] . ').<br />';
					break;
				}
				
				// проверка "ошибка 503" и т.д.
				preg_match('|<title>(.*)</title>|sei', mb_convert_encoding($json, 'UTF-8', 'Windows-1251'), $error);
				if(!empty($error)) {
					$this->log .= get_now_datetime() . 'Error: ' . $error[1] . ' (' . $topic['id'] . ').<br />';
					$this->log .= get_now_datetime() . 'Повторная попытка ' . $n . '/3 скачать торрент-файл (' . $topic['id'] . ').<br />';
					sleep(40);
					$n++;
					continue;
				}
				
				// сохраняем в файл
				if(!file_put_contents($torrent_file, $json) === false) {
					$success[$q]['id'] = $topic['id'];
					$success[$q]['hash'] = $topic['hash'];
					$success[$q]['filename'] = 'http://' . $_SERVER['SERVER_ADDR'] . '/' . basename($savedir) . '/[webtlo].t'.$topic['id'].'.torrent';
					$q++;
					//~ $this->log .= get_now_datetime() . 'Успешно сохранён торрент-файл для ' . $topic['id'] . '.<br />';
				}
				
				break;
			}
		}
		$endtime1 = microtime(true);
		$dl_log .= 'Сохранено в каталоге "' . $savedir . '": <span class="rp-header">' . $q . '</span> шт. (за ' . round($endtime1-$starttime, 1). ' с).'; //, ошибок: ' . $err . '.';
		return isset($success) ? $success : null;
	}
	
	public function __destruct(){
		curl_close($this->ch);
	}
	
}
	
?>
