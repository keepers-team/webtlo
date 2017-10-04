<?php

// получение списка раздач
function get_tor_client_data ( $tcs ) {
	
	Log::append ( 'Получение данных от торрент-клиентов...' );
	Log::append ( 'Количество торрент-клиентов: ' . count($tcs) );
	$tc_topics = array();
	
	if( isset($tcs) && is_array($tcs) ) {
		foreach($tcs as $id => $tc) {
			$tmp = array();
			$client = new $tc['cl'] ( $tc['ht'], $tc['pt'], $tc['lg'], $tc['pw'], $tc['cm'] );
			if($client->is_online()) {
				$tmp = $client->getTorrents( $id );
				$tc_topics += $tmp;
			}
			Log::append ( $tc['cm'] . ' (' . $tc['cl'] . ') - получено раздач: ' . count($tmp) );
		}
	}
	
	// array ( [hash] => ( 'status' => status, 'client' => comment ) )
	// status: 0 - загружается, 1 - раздаётся, -1 - на паузе или стопе
	return $tc_topics;
	
}

// регулировка раздач
function topics_control( $topics, $tc_topics, $ids, $rule, $tcs = array() ) {
	
	$ids = array_flip( $ids );
	
	// выбираем раздачи для остановки
	foreach( $topics as $topic_id => $topic ) {
		
		// если нет такой раздачи или идёт загрузка раздачи, идём дальше
		if( empty( $tc_topics[$ids[$topic_id]]['status'] ) ) continue;
		$client = $tc_topics[$ids[$topic_id]];
		
		// учитываем себя
		$topic['seeders'] -= $topic['seeders'] ? $client['status'] : 0;
		// находим значение личей
		$leechers = $rule['leechers'] ? $topic['leechers'] : 0;
		// находим значение пиров
		$peers = $topic['seeders'] + $leechers;
		// учитываем вновь прибывшего "лишнего" сида
		$peers += $topic['seeders'] && $peers == $rule['peers'] && $client['status'] == 1 ? 1 : 0;
		
		// стопим только, если есть сиды
		if( ( $peers > $rule['peers'] || !$rule['no_leechers'] && !$topic['leechers'] ) && $topic['seeders'] ) {
			if( $client['status'] == 1 )
				$hashes[$client['client']]['stop'][] = $ids[$topic_id];
		} else {
			if( $client['status'] == -1 )
				$hashes[$client['client']]['start'][] = $ids[$topic_id];
		}
	}
	
	if( empty( $hashes ) )
		throw new Exception( 'Раздачи не нуждаются в регулировании.' );
	
	// выполняем запуск/остановку раздач
	foreach( $tcs as $cm => $tc ) {
		if( empty( $hashes[$cm] ) ) continue;
		$client = new $tc['cl'] ( $tc['ht'], $tc['pt'], $tc['lg'], $tc['pw'], $tc['cm'] );
		if( $client->is_online() ) {
			// запускаем
			if( !empty( $hashes[$cm]['start'] ) ) {
				$q = count( $hashes[$cm]['start'] );
				$hashes[$cm]['start'] = array_chunk( $hashes[$cm]['start'], 100 );
				foreach( $hashes[$cm]['start'] as $start ) {
					$client->torrentStart( $start );
				}
				Log::append( "Запрос на запуск раздач торрент-клиенту \"$cm\" отправлен ($q)." );
			}
			// останавливаем
			if( !empty( $hashes[$cm]['stop'] ) ) {
				$q = count( $hashes[$cm]['stop'] );
				$hashes[$cm]['stop'] = array_chunk( $hashes[$cm]['stop'], 100 );
				foreach( $hashes[$cm]['stop'] as $stop ) {
					$client->torrentStop( $stop );
				}
				Log::append( "Запрос на остановку раздач торрент-клиенту \"$cm\" отправлен ($q)." );
			}
		} else {
			Log::append( "Регулировка раздач не выполнена для торрент-клиента \"$cm\"." );
			continue;
		}
	}
	
}

// uTorrent 1.8.2 ~ Windows x32
class utorrent {
		
    private static $base = "http://%s:%s/gui/%s";
	
	public $host;
    public $port;
    public $login;
    public $paswd;
    public $comment;
    
    protected $token;
    protected $guid;
	
	public function __construct($host = "", $port = "", $login = "", $paswd = "", $comment = "") {
		$this->host = $host;
        $this->port = $port;
        $this->login = $login;
        $this->paswd = $paswd;
        $this->comment = $comment;
	}
	
	public function is_online() {
		return $this->getToken();
	}
	
	// получение токена
	private function getToken() {
		Log::append ( 'Попытка подключиться к торрент-клиенту "' . $this->comment . '"...' );
        $ch = curl_init();
        curl_setopt_array($ch, array(
	        CURLOPT_URL => sprintf(self::$base, $this->host, $this->port, 'token.html'),
	        CURLOPT_RETURNTRANSFER => true,
	        CURLOPT_USERPWD => $this->login.":".$this->paswd,
	        CURLOPT_HEADER => true
        ));
        $output = curl_exec($ch);
        if($output === false) {
			Log::append ( 'CURL ошибка: ' . curl_error($ch) );
            Log::append ( 'Проверьте в настройках правильность введённого IP-адреса и порта для доступа к торрент-клиенту.' );
			return false;
		}
        $info = curl_getinfo($ch);
        curl_close($ch);
        $headers = substr($output, 0, $info['header_size']);
        preg_match("|Set-Cookie: GUID=([^;]+);|i", $headers, $matches);
        if(!empty($matches)) {
            $this->guid = $matches[1];
		}
        preg_match('/<div id=\'token\'.+>(.*)<\/div>/', $output, $m);
        if(!empty($m)) {
	        $this->token = $m[1];
            return true;
        }
        Log::append ( 'Не удалось подключиться к веб-интерфейсу торрент-клиента.' );
		Log::append ( 'Проверьте в настройках правильность введённого логина и пароля для доступа к торрент-клиенту.' );
        return false;
    }
	
	// выполнение запроса
	private function makeRequest($request, $decode = true, $options = array()) {
        $request = preg_replace('/^\?/', '?token='.$this->token . '&', $request);
        $ch = curl_init();
        curl_setopt_array($ch, $options);
        curl_setopt_array($ch, array(
	        CURLOPT_URL => sprintf(self::$base, $this->host, $this->port, $request),
	        CURLOPT_RETURNTRANSFER => true,
	        CURLOPT_USERPWD => $this->login.":".$this->paswd,
	        CURLOPT_COOKIE => "GUID=".$this->guid
        ));
        $req = curl_exec($ch);
        if($req === false) {
			Log::append ( 'CURL ошибка: ' . curl_error($ch) );
			return false;
		}
        curl_close($ch);
        return ($decode ? json_decode($req, true) : $req);
    }
	
	// получение списка раздач
	public function getTorrents( $client = "" ) {
		Log::append ( 'Попытка получить данные о раздачах от торрент-клиента "' . $this->comment . '"...' );
		$json = $this->makeRequest("?list=1");
        foreach($json['torrents'] as $torrent)
		{
			$status = decbin($torrent[1]);
			// 0 - Started, 2 - Paused, 3 - Error, 4 - Checked, 7 - Loaded, 100% Downloads
			if( !$status{3} ) {
				$status = $status{0} && $status{4} && $torrent[4] == 1000
					// на паузе или стопе
					? !$status{2} && $status{7}
						? 1
						: -1
					: 0;
				$data[$torrent[0]]['status'] = $status;
				$data[$torrent[0]]['client'] = $client;
			}
		}
        return isset($data) ? $data : array();
	}
	
	// добавить торрент
	public function torrentAdd($filename, $savepath = "", $label = "", $savepath_subfolder = 0) {
		//~ $this->setSetting('dir_add_label', 1);
		$this->setSetting('dir_active_download_flag', true);
		foreach($filename as $file){
			if (!empty($savepath)) {
				$current_savepath = $savepath_subfolder ? $savepath . $file['id'] : $savepath;
				$this->setSetting('dir_active_download', urlencode($current_savepath));
			}
			$this->makeRequest("?action=add-url&s=".urlencode($file['filename']), false);
			usleep( 500000 );
		}
		if ( empty( $label ) ) {
			return;
		}
		$this->setProperties(array_column_common($filename, 'hash'), 'label', $label);
	}
	
	// изменение свойств торрента
	public function setProperties($hash, $property, $value) {
		$request = preg_replace('|^(.*)$|', "hash=$0&s=".$property."&v=".urlencode($value), $hash);
        $request = implode('&', $request);
        $this->makeRequest("?action=setprops&".$request, false);
    }
	
	// изменение настроек
	public function setSetting($setting, $value) {
        $this->makeRequest("?action=setsetting&s=".$setting."&v=".$value, false);
    }
    
    // "склеивание" параметров в строку
    private function paramImplode($glue, $param) {
        return $glue . implode($glue, is_array($param) ? $param : array($param));
    }
    
    // установка метки
    public function setLabel($hash, $label = "") {
		$this->setProperties($hash, 'label', $label);
	}
    
    // запуск раздач
    public function torrentStart($hash, $force = false) {
		$this->makeRequest("?action=".($force ? "forcestart" : "start").$this->paramImplode("&hash=", $hash), false);
	}
	
	// пауза раздач
	public function torrentPause($hash) {
        $this->makeRequest("?action=pause".$this->paramImplode("&hash=", $hash), false);
    }
	
	// проверить локальные данные раздач
	public function torrentRecheck($hash) {
        $this->makeRequest("?action=recheck".$this->paramImplode("&hash=", $hash), false);
    }
	
    // остановка раздач
    public function torrentStop($hash) {
		$this->makeRequest("?action=stop".$this->paramImplode("&hash=", $hash), false);
	}
	
    // удаление раздач
	public function torrentRemove($hash, $data = false) {
		 $this->makeRequest("?action=".($data ? "removedata" : "remove").$this->paramImplode("&hash=", $hash), false);
	}
	
}

// Transmission 2.82 ~ Linux x32 (режим демона)
class transmission {
	
	private static $base = "http://%s:%s/transmission/rpc";	
	
	public $host;
    public $port;
    public $login;
    public $paswd;
    public $comment;
    
    protected $sid;
	
	public function __construct($host = "", $port = "", $login = "", $paswd = "", $comment = "") {
		$this->host = $host;
        $this->port = $port;
        $this->login = $login;
        $this->paswd = $paswd;
        $this->comment = $comment;
	}
	
	public function is_online() {
		return $this->getSID();
	}
	
	// получение идентификатора сессии
	private function getSID() {
		Log::append ( 'Попытка подключиться к торрент-клиенту "' . $this->comment . '"...' );
        $ch = curl_init();
        curl_setopt_array($ch, array(
	        CURLOPT_URL => sprintf(self::$base, $this->host, $this->port),
	        CURLOPT_RETURNTRANSFER => true,
	        CURLOPT_USERPWD => $this->login.":".$this->paswd,
	        CURLOPT_HEADER => true
        ));
        $output = curl_exec($ch);
        if($output === false) {
			Log::append ( 'CURL ошибка: ' . curl_error($ch) );
			Log::append ( 'Проверьте в настройках правильность введённого IP-адреса и порта для доступа к торрент-клиенту.' );
			return false;
		}
        curl_close($ch);
        preg_match("|.*\r\n(X-Transmission-Session-Id: .*?)(\r\n.*)|", $output, $sid);
        if(!empty($sid)) {
            $this->sid = $sid[1];
            return true;
		}
		Log::append ( 'Не удалось подключиться к веб-интерфейсу торрент-клиента.' );
		Log::append ( 'Проверьте в настройках правильность введённого логина и пароля для доступа к торрент-клиенту.' );
        return false;
	}
	
	// выполнение запроса
	private function makeRequest($fields, $options = array()) {
        $ch = curl_init();
        curl_setopt_array($ch, $options);
        curl_setopt_array($ch, array(
	        CURLOPT_URL => sprintf(self::$base, $this->host, $this->port),
	        CURLOPT_RETURNTRANSFER => true,
	        CURLOPT_USERPWD => $this->login.":".$this->paswd,
	        CURLOPT_HTTPHEADER => array($this->sid),
	        CURLOPT_POSTFIELDS => $fields
        ));
		$i = 1; // номер попытки
		$n = 3; // количество попыток
		while( true ) {
	        $req = curl_exec($ch);
	        if( $req === false ) {
				Log::append ( 'CURL ошибка: ' . curl_error($ch) );
				curl_close($ch);
				return;
			}
			$req = json_decode( $req, true );
			if( $req['result'] != 'success' ) {
				if( empty($req['result']) && $i <= $n ) {
					Log::append ( "Повторная попытка $i/$n выполнить запрос." );
					sleep(10);
					$i++;
					continue;
				}
				$error = empty( $req['result'] )
					? "Неизвестная ошибка"
					: $req['result'];
				Log::append( "Error: $error" );
				curl_close($ch);
				return;
			}
			curl_close($ch);
			return $req;
		}
	}
	
	// получение списка раздач
	public function getTorrents( $client = "" ) {
		Log::append ( 'Попытка получить данные о раздачах от торрент-клиента "' . $this->comment . '"...' );
		$json = $this->makeRequest('{ "method" : "torrent-get", "arguments" : { "fields" : [ "hashString", "status", "error", "percentDone"] } }');
		if( !empty($json) ) {
			foreach( $json['arguments']['torrents'] as $torrent ) {
				if( empty( $torrent['error'] ) ) {
					// скачано 100%
					$status = $torrent['percentDone'] == 1
						// на паузе
						? $torrent['status'] == 0
							? -1
							: 1
						: 0;
					$hash = strtoupper( $torrent['hashString'] );
					$data[$hash]['status'] = $status;
					$data[$hash]['client'] = $client;
				}
			}
		}
        return isset($data) ? $data : array();
	}
	
	// добавить торрент
	public function torrentAdd($filename, $savepath = "", $label = "", $savepath_subfolder = 0) {
		$success = array();
		foreach($filename as $file){
			$current_savepath = $savepath_subfolder ? $savepath . $file['id'] : $savepath;
			$json = $this->makeRequest('{
				"method" : "torrent-add",
				"arguments" : {
					"filename" : "' . $file['filename'] . '",
					"paused" : "false"'
					. (!empty($savepath) ? ', "download-dir" : "' . quotemeta($current_savepath) . '"' : '') .
				'}
			}');
			if( !empty($json['arguments']) ) {
				if( !empty( $json['arguments']['torrent-added'] ) ) {
					$success[] = $json['arguments']['torrent-added']['hashString'];
				}
				if( !empty( $json['arguments']['torrent-duplicate'] ) ) {
					Log::append( "Warning: Эта раздача уже раздаётся в торрент-клиенте (${file['id']})." );
				}
			}
		}
		return array_map( function($e) {
			return strtoupper($e);
		}, $success );
	}
	
	// установка метки
    public function setLabel($hash, $label = "") {
		return 'Торрент-клиент не поддерживает установку меток.';
	}
    
    // запуск раздач
    public function torrentStart($hash, $force = false) {
		$json = $this->makeRequest(json_encode(array(
            'method' => ($force ? 'torrent-start-now' : 'torrent-start'),
            'arguments' => array('ids' => $hash)
        )));
	}
	
    // остановка раздач
    public function torrentStop($hash) {
		$json = $this->makeRequest(json_encode(array(
			'method' => 'torrent-stop',
			'arguments' => array('ids' => $hash)
		)));
	}
	
    // проверить локальные данные раздач
	public function torrentRecheck($hash) {
		$json = $this->makeRequest(json_encode(array(
            'method' => 'torrent-verify',
            'arguments' => array( 'ids' => $hash )
		)));
	}
	
    // удаление раздач
	public function torrentRemove($hash, $data = false) {
		$json = $this->makeRequest(json_encode(array(
            'method' => 'torrent-remove',
            'arguments' => array(
				'ids' => $hash,
				'delete-local-data' => $data
        ))));
	}
	
}

// Vuze 5.7.0.0/4 az3 [ plugin Web Remote 0.5.11 ] ~ Linux x32
class vuze {
	
	private static $base = "http://%s:%s/transmission/rpc";
	
	public $host;
    public $port;
    public $login;
    public $paswd;
    public $comment;
    
    protected $sid;
	
	public function __construct($host = "", $port = "", $login = "", $paswd = "", $comment = "") {
		$this->host = $host;
        $this->port = $port;
        $this->login = $login;
        $this->paswd = $paswd;
        $this->comment = $comment;
	}
	
	public function is_online() {
		return $this->getSID();
	}
	
	// получение идентификатора сессии
	private function getSID() {
		Log::append ( 'Попытка подключиться к торрент-клиенту "' . $this->comment . '"...' );
        $ch = curl_init();
        curl_setopt_array($ch, array(
	        CURLOPT_URL => sprintf(self::$base, $this->host, $this->port),
	        CURLOPT_RETURNTRANSFER => true,
	        CURLOPT_USERPWD => $this->login.":".$this->paswd,
	        CURLOPT_HEADER => true
        ));
        $output = curl_exec($ch);
        if($output === false) {
			Log::append ( 'CURL ошибка: ' . curl_error($ch) );
			Log::append ( 'Проверьте в настройках правильность введённого IP-адреса и порта для доступа к торрент-клиенту.' );
			return false;
		}
        curl_close($ch);
        preg_match("|.*\r\n(X-Transmission-Session-Id: .*?)(\r\n.*)|", $output, $sid);
        if(!empty($sid)) {
            $this->sid = $sid[1];
            return true;
		}
		Log::append ( 'Не удалось подключиться к веб-интерфейсу торрент-клиента.' );
		Log::append ( 'Проверьте в настройках правильность введённого логина и пароля для доступа к торрент-клиенту.' );
        return false;
	}
	
	// выполнение запроса
	private function makeRequest($fields, $options = array()) {
        $ch = curl_init();
        curl_setopt_array($ch, $options);
        curl_setopt_array($ch, array(
	        CURLOPT_URL => sprintf(self::$base, $this->host, $this->port),
	        CURLOPT_RETURNTRANSFER => true,
	        CURLOPT_USERPWD => $this->login.":".$this->paswd,
	        CURLOPT_HTTPHEADER => array($this->sid),
	        CURLOPT_POSTFIELDS => $fields
        ));
		$i = 1; // номер попытки
		$n = 3; // количество попыток
		while( true ) {
	        $req = curl_exec($ch);
	        if( $req === false ) {
				Log::append ( 'CURL ошибка: ' . curl_error($ch) );
				curl_close($ch);
				return;
			}
			$req = json_decode( $req, true );
			if( $req['result'] != 'success' ) {
				if( empty($req['result']) && $i <= $n ) {
					Log::append ( "Повторная попытка $i/$n выполнить запрос." );
					sleep(10);
					$i++;
					continue;
				}
				$error = empty( $req['result'] )
					? "Неизвестная ошибка"
					: $req['result'];
				Log::append( "Error: $error" );
				curl_close($ch);
				return;
			}
			curl_close($ch);
			return $req;
		}
	}
	
	// получение списка раздач
	public function getTorrents( $client = "" ) {
		Log::append ( 'Попытка получить данные о раздачах от торрент-клиента "' . $this->comment . '"...' );
		$json = $this->makeRequest('{ "method" : "torrent-get", "arguments" : { "fields" : [ "hashString", "status", "error", "percentDone"] } }');
		if( !empty( $json ) ) {
			foreach( $json['arguments']['torrents'] as $torrent ) {
				if( empty( $torrent['error'] ) ) {
					// скачано 100%
					$status = $torrent['percentDone'] == 1
						// на паузе
						? $torrent['status'] == 0
							? -1
							: 1
						: 0;
					$hash = strtoupper( $torrent['hashString'] );
					$data[$hash]['status'] = $status;
					$data[$hash]['client'] = $client;
				}
			}
		}
        return isset($data) ? $data : array();
	}
	
	// добавить торрент
	public function torrentAdd($filename, $savepath = "", $label = "", $savepath_subfolder = 0) {
		$success = array();
		foreach($filename as $file){
			$current_savepath = $savepath_subfolder ? $savepath . $file['id'] : $savepath;
			$json = $this->makeRequest('{
				"method" : "torrent-add",
				"arguments" : {
					"filename" : "' . $file['filename'] . '",
					"paused" : "false"'
					. (!empty($savepath) ? ', "download-dir" : "' . quotemeta($current_savepath) . '"' : '') .
				'}
			}');
			if( !empty($json['arguments']) ) {
				if( !empty( $json['arguments']['torrent-added'] ) ) {
					$success[] = $json['arguments']['torrent-added']['hashString'];
				}
				if( !empty( $json['arguments']['torrent-duplicate'] ) ) {
					Log::append( "Warning: Эта раздача уже раздаётся в торрент-клиенте (${file['id']})." );
				}
			}
		}
		return array_map( function($e) {
			return strtoupper($e);
		}, $success );
	}
	
	// установка метки
    public function setLabel($hash, $label = "") {
		return 'Торрент-клиент не поддерживает установку меток.';
	}
    
    // запуск раздач
    public function torrentStart($hash, $force = false) {
		$json = $this->makeRequest(json_encode(array(
            'method' => ($force ? 'torrent-start-now' : 'torrent-start'),
            'arguments' => array('ids' => $hash)
        )));
	}
	
    // остановка раздач
    public function torrentStop($hash) {
		$json = $this->makeRequest(json_encode(array(
			'method' => 'torrent-stop',
			'arguments' => array('ids' => $hash)
		)));
	}
	
	// проверить локальные данные раздач
	public function torrentRecheck($hash) {
		$json = $this->makeRequest(json_encode(array(
            'method' => 'torrent-verify',
            'arguments' => array( 'ids' => $hash )
		)));
	}
	
    // удаление раздач
	public function torrentRemove($hash, $data = false) {
		$json = $this->makeRequest(json_encode(array(
            'method' => 'torrent-remove',
            'arguments' => array(
				'ids' => $hash,
				'delete-local-data' => $data
        ))));
	}
	
}

// Deluge 1.3.6 [ plugin WebUi 0.1 ] ~ Linux x64
class deluge {
	
	private static $base = "http://%s:%s/json";
	
	public $host;
    public $port;
    public $login;
    public $paswd;
    public $comment;
    
    protected $sid;
	
	public function __construct($host = "", $port = "", $login = "", $paswd = "", $comment = "") {
		$this->host = $host;
        $this->port = $port;
        $this->login = $login;
        $this->paswd = $paswd;
        $this->comment = $comment;
	}
	
	public function is_online() {
		return $this->getSID();
	}
	
	// получение идентификатора сессии
	private function getSID() {
		Log::append ( 'Попытка подключиться к торрент-клиенту "' . $this->comment . '"...' );
        $ch = curl_init();
        curl_setopt_array($ch, array(
	        CURLOPT_URL => sprintf(self::$base, $this->host, $this->port),
	        CURLOPT_POSTFIELDS => '{ "method" : "auth.login" , "params" : [ "' . $this->paswd . '" ], "id" : 2 }',
	        CURLOPT_RETURNTRANSFER => true,
	        CURLOPT_ENCODING => 'gzip',
	        CURLOPT_HEADER => true,
	        CURLOPT_HTTPHEADER => array('Content-Type: application/json'),
        ));
        $output = curl_exec($ch);
        if($output === false) {
			Log::append ( 'CURL ошибка: ' . curl_error($ch) );
			Log::append ( 'Проверьте в настройках правильность введённого IP-адреса и порта для доступа к торрент-клиенту.' );
			return false;
		}
        curl_close($ch);
        preg_match("|Set-Cookie: ([^;]+);|i", $output, $sid);
        if(!empty($sid)) {
            $this->sid = $sid[1];
			$webUIIsConnected = $this->makeRequest(json_encode(array(
				'method' => 'web.connected',
				'params' => array(),
				'id' => 7
			)));
			if ( !$webUIIsConnected['result'] ) {
				$firstHost = $this->makeRequest(json_encode(array(
					'method' => 'web.get_hosts',
					'params' => array(),
					'id' => 7
				)));
				$firstHostStatus = $this->makeRequest(json_encode(array(
					'method' => 'web.get_host_status',
					'params' => array( $firstHost['result'][0][0] ),
					'id' => 7
				)));
				if ( $firstHostStatus['result'][3] === 'Offline' ) {
					Log::append('Deluge daemon offline.');
					return false;
				} elseif ( $firstHostStatus['result'][3] === 'Online' ) {
					$response = $this->makeRequest(json_encode(array(
						'method' => 'web.connect',
						'params' => array( $firstHost['result'][0][0] ),
						'id' => 7
					)));
					if ($response['error'] === null){
						Log::append('Подключение Deluge webUI к Deluge daemon прошло успешно.');
						return true;
					} else {
						Log::append('Подключение Deluge webUI к Deluge daemon не удалось.');
						return false;
					}
				}
			}
			return true;
		}
		Log::append ( 'Не удалось подключиться к веб-интерфейсу торрент-клиента.' );
		Log::append ( 'Проверьте в настройках правильность введённого логина и пароля для доступа к торрент-клиенту.' );
        return false;
	}
	
	// выполнение запроса
	private function makeRequest($fields, $decode = true, $options = array()) {
        $ch = curl_init();
        curl_setopt_array($ch, $options);
        curl_setopt_array($ch, array(
	        CURLOPT_URL => sprintf(self::$base, $this->host, $this->port),
	        CURLOPT_RETURNTRANSFER => true,
	        CURLOPT_COOKIE => $this->sid,
	        CURLOPT_ENCODING => 'gzip',
	        CURLOPT_POSTFIELDS => $fields,
	        CURLOPT_HTTPHEADER => array('Content-Type: application/json')
        ));
        $req = curl_exec($ch);
        if($req === false) {
			Log::append ( 'CURL ошибка: ' . curl_error($ch) );
			return false;
		}
        curl_close($ch);
        return ($decode ? json_decode($req, true) : $req);
	}
	
	// получение списка раздач
	public function getTorrents( $client = "" ) {
		Log::append ( 'Попытка получить данные о раздачах от торрент-клиента "' . $this->comment . '"...' );
		$json = $this->makeRequest('{ "method" : "web.update_ui" , "params" : [[ "paused", "message", "progress" ], {} ], "id" : 9 }');
        foreach($json['result']['torrents'] as $hash => $torrent)
		{
			if( $torrent['message'] == 'OK' ) {
				// скачано 100%
				$status = $torrent['progress'] == 100
					// на паузе
					? $torrent['paused']
						? -1
						: 1
					: 0;
				$hash = strtoupper( $hash );
				$data[$hash]['status'] = $status;
				$data[$hash]['client'] = $client;
			}
		}        
        return isset($data) ? $data : array();
	}
	
	// добавить торрент
	public function torrentAdd($filename, $savepath = "", $label = "", $savepath_subfolder = 0) {
		foreach($filename as $file){
			$current_savepath = $savepath_subfolder ? $savepath . $file['id'] : $savepath;
			$localpath = $this->torrentDownload($file['filename']);
			$json = $this->makeRequest(json_encode(array(
				"method" => "web.add_torrents",
				"params" => [[array(
					"path" => "$localpath",
					"options" => array( "download_location" => !empty($savepath) ? $current_savepath : '')
				)]],
				"id" => 1
			)));
			//~ return $json['result'] == 1 ? true : false;
		}
		if(empty($label)) return;
		sleep(round(count($filename) / 3) + 1); // < 3 дольше ожидание
		$this->setLabel(array_column_common($filename, 'hash'), $label);
	}
	
	// загрузить торрент локально
	private function torrentDownload($filename) {
		$json = $this->makeRequest('{
			"method" : "web.download_torrent_from_url",
			"params" : ["' . $filename . '"],
			"id" : 2
		}');
		return $json['result']; // return localpath
	}
	
	// включение плагинов
	private function enablePlugin($name = "") {
		$json = $this->makeRequest(json_encode(array( 'method' => 'core.enable_plugin', 'params' => array( $name ), 'id' => 3 )));
    }

	// добавить метку
	private function addLabel($label = ""){
		// не знаю как по-другому вытащить список уже имеющихся label
		$filters = $this->makeRequest(json_encode(array( 'method' => 'core.get_filter_tree', 'params' => array(), 'id' => 3 )));
		if(in_array($label, array_column_common($filters['result']['label'], 0))) return;
        $json = $this->makeRequest(json_encode(array( 'method' => 'label.add', 'params' => array( $label ), 'id' => 3 )));
    }
	
	// установка метки
    public function setLabel($hashes, $label = "") {
		$label = str_replace(' ', '_', $label);
		if(!preg_match("|^[aA-zZ0-9\-_]+$|", $label)) {
			Log::append('В названии метки присутствуют недопустимые символы.');
			return 'В названии метки присутствуют недопустимые символы.';
		}
		$this->enablePlugin('Label');
		$this->addLabel($label);
		foreach($hashes as $hash){
			$json = $this->makeRequest(json_encode(array( 'method' => 'label.set_torrent', 'params' => array( strtolower($hash), $label ), 'id' => 1 )));
		}
	}
    
    // запустить все
    public function startAll () {
		$json = $this->makeRequest(json_encode(array( 'method' => 'core.resume_all_torrents', 'params' => array(), 'id' => 7 )));
	}
    
    // запуск раздач
    public function torrentStart($hash, $force = false) {
		$json = $this->makeRequest(json_encode(array( 'method' => 'core.resume_torrent', 'params' =>  array(array_map('strtolower', $hash)), 'id' => 7 )));
	}

    // остановка раздач
    public function torrentStop($hash) {
		$json = $this->makeRequest(json_encode(array( 'method' => 'core.pause_torrent', 'params' => array(array_map('strtolower', $hash)), 'id' => 8 )));
	}
	
    // удаление раздач
	public function torrentRemove($hashes, $data = false) {
		foreach($hashes as $hash){
			$json = $this->makeRequest(json_encode(array( 'method' => 'core.remove_torrent', 'params' => array(strtolower($hash), $data), 'id' => 6 )));
		}
	}
	
	// проверить локальные данные раздач
	public function torrentRecheck($hash) {
        $json = $this->makeRequest(json_encode(array( 'method' => 'core.force_recheck', 'params' => array(array_map('strtolower', $hash)), 'id' => 5 )));
    }
	
}

// qBittorrent 3.3.{4,5,7} ~ Windows x32
class qbittorrent {
	
	private static $base = "http://%s:%s/%s";	
	
	public $host;
    public $port;
    public $login;
    public $paswd;
    public $comment;
    
    protected $sid;
    protected $api;
	
	public function __construct($host = "", $port = "", $login = "", $paswd = "", $comment = "") {
		$this->host = $host;
        $this->port = $port;
        $this->login = $login;
        $this->paswd = $paswd;
        $this->comment = $comment;
	}
	
	public function is_online() {
		if (!$this->getSID()) return false;
        if (!$this->version_api()) {
			Log::append ( 'Версия торрент-клиента не поддерживается.' );
			return false;
		}
        return true;
	}
	
	// версия API
	private function version_api() {
		$this->api = $this->makeRequest("", 'version/api', true);
		return $this->api < 7 ? false : true;
	}
	
	// получение идентификатора сессии
	private function getSID() {
		Log::append ( 'Попытка подключиться к торрент-клиенту "' . $this->comment . '"...' );
        $ch = curl_init();
        curl_setopt_array($ch, array(
	        CURLOPT_URL => sprintf(self::$base, $this->host, $this->port, 'login'),
	        CURLOPT_RETURNTRANSFER => true,
	        CURLOPT_POSTFIELDS => http_build_query(array(
				'username' => "$this->login", 'password' => "$this->paswd"
			)),
	        CURLOPT_HEADER => true
        ));
        $output = curl_exec($ch);
        if($output === false) {
			Log::append ( 'CURL ошибка: ' . curl_error($ch) );
			Log::append ( 'Проверьте в настройках правильность введённого IP-адреса и порта для доступа к торрент-клиенту.' );
			return false;
		}
        curl_close($ch);
        preg_match("|Set-Cookie: ([^;]+);|i", $output, $sid);
        if(!empty($sid)) {
            $this->sid = $sid[1];
            return true;
		}
		Log::append ( 'Не удалось подключиться к веб-интерфейсу торрент-клиента.' );
		Log::append ( 'Проверьте в настройках правильность введённого логина и пароля для доступа к торрент-клиенту.' );
        return false;
	}
	
	// выполнение запроса
	private function makeRequest($fields, $url = "", $decode = true, $options = array()) {
        $ch = curl_init();
        curl_setopt_array($ch, $options);
        curl_setopt_array($ch, array(
	        CURLOPT_URL => sprintf(self::$base, $this->host, $this->port, $url),
	        CURLOPT_RETURNTRANSFER => true,
	        CURLOPT_COOKIE => $this->sid,
	        CURLOPT_POSTFIELDS => $fields
        ));
        $req = curl_exec($ch);
        if($req === false) {
			Log::append ( 'CURL ошибка: ' . curl_error($ch) );
			return false;
		}
        curl_close($ch);
        return ($decode ? json_decode($req, true) : $req);
	}
	
	// получение списка раздач
	public function getTorrents( $client = "" ) {
		Log::append ( 'Попытка получить данные о раздачах от торрент-клиента "' . $this->comment . '"...' );
		$json = $this->makeRequest('', 'query/torrents');
        foreach($json as $torrent)
		{
			if( $torrent['state'] != 'error' ) {
				// скачано 100%
				$status = $torrent['progress'] == 1
					// на паузе
					? $torrent['state'] == 'pausedUP'
						? -1
						: 1
					: 0;
				$hash = strtoupper( $torrent['hash'] );
				$data[$hash]['status'] = $status;
				$data[$hash]['client'] = $client;
			}
		}
        return isset($data) ? $data : array();
	}
	
	// добавить торрент
	public function torrentAdd($filename, $savepath = "", $label = "", $savepath_subfolder = 0) {
		foreach($filename as $file) {
			$current_savepath = $savepath_subfolder ? $savepath . $file['id'] : $savepath;
			$fields = http_build_query(array(
				'urls' => $file['filename'], 'savepath' => !empty($savepath) ? $current_savepath : '', 'cookie' => $this->sid, 'label' => $label, 'category' => $label
			), '', '&', PHP_QUERY_RFC3986);
			$this->makeRequest($fields, 'command/download', false);
		}
	}
	
	// установка метки
    public function setLabel($hash, $label = "") {
		$hash = array_map(function($hash){ return strtolower($hash); }, $hash);
		if ( $this->api < 10 ) {
			$fields = http_build_query(array(
				'hashes' => implode('|', $hash), 'label' => $label
	        ), '', '&', PHP_QUERY_RFC3986);
			$this->makeRequest($fields, 'command/setLabel', false);
		} else {
			$fields = http_build_query(array(
				'hashes' => implode('|', $hash), 'category' => $label
	        ), '', '&', PHP_QUERY_RFC3986);
			$this->makeRequest($fields, 'command/setCategory', false);
		}
	}
    
    // запустить все
    public function startAll () {
		$this->makeRequest("", 'command/resumeAll', false);
	}
    
    // запуск раздач
    public function torrentStart($hash, $force = false) {
		foreach($hash as $hash){
			$this->makeRequest('hash='.strtolower($hash), 'command/resume', false);
		}
	}
	
    // остановка раздач
    public function torrentStop($hash) {
		foreach($hash as $hash){
			$this->makeRequest('hash='.strtolower($hash), 'command/pause', false);
		}
	}
	
    // удаление раздач
	public function torrentRemove($hash, $data = false) {
		$hash = array_map(function($hash){ return strtolower($hash); }, $hash);
		$this->makeRequest('hashes='.implode('|', $hash), 'command/delete' . ($data ? 'Perm' : ''), false);
	}
	
	// проверить локальные данные раздач
	public function torrentRecheck($hash) {
		foreach($hash as $hash){
			$this->makeRequest('hash='.strtolower($hash), 'command/recheck', false);
		}
	}
	
}

// KTorrent 4.3.1 ~ Linux x64
class ktorrent {
	
	private static $base = "http://%s:%s/%s";
	
	public $host;
    public $port;
    public $login;
    public $paswd;
    public $comment;
    
    protected $challenge;
    protected $sid;
	
	public function __construct($host = "", $port = "", $login = "", $paswd = "", $comment = "") {
		$this->host = $host;
        $this->port = $port;
        $this->login = $login;
        $this->paswd = $paswd;
        $this->comment = $comment;
	}
	
	public function is_online() {
		return $this->getChallenge();
	}
	
	// получение challenge
	private function getChallenge() {
		Log::append ( 'Попытка подключиться к торрент-клиенту "' . $this->comment . '"...' );
        $ch = curl_init();
        curl_setopt_array($ch, array(
	        CURLOPT_URL => sprintf(self::$base, $this->host, $this->port, 'login/challenge.xml'),
	        CURLOPT_RETURNTRANSFER => true,
	        CURLOPT_POSTFIELDS => http_build_query(array(
				'username' => "$this->login", 'password' => "$this->paswd"
			)),
	        CURLOPT_HEADER => true
        ));
        $output = curl_exec($ch);
        if($output === false) {
			Log::append ( 'CURL ошибка: ' . curl_error($ch) );
			Log::append ( 'Проверьте в настройках правильность введённого IP-адреса и порта для доступа к торрент-клиенту.' );
			return false;
		}
        curl_close($ch);
        preg_match('|<challenge>(.*)</challenge>|sei', $output, $challenge);
        if(!empty($challenge)) {
            $this->challenge = sha1($challenge[1] . $this->paswd);;
            return $this->getSID();
		}
		Log::append ( 'Не удалось подключиться к веб-интерфейсу торрент-клиента.' );
		Log::append ( 'Проверьте в настройках правильность введённого логина и пароля для доступа к торрент-клиенту.' );
        return false;
	}
	
	// получение идентификатора сессии
	private function getSID() {
        $ch = curl_init();
        curl_setopt_array($ch, array(
	        CURLOPT_URL => sprintf(self::$base, $this->host, $this->port, 'login?page=interface.html'),
	        CURLOPT_RETURNTRANSFER => true,
	        CURLOPT_POSTFIELDS => http_build_query(array(
				'username' => "$this->login", 'challenge' => "$this->challenge", 'Login' => 'Sign in'
			)),
	        CURLOPT_HEADER => true
        ));
        $output = curl_exec($ch);
        if($output === false) {
			Log::append ( 'CURL ошибка: ' . curl_error($ch) );
			Log::append ( 'Проверьте в настройках правильность введённого IP-адреса и порта для доступа к торрент-клиенту.' );
			return false;
		}
        curl_close($ch);
        preg_match("|Set-Cookie: ([^;]+)|i", $output, $sid);
        if(!empty($sid)) {
            $this->sid = $sid[1];
            return true;
		}
		Log::append ( 'Не удалось подключиться к веб-интерфейсу торрент-клиента.' );
		Log::append ( 'Проверьте в настройках правильность введённого логина и пароля для доступа к торрент-клиенту.' );
        return false;
	}
	
	// выполнение запроса
	private function makeRequest($url, $decode = true, $options = array(), $xml = false) {
        $ch = curl_init();
        curl_setopt_array($ch, $options);
        curl_setopt_array($ch, array(
	        CURLOPT_URL => sprintf(self::$base, $this->host, $this->port, $url),
	        CURLOPT_RETURNTRANSFER => true,
	        CURLOPT_COOKIE => $this->sid
        ));
        $req = curl_exec($ch);
        if($req === false) {
			Log::append ( 'CURL ошибка: ' . curl_error($ch) );
			return false;
		}
        curl_close($ch);
        if($xml){
			$req = new SimpleXMLElement($req);
	        $req = json_encode($req);
		}
        return ($decode ? json_decode($req, true) : $req);
	}
	
	// получение списка раздач
	public function getTorrents( $client = "", $full = false ) {
		Log::append ( 'Попытка получить данные о раздачах от торрент-клиента "' . $this->comment . '"...' );
		$json = $this->makeRequest('data/torrents.xml', true, array(CURLOPT_POST => false), true);
		// вывод отличается, если в клиенте только одна раздача
        if($full) return $json;
        foreach($json['torrent'] as $torrent)
		{
			if( $torrent['status'] != 'Ошибка' ) {
				// скачано 100%
				$status = $torrent['percentage'] == 100
					// на паузе
					? $torrent['status'] == 'Пауза' //Приостановлен
						? -1
						: 1
					: 0;
				$hash = strtoupper( $torrent['info_hash'] );
				$data[$hash]['status'] = $status;
				$data[$hash]['client'] = $client;
			}
		}
        return isset($data) ? $data : array();
	}
	
	// добавить торрент
	public function torrentAdd($filename, $savepath = "", $label = "") {
		foreach($filename as $filename){
			$json = $this->makeRequest('action?load_torrent=' . $filename['filename'], false); // 200 OK
		}
	}
	
	// установка метки
    public function setLabel($hash, $label = "") {
		return 'Торрент-клиент не поддерживает установку меток.';
	}
    
    // запустить все
    public function startAll () {
		$json = $this->makeRequest('action?startall=true');
	}
    
    // запуск раздач
    public function torrentStart($hash, $force = false) {
		$torrents = $this->getTorrents("", true);
        $hashes = array_flip(array_column_common($torrents['torrent'], 'info_hash'));
        foreach($hash as $hash){
            if(isset($hashes[strtolower($hash)]))
                $json = $this->makeRequest('action?start=' . $hashes[strtolower($hash)]);
        }
	}
	
    // остановка раздач
    public function torrentStop($hash) {
		$torrents = $this->getTorrents("", true);
        $hashes = array_flip(array_column_common($torrents['torrent'], 'info_hash'));
        foreach($hash as $hash){
            if(isset($hashes[strtolower($hash)]))
                $json = $this->makeRequest('action?stop=' . $hashes[strtolower($hash)]);
        }
	}
	
    // удаление раздач
	public function torrentRemove($hash, $data = false) {
		$torrents = $this->getTorrents("", true);
        $hashes = array_flip(array_column_common($torrents['torrent'], 'info_hash'));
        foreach($hash as $hash){
            if(isset($hashes[strtolower($hash)]))
                $json = $this->makeRequest('action?remove=' . $hashes[strtolower($hash)]);
        }
	}
	
	// проверить локальные данные раздач
	public function torrentRecheck($hash) {
		return 'Торрент-клиент не поддерживает проверку локальных данных.';
	}
	
}

// rTorrent 0.9.x ~ Linux
// Added by: advers222@ya.ru
class rtorrent {
    // предлагается вводить ссылку полностью в веб интерфейсе
    // потому что при нескольких клиентах вполне может меняться последняя часть (RPC2)
    // http://localhost/RPC2
    private static $base = "http://%s";

    public $host;
    public $port;
    public $login;
    public $paswd;
    public $comment;

    protected $token;
    protected $guid;

    public function __construct($host = "", $port = "", $login = "", $paswd = "", $comment = "") {
        $this->host = $host;
        $this->port = $port;
        $this->login = $login;
        $this->paswd = $paswd;
        $this->comment = $comment;
    }

    public function is_online() {
        return $this->makeRequest("get_name") ? true : false;
    }

    // выполнение запроса
    function makeRequest($cmd, $param = null) {
        // XML RPC запрос
        $request = xmlrpc_encode_request($cmd, $param);
        $header[] = "Content-type: text/xml";
        $header[] = "Content-length: ".strlen($request);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, sprintf(self::$base, $this->host));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        //curl_setopt($ch, CURLOPT_TIMEOUT, 1);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $header);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $request);

        $data = curl_exec($ch);
        if (curl_errno($ch)) {
            Log::append ( 'CURL ошибка: ' . curl_error($ch) );
            return false;
        }
        curl_close($ch);
        // Грязный хак для приведения ответа XML RPC к понятному для PHP
        return xmlrpc_decode(str_replace('i8>', 'i4>', $data));
    }

    // получение списка раздач
    public function getTorrents( $client = "" ) {
        Log::append ( 'Попытка получить данные о раздачах от торрент-клиента "' . $this->comment . '"...' );
        $res = $this->makeRequest("d.multicall", array("main", "d.get_hash=", "d.get_state=", "d.get_complete=") );
        // ответ в формате array(HASH, STATE active/stopped, COMPLETED)
        foreach($res as $torrent)
        {
            // $status:
            //		0 - Не скачано
            //		1 - Скачано и активно
            //		-1 - Скачано и остановлено
            $status = $torrent[2]
                // на паузе или стопе
                ? $torrent[1]
                    ? 1
                    : -1
                : 0;
            $data[$torrent[0]]['status'] = $status;
            $data[$torrent[0]]['client'] = $client;
        }
        return isset($data) ? $data : array();
    }

    // добавить торрент
    public function torrentAdd($filename, $savepath = "", $label = "") {
        // TODO: Придумать, как установить метку для раздачи.
        // TODO: Скорее всего не сработает установка метки потому что на момент
        // TODO: запроса торрент еще не будет добавлен :-/
        $result_ok = 0;
        $result_fail = 0;
        foreach($filename as $fn){
            $this->makeRequest("load_start", $fn['filename']) === false ? $result_fail += 1 : $result_ok += 1;
            if ($label) {
                $this->makeRequest("d.set_custom1", array($fn["hash"], $label) );
            }
        }
        Log::append ( 'Добавлено раздач успешно: ' . $result_ok . '. С ошибкой: ' . $result_fail);
    }

    // установка метки
    public function setLabel($hash, $label = "") {
        $result_ok = 0;
        $result_fail = 0;
        foreach ($hash as $hash) {
            $this->makeRequest("d.set_custom1", array($hash, $label) ) === false ? $result_fail += 1 : $result_ok += 1;
        }
        Log::append ( 'Установлено меток успешно: ' . $result_ok . '. С ошибкой: ' . $result_fail);
    }

    // запуск раздач
    public function torrentStart($hash, $force = false) {
        $result_ok = 0;
        $result_fail = 0;
        foreach ($hash as $hash) {
            $this->makeRequest("d.start", $hash) === false ? $result_fail += 1 : $result_ok += 1;
        }
        Log::append ( 'Запущено раздач успешно: ' . $result_ok . '. С ошибкой: ' . $result_fail);
    }

    // пауза раздач
    public function torrentPause($hash) {
        $result_ok = 0;
        $result_fail = 0;
        foreach ($hash as $hash) {
            $this->makeRequest("d.pause", $hash) === false ? $result_fail += 1 : $result_ok += 1;
        }
        Log::append ( 'Приостановлено раздач успешно: ' . $result_ok . '. С ошибкой: ' . $result_fail);
    }

    // проверить локальные данные раздач
    public function torrentRecheck($hash) {
        $result_ok = 0;
        $result_fail = 0;
        foreach ($hash as $hash) {
            $this->makeRequest("d.check_hash", $hash) === false ? $result_fail += 1 : $result_ok += 1;
        }
        Log::append ( 'Проверка файлов запущена успешно: ' . $result_ok . '. С ошибкой: ' . $result_fail);
    }

    // остановка раздач
    public function torrentStop($hash) {
        $result_ok = 0;
        $result_fail = 0;
        foreach ($hash as $hash) {
            $this->makeRequest("d.stop", $hash) === false ? $result_fail += 1 : $result_ok += 1;
        }
        Log::append ( 'Остановлено раздач успешно: ' . $result_ok . '. С ошибкой: ' . $result_fail);
    }

    // удаление раздач
    public function torrentRemove($hash, $data = false) {
        // FIXME: Не знаю стоит ли делать удаление, мне пока не нужно и страшно. Удаленного не вернешь :))
        Log::append ("Удаление раздачи заблокировано.");
    }

}

?>
