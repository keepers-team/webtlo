<?php
/*
 * web-TLO (Web Torrent List Organizer)
 * api.php
 * author: berkut_174 (webtlo@yandex.ru)
 * last change: 10.03.2016
 */

class Webtlo {
	
	public $ch;
	public $db;
	public $api_key;
	public $api_url;
	public $log;
	
	public function __construct($api_key, $api_url, $proxy_activate, $proxy_type, $proxy_address, $proxy_auth){
		$this->log = date("H:i:s") . ' Получение данных с api.rutracker.' . $api_url . '...<br />';
		$this->api_key = $api_key;
		$this->api_url = $api_url;
		$this->db = new PDO('sqlite:' . dirname(__FILE__) . '/webtlo.db');
		$this->ch = curl_init();
		curl_setopt_array($this->ch, array(
		    CURLOPT_RETURNTRANSFER => 1,
		    CURLOPT_ENCODING => "gzip",
		    //~ CURLOPT_CONNECTTIMEOUT => 60
		));
		// прокси
		if($proxy_activate) {
			$this->log .= date("H:i:s") . ' Используется ' . mb_strtoupper($proxy_type) . '-прокси: "' . $proxy_address . '".<br />';
			$this->init_proxy($proxy_type, $proxy_address, $proxy_auth);
		} else {
			$this->log .= date("H:i:s") . ' Прокси-сервер не используется.<br />';
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
	
	private function request_exec($url){
		$n = 1; // кол-во попыток
		curl_setopt($this->ch, CURLOPT_URL, $url);
		while(true){
			$json = curl_exec($this->ch);
			if($json === false) {
				//~ curl_close($this->ch);
				throw new Exception(date("H:i:s") . ' CURL ошибка: ' . curl_error($this->ch) . '<br />');
			}
			$data = json_decode($json, true);
			if(isset($data['error'])){
				if($data['error']['code'] == '503' && $n <= 3){
					$this->log .= date("H:i:s") . ' Повторная попытка ' . $n . '/3 получить данные.<br />';
					sleep(20);
					$n++;
					continue;
				}
				//~ curl_close($this->ch);
				throw new Exception(date("H:i:s") . ' API ошибка: ' . $data['error']['text'] . '<br />');
			}
			break;
		}
		return $data;
	}
	
	// ограничение на запрашиваемые данные
	private function get_limit(){
		$url = 'http://api.rutracker.' . $this->api_url . '/v1/get_limit?api_key=' . $this->api_key;
		$data = $this->request_exec($url);
		return $data['result']['limit'];
	}
	
	// статусы раздач
	public function get_tor_status_titles($tor_status){
		if(!is_array($tor_status))
			throw new Exception(date("H:i:s") . ' Не выбран ни один из статусов раздач на трекере.<br />');
		$url = 'http://api.rutracker.' . $this->api_url . '/v1/get_tor_status_titles?api_key=' . $this->api_key;
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
		$this->log .= date("H:i:s"). ' Получение дерева разделов...<br />';
		// готовим таблицу
		$this->db->exec('CREATE TABLE IF NOT EXISTS `Forums` (
				id INT NOT NULL PRIMARY KEY,
				na VARCHAR NOT NULL
		)');
		if($this->db->errorCode() != '0000') {
			$db_error = $this->db->errorInfo();
			throw new Exception(date("H:i:s") . " SQL ошибка: " . $db_error[2] . '<br />');
		}
		$url = 'http://api.rutracker.' . $this->api_url . '/v1/static/cat_forum_tree?api_key=' . $this->api_key;
		$data = $this->request_exec($url);
		$tmp = array();
		$subsections_use = explode(',', $subsections_use);
		foreach($data['result']['c'] as $cat_id => $cat_title){
		    foreach($data['result']['tree'][$cat_id] as $forum_id => $subforum){
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
		
		// пишем в БД
		foreach($tree as $value){
			$sql = 'INSERT INTO `Forums` (`id`,`na`) ' . rtrim(implode(' ', $value), ' UNION ALL');
			$this->db->prepare($sql);
			if($this->db->errorCode() != '0000') {
				$db_error = $this->db->errorInfo();
				throw new Exception(date("H:i:s") . " SQL ошибка: " . $db_error[2] . '<br />');
			}
		}
		$this->db->query('DELETE FROM `Forums`');
		foreach($tree as $value){
			$sql = 'INSERT INTO `Forums` (`id`,`na`) ' . rtrim(implode(' ', $value), ' UNION ALL');
			$this->db->query($sql);
		}
		return $subsections;
	}
	
	// список раздач раздела
	public function get_subsection_data($subsections, $status){
		$this->log .= date("H:i:s") . ' Получение списка раздач...<br />';
		$ids = array();
		// узнаём лимит на кол-во запросов
		$limit = $this->get_limit();
		//~ $tmp = array();
		foreach($subsections as $subsection){
			$url = 'http://api.rutracker.' . $this->api_url . '/v1/static/pvc/f/' . $subsection['id'] . '?api_key=' . $this->api_key;
			$data = $this->request_exec($url);
			$this->log .= date("H:i:s") . ' Список раздач раздела № ' . $subsection['id'] . ' получен (' . count($data['result']) . ' шт.).<br />';
			foreach($data['result'] as $id => $val){
				// только раздачи с выбранными статусами
				if((isset($val[0])) && (in_array($val[0], $status))){
					$tmp[] = $id;
				}
			}
		}
		// разбираем $tmp
		$id_list = array_chunk($tmp, $limit, false);
		foreach($id_list as $num => $id){
			$ids[] = implode(',', $id);
		}
		// дата обновления
		$ini = new TIniFileEx(dirname(__FILE__) . '/config.ini');
		$ini->write('other', 'update_time', $data['update_time']);
		$ini->updateFile();
		return $ids;
	}	
	
	// сведения о каждой раздаче
	public function get_tor_topic_data($ids, $tc_topics, $rule, $subsections_use){
		$this->log .= date("H:i:s") . ' Получение подробных сведений о раздачах...<br />';
		$topics = array();
		// готовим БД
		$this->db->exec('CREATE TABLE IF NOT EXISTS `Topics` (
				id VARCHAR NOT NULL PRIMARY KEY,
				ss INT NOT NULL,
				na VARCHAR NOT NULL,
				hs VARCHAR NOT NULL,
				se INT NOT NULL,
				si VARCHAR NOT NULL,
				st INT NOT NULL,
				rg VARCHAR NOT NULL,
				dl INT NOT NULL
		)');
		if($this->db->errorCode() != '0000') {
			$db_error = $this->db->errorInfo();
			throw new Exception(date("H:i:s") . " SQL ошибка: " . $db_error[2] . '<br />');
		}
		
		$tmp = array();
		
		foreach($ids as $ids){			
			$url = 'http://api.rutracker.' . $this->api_url . '/v1/get_tor_topic_data?by=topic_id&api_key=' . $this->api_key . '&val=' . $ids;
			$data = $this->request_exec($url);
			
			// разбираем полученные с api данные
			foreach($data['result'] as $topic_id => $info){
				// для отправки дальше
				$tmp['topics'][$topic_id]['id'] = $topic_id;
				$tmp['topics'][$topic_id]['ss'] = $info['forum_id'];
				$tmp['topics'][$topic_id]['na'] = $info['topic_title'];
				$tmp['topics'][$topic_id]['hs'] = $info['info_hash'];
				$tmp['topics'][$topic_id]['se'] = $info['seeders'];
				$tmp['topics'][$topic_id]['si'] = $info['size'];
				$tmp['topics'][$topic_id]['st'] = $info['tor_status'];
				$tmp['topics'][$topic_id]['rg'] = $info['reg_time'];
				// "0" - не храню, "1" - храню (раздаю), "-1" - храню (качаю)
				if(isset($tc_topics[$info['info_hash']])){
					$tmp['topics'][$topic_id]['dl'] = ($tc_topics[$info['info_hash']]['status'] == 1 ? 1 : -1);
				} else {
					$tmp['topics'][$topic_id]['dl'] = 0;
				}
				// для вставки в базу
				$tmp['insert'][] = "SELECT " .
				    "'{$topic_id}',
				    {$info['forum_id']},
				    {$this->db->quote($info['topic_title'])},
				    '{$info['info_hash']}',
				    {$info['seeders']},
				    '{$info['size']}',
				    {$info['tor_status']},
				    '{$info['reg_time']}',
				    {$tmp['topics'][$topic_id]['dl']}" .
			    " UNION ALL";
				//~ $tmp['insert'][] = "SELECT " . 
				    //~ $topic_id . ',' .
				    //~ $info['forum_id'] . ',' .
				    //~ $this->db->quote($info['topic_title']) . ',' .
				    //~ $this->db->quote($info['info_hash']) . ',' .
				    //~ $info['seeders'] . ',' .
				    //~ $this->db->quote($info['size']) . ',' .
				    //~ $info['tor_status'] . ',' .
				    //~ $this->db->quote($info['reg_time']) . ',' .
				    //~ $tmp['topics'][$topic_id]['dl'] . '.' .
			    //~ " UNION ALL";
			}
						
		}
		
		//~ $insert = array();
		
		// разбираем $tmp
		$insert = array_chunk($tmp['insert'], 500, false);
		foreach($tmp['topics'] as $id => $topic){
			if($topic['se'] <= $rule) $topics[$id] = $topic;
		}
		
		// пишем в БД
		foreach($insert as $value){
			$sql = 'INSERT INTO `Topics` (`id`,`ss`,`na`,`hs`,`se`,`si`,`st`,`rg`,`dl`) ' . rtrim(implode(' ', $value), ' UNION ALL');
			$this->db->prepare($sql);
			if($this->db->errorCode() != '0000') {
				$db_error = $this->db->errorInfo();
				throw new Exception(date("H:i:s") . " SQL ошибка: " . $db_error[2] . '<br />');
			}
		}
		$this->db->query('DELETE FROM `Topics` WHERE `ss` IN(' . $subsections_use . ')'); // удаляем все старые данные	
		foreach($insert as $value){
			$sql = 'INSERT INTO `Topics` (`id`,`ss`,`na`,`hs`,`se`,`si`,`st`,`rg`,`dl`) ' . rtrim(implode(' ', $value), ' UNION ALL');
			$this->db->query($sql);
		}
		
		return $topics;
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
	
	// ... из базы подразделы для списка раздач на главной
	public function get_forums($subsections_use){
		$query = $this->db->query('SELECT * FROM `Forums` WHERE `id` IN(' . $subsections_use . ')');
		if($this->db->errorCode() != '0000') {
			$db_error = $this->db->errorInfo();
			throw new Exception(date("H:i:s") . " SQL ошибка: " . $db_error[2] . '<br />');
		}
		$subsections = $query->fetchAll(PDO::FETCH_ASSOC);
		if(count($subsections) == 0)
			throw new Exception();
		$this->log .= date("H:i:s") . ' Данные о подразделах получены.<br />';
		return $subsections;
	}
	
	// ... из базы топики
	public function get_topics($seeders, $status){
		$query = $this->db->prepare('SELECT * FROM `Topics` WHERE `se` <= :se AND `dl` = :dl ORDER BY `ss`, `na`');
		if($this->db->errorCode() != '0000') {
			$db_error = $this->db->errorInfo();
			throw new Exception(date("H:i:s") . " SQL ошибка: " . $db_error[2] . '<br />');
		}
		$query->bindValue(':se', $seeders);
		$query->bindValue(':dl', $status);
		$query->execute();
		$topics = $query->fetchAll(PDO::FETCH_ASSOC);
		$this->log .= date("H:i:s") . ' Данные о раздачах получены.<br />';
		return $topics;
	}
	
	// ... из базы подразделы для отчётов
	public function get_forums_details($subsections_use){
		$query = $this->db->query('SELECT * FROM `Forums` WHERE `id` IN('.$subsections_use.')');
		if($this->db->errorCode() != '0000') {
			$db_error = $this->db->errorInfo();
			throw new Exception(date("H:i:s") . " SQL ошибка: " . $db_error[2] . '<br />');
		}
		$subsections = $query->fetchAll(PDO::FETCH_ASSOC);
		foreach($subsections as $id => $subsection){
			$query = $this->db->prepare('SELECT SUM(`si`) FROM `Topics` WHERE `ss` = :id');
			if($this->db->errorCode() != '0000') {
				$db_error = $this->db->errorInfo();
				throw new Exception(date("H:i:s") . " SQL ошибка: " . $db_error[2] . '<br />');
			}
			$query->bindValue(':id', $subsection['id']);
			$query->execute();
			$size = $query->fetchAll(PDO::FETCH_COLUMN);
			$query = $this->db->prepare('SELECT COUNT() FROM `Topics` WHERE `ss` = :id');
			if($this->db->errorCode() != '0000') {
				$db_error = $this->db->errorInfo();
				throw new Exception(date("H:i:s") . " SQL ошибка: " . $db_error[2] . '<br />');
			}
			$query->bindValue(':id', $subsection['id']);
			$query->execute();			
			$qt = $query->fetchAll(PDO::FETCH_COLUMN);
			$subsections[$id]['si'] = $size[0];
			$subsections[$id]['qt'] = $qt[0];
		}
		$this->log .= date("H:i:s") . ' Данные о подразделах получены.<br />';
		return $subsections;
	}
		
}

class Download {

	public $ch;
	public $api_key;
	public $log;
	
	public function __construct($api_key, $proxy_activate, $proxy_type, $proxy_address, $proxy_auth){
		$this->log = date("H:i:s") . ' Скачивание торрент-файлов...<br />';
		$this->api_key = $api_key;
		$this->ch = curl_init();
		curl_setopt_array($this->ch, array(
			CURLOPT_RETURNTRANSFER => 1,
			//~ CURLOPT_CONNECTTIMEOUT => 60
		));
		// прокси
		if($proxy_activate) {
			$this->log .= date("H:i:s") . ' Используется ' . mb_strtoupper($proxy_type) . '-прокси: "' . $proxy_address . '".<br />';
			$this->init_proxy($proxy_type, $proxy_address, $proxy_auth);
		} else {
			$this->log .= date("H:i:s") . ' Прокси-сервер не используется.<br />';
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
	private function get_user_id($login, $paswd){
		$paswd = mb_convert_encoding($paswd, 'Windows-1251', 'UTF-8');
		$login = mb_convert_encoding($login, 'Windows-1251', 'UTF-8');
		curl_setopt_array($this->ch, array(CURLOPT_URL => 'http://login.rutracker.org/forum/login.php',
			CURLOPT_POSTFIELDS => http_build_query(array(
				'login_username' => "$login", 'login_password' => "$paswd",
				'login' => 'Вход'
			)),
			CURLOPT_HEADER => 1
		));
		$json = curl_exec($this->ch);
		if($json === false)
			throw new Exception(date("H:i:s") . ' CURL ошибка: ' . curl_error($this->ch) . '<br />');
		preg_match("/.*Set-Cookie: [^-]*-([0-9]*)/", $json, $tmp);
		if(!ctype_digit($tmp[1])){
			preg_match('|<title>(.*)</title>|sei', $json, $title);
			if(!empty($title))
				if($title[1] == 'rutracker.org'){
					preg_match('|<h4[^>]*?>(.*)</h4>|sei', $json, $text);
					if(!empty($text))
						$this->log .= date("H:i:s") . ' Error: ' . $title[1] . ' - ' . mb_convert_encoding($text[1], 'UTF-8', 'Windows-1251') . '.<br />';
				} else
					$this->log .= date("H:i:s") . ' Error: ' . mb_convert_encoding($title[1], 'UTF-8', 'Windows-1251') . '.<br />';
			throw new Exception(				
				date("H:i:s") .
				' Получен некорректный идентификатор пользователя: "' .
				(isset($tmp[1]) ? $tmp[1] : 'null') . '".<br />'
			);
		}
		return $tmp[1];
	}
	
	// скачивание т-.файлов
	public function download_torrent_files($savedir, $login, $paswd, $topics, $retracker, &$dl_log){
		$q = 0; // кол-во успешно скачанных торрент-файлов
		//~ $err = 0;
		$starttime = microtime(true);
		$user = $this->get_user_id($login, $paswd);
		curl_setopt_array($this->ch, array(
		    CURLOPT_URL => 'http://dl.rutracker.org/forum/dl.php',
		    CURLOPT_HEADER => 0
		));
		//~ $topics = array_chunk($topics, 30, true);
		foreach($topics as $topic_id){
		    curl_setopt($this->ch, CURLOPT_POSTFIELDS, http_build_query(array(
			    'keeper_user_id' => $user,
			    'keeper_api_key' => "$this->api_key",
			    't' => $topic_id,
			    'add_retracker_url' => $retracker
		    )));
			$torrent_file = $savedir . '[webtlo].t' . $topic_id . '.torrent';
			//~ $torrent_file = mb_convert_encoding($savedir . '[webtlo].t' . $topic_id . '.torrent', 'Windows-1251', 'UTF-8');
			$n = 1; // кол-во попыток
			while(true) {
				
				// выходим после 3-х попыток
				if($n >= 4) {
					$this->log .= date("H:i:s") . ' Не удалось скачать торрент-файл для ' . $topic_id . '.<br />';
					break;
				}
				
				$json = curl_exec($this->ch);
				
				if($json === false) {
					$this->log .= date("H:i:s") . ' CURL ошибка: ' . curl_error($this->ch) . ' (раздача ' . $topic_id . ').<br />';
					break;
				}
				
				// проверка "торрент не зарегистрирован" и т.д.
				preg_match('|<center.*>(.*)</center>|sei', mb_convert_encoding($json, 'UTF-8', 'Windows-1251'), $forbidden);
				if(!empty($forbidden)) {
					preg_match('|<title>(.*)</title>|sei', mb_convert_encoding($json, 'UTF-8', 'Windows-1251'), $title);
					$this->log .= date("H:i:s") . ' Error: ' . (empty($title) ? $forbidden[1] : $title[1]) . ' (' . $topic_id . ').<br />';
					break;
				}
				
				// проверка "ошибка 503" и т.д.
				preg_match('|<title>(.*)</title>|sei', mb_convert_encoding($json, 'UTF-8', 'Windows-1251'), $error);
				if(!empty($error)) {
					$this->log .= date("H:i:s") . ' Error: ' . $error[1] . ' (' . $topic_id . ').<br />';
					$this->log .= date("H:i:s") . ' Повторная попытка ' . $n . '/3 скачать торрент-файл (' . $topic_id . ').<br />';
					sleep(40);
					$n++;
					continue;
				}
				
				// сохраняем в файл
				if(!file_put_contents($torrent_file, $json) === false) {
					$q++;
					$success[] = $topic_id;
					//~ $this->log .= date("H:i:s") . ' Успешно сохранён торрент-файл для ' . $topic_id . '.<br />';
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
