<?php

function get_tor_client_data($tcs, &$log) {
	
	$log .= date("H:i:s") . ' Получение данных от торрент-клиентов...<br />';
	$log .= date("H:i:s") . ' Количество торрент-клиентов: ' . count($tcs) . '.<br />';
	$tc_topics = array();
	
	foreach($tcs as $cm => $tc) {
		$client = new $tc['cl']($tc['ht'], $tc['pt'], $tc['lg'], $tc['pw']);
		if($client->is_online()) {
			$tmp = $client->getTorrents();
			$tc_topics += $tmp;
		} else $tmp = null;
		$log .= str_replace('{cm}', $tc['cm'], $client->log);
		$log .= date("H:i:s") . ' ' . $tc['cm'] . ' (' . $tc['cl'] .
			') - получено раздач: ' . count($tmp) . '<br />';
	}
	
	// array ( [hash] => ( 'status' => status, 'client' => comment ) )
	return $tc_topics;
	
}

// uTorrent 1.8.2 ~ Windows x32
class utorrent {
		
    private static $base = "http://%s:%s/gui/%s";
	
	public $log;
	public $host;
    public $port;
    public $login;
    public $paswd;
    
    protected $token;
    protected $guid;
	
	public function __construct($host = "", $port = "", $login = "", $paswd = "") {
		$this->host = $host;
        $this->port = $port;
        $this->login = $login;
        $this->paswd = $paswd;
	}
	
	public function is_online() {
		if (!$this->getToken()) {
            $this->log .= date("H:i:s") . ' Произошла ошибка при подключении к торрент-клиенту "{cm}".<br />';
            return false;
        }
        return true;
	}
	
	// получение токена
	private function getToken() {
		$this->log .= date("H:i:s") . ' Попытка подключиться к торрент-клиенту "{cm}"...<br />';
        $ch = curl_init();
        curl_setopt_array($ch, array(
	        CURLOPT_URL => sprintf(self::$base, $this->host, $this->port, 'token.html'),
	        CURLOPT_RETURNTRANSFER => true,
	        CURLOPT_USERPWD => $this->login.":".$this->paswd,
	        CURLOPT_HEADER => true
        ));
        $output = curl_exec($ch);
        if($output === false) {
			$this->log .= date("H:i:s") . ' CURL ошибка: ' . curl_error($ch) . '<br />';
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
			$this->log .= date("H:i:s") . ' CURL ошибка: ' . curl_error($ch) . '<br />';
			return false;
		}
        curl_close($ch);
        return ($decode ? json_decode($req, true) : $req);
    }
	
	// получение списка раздач
	public function getTorrents() {
		$this->log .= date("H:i:s") . ' Попытка получить данные о раздачах от торрент-клиента "{cm}"...<br />';
		$json = $this->makeRequest("?list=1");
        foreach($json['torrents'] as $torrent)
		{
			$status = decbin($torrent[1]);
			// 100%, загружено, проверено, нет ошибок
			if( $torrent[4] == 1000 &&
				$status{0} == 1 &&
				$status{4} == 1 &&
				$status{3} == 0
			) $status = 1;
			else $status = 0;
			$data[$torrent[0]]['status'] = $status;
			//~ $data[$torrent[0]]['client'] = '';
		}
        return $data;
	}
	
	// добавить торрент
	public function torrentAdd($filename, $savepath = "", $label = "") {
		//~ $this->setSetting('dir_add_label', 1);
		$this->setSetting('dir_active_download', urlencode($savepath));
		foreach($filename as $filename){
			$json = $this->makeRequest("?action=add-url&s=".urlencode($filename), false);
		}
	}
	
	// изменение свойств торрента
	public function setProperties($hash, $property, $value) {
        $this->makeRequest("?action=setprops&hash=".$hash."&s=".$property."&v=".$value, false);
    }
	
	// изменение настроек
	public function setSetting($setting, $value) {
        $this->makeRequest("?action=setsetting&s=".$setting."&v=".$value, false);
    }
}

// Transmission 2.82 ~ Linux x32 (режим демона)
class transmission {
	
	private static $base = "http://%s:%s/transmission/rpc";	
	
	public $log;
	public $host;
    public $port;
    public $login;
    public $paswd;
    
    protected $sid;
	
	public function __construct($host = "", $port = "", $login = "", $paswd = "") {
		$this->host = $host;
        $this->port = $port;
        $this->login = $login;
        $this->paswd = $paswd;
	}
	
	public function is_online() {
		if (!$this->getSID()) {
            $this->log .= date("H:i:s") . ' Произошла ошибка при подключении к торрент-клиенту "{cm}".<br />';
            return false;
        }
        return true;
	}
	
	// получение идентификатора сессии
	private function getSID() {
		$this->log .= date("H:i:s") . ' Попытка подключиться к торрент-клиенту "{cm}"...<br />';
        $ch = curl_init();
        curl_setopt_array($ch, array(
	        CURLOPT_URL => sprintf(self::$base, $this->host, $this->port),
	        CURLOPT_RETURNTRANSFER => true,
	        CURLOPT_USERPWD => $this->login.":".$this->paswd,
	        CURLOPT_HEADER => true
        ));
        $output = curl_exec($ch);
        if($output === false) {
			$this->log .= date("H:i:s") . ' CURL ошибка: ' . curl_error($ch) . '<br />';
			return false;
		}
        curl_close($ch);
        preg_match("|.*\r\n(X-Transmission-Session-Id: .*?)(\r\n.*)|", $output, $sid);
        if(!empty($sid)) {
            $this->sid = $sid[1];
            return true;
		}
        return false;
	}
	
	// выполнение запроса
	private function makeRequest($fields, $decode = true, $options = array()) {
        $ch = curl_init();
        curl_setopt_array($ch, $options);
        curl_setopt_array($ch, array(
	        CURLOPT_URL => sprintf(self::$base, $this->host, $this->port),
	        CURLOPT_RETURNTRANSFER => true,
	        CURLOPT_USERPWD => $this->login.":".$this->paswd,
	        CURLOPT_HTTPHEADER => array($this->sid),
	        CURLOPT_POSTFIELDS => $fields
        ));
        $req = curl_exec($ch);
        if($req === false) {
			$this->log .= date("H:i:s") . ' CURL ошибка: ' . curl_error($ch) . '<br />';
			return false;
		}
        curl_close($ch);
        return ($decode ? json_decode($req, true) : $req);
	}
	
	// получение списка раздач
	public function getTorrents() {
		$this->log .= date("H:i:s") . ' Попытка получить данные о раздачах от торрент-клиента "{cm}"...<br />';
		$json = $this->makeRequest('{ "method" : "torrent-get", "arguments" : { "fields" : [ "hashString", "name", "error", "percentDone"] } }');
        foreach($json['arguments']['torrents'] as $torrent)
		{
			// скачано 100%, нет ошибок
			if(	$torrent['percentDone'] == 1 && $torrent['error'] == 0)
				$status = 1;
			else
				$status = 0;
			$data[strtoupper($torrent['hashString'])]['status'] = $status;
			//~ $data[strtoupper($torrent['hashString'])]['client'] = '';
		}
        return $data;
	}
	
	// добавить торрент
	public function torrentAdd($filename, $savepath = "", $label = "") {
		foreach($filename as $filename){
			$json = $this->makeRequest('{
				"method" : "torrent-add",
				"arguments" : {
					"filename" : "' . $filename . '",
					"download-dir" : "' . quotemeta($savepath) . '",
					"paused" : "false"
				}
			}', true);
			//~ return $json['result']; // success
		}
	}
	
}

// Vuze 5.7.0.0/4 az3 [ plugin Web Remote 0.5.11 ] ~ Linux x32
class vuze {
	
	private static $base = "http://%s:%s/transmission/rpc";
	
	public $log;
	public $host;
    public $port;
    public $login;
    public $paswd;
    
    protected $sid;
	
	public function __construct($host = "", $port = "", $login = "", $paswd = "") {
		$this->host = $host;
        $this->port = $port;
        $this->login = $login;
        $this->paswd = $paswd;
	}
	
	public function is_online() {
		if (!$this->getSID()) {
            $this->log .= date("H:i:s") . ' Произошла ошибка при подключении к торрент-клиенту "{cm}".<br />';
            return false;
        }
        return true;
	}
	
	// получение идентификатора сессии
	private function getSID() {
		$this->log .= date("H:i:s") . ' Попытка подключиться к торрент-клиенту "{cm}"...<br />';
        $ch = curl_init();
        curl_setopt_array($ch, array(
	        CURLOPT_URL => sprintf(self::$base, $this->host, $this->port),
	        CURLOPT_RETURNTRANSFER => true,
	        CURLOPT_USERPWD => $this->login.":".$this->paswd,
	        CURLOPT_HEADER => true
        ));
        $output = curl_exec($ch);
        if($output === false) {
			$this->log .= date("H:i:s") . ' CURL ошибка: ' . curl_error($ch) . '<br />';
			return false;
		}
        curl_close($ch);
        preg_match("|.*\r\n(X-Transmission-Session-Id: .*?)(\r\n.*)|", $output, $sid);
        if(!empty($sid)) {
            $this->sid = $sid[1];
            return true;
		}
        return false;
	}
	
	// выполнение запроса
	private function makeRequest($fields, $decode = true, $options = array()) {
        $ch = curl_init();
        curl_setopt_array($ch, $options);
        curl_setopt_array($ch, array(
	        CURLOPT_URL => sprintf(self::$base, $this->host, $this->port),
	        CURLOPT_RETURNTRANSFER => true,
	        CURLOPT_USERPWD => $this->login.":".$this->paswd,
	        CURLOPT_HTTPHEADER => array($this->sid),
	        CURLOPT_POSTFIELDS => $fields
        ));
        $req = curl_exec($ch);
        if($req === false) {
			$this->log .= date("H:i:s") . ' CURL ошибка: ' . curl_error($ch) . '<br />';
			return false;
		}
        curl_close($ch);
        return ($decode ? json_decode($req, true) : $req);
	}
	
	// получение списка раздач
	public function getTorrents() {
		$this->log .= date("H:i:s") . ' Попытка получить данные о раздачах от торрент-клиента "{cm}"...<br />';
		$json = $this->makeRequest('{ "method" : "torrent-get", "arguments" : { "fields" : [ "hashString", "name", "error", "percentDone"] } }');
        foreach($json['arguments']['torrents'] as $torrent)
		{
			// скачано 100%, нет ошибок
			if(	$torrent['percentDone'] ==	1 && $torrent['error'] == 0)
				$status = 1;
			else
				$status = 0;
			$data[strtoupper($torrent['hashString'])]['status'] = $status;
			//~ $data[strtoupper($torrent['hashString'])]['client'] = '';
		}
        return $data;
	}
	
	// добавить торрент
	public function torrentAdd($filename, $savepath = "", $label = "") {
		foreach($filename as $filename){
			$json = $this->makeRequest('{
				"method" : "torrent-add",
				"arguments" : {
					"filename" : "' . $filename . '",
					"download-dir" : "' . quotemeta($savepath) . '",
					"paused" : "false"
				}
			}', true);
			//~ retutn $json['result']; // success
		}
	}
	
}

// Deluge 1.3.6 [ plugin WebUi 0.1 ] ~ Linux x64
class deluge {
	
	private static $base = "http://%s:%s/json";
	
	public $log;
	public $host;
    public $port;
    public $login;
    public $paswd;
    
    protected $sid;
	
	public function __construct($host = "", $port = "", $login = "", $paswd = "") {
		$this->host = $host;
        $this->port = $port;
        $this->login = $login;
        $this->paswd = $paswd;
	}
	
	public function is_online() {
		if (!$this->getSID()) {
            $this->log .= date("H:i:s") . ' Произошла ошибка при подключении к торрент-клиенту "{cm}".<br />';
            return false;
        }
        return true;
	}
	
	// получение идентификатора сессии
	private function getSID() {
		$this->log .= date("H:i:s") . ' Попытка подключиться к торрент-клиенту "{cm}"...<br />';
        $ch = curl_init();
        curl_setopt_array($ch, array(
	        CURLOPT_URL => sprintf(self::$base, $this->host, $this->port),
	        CURLOPT_POSTFIELDS => '{ "method" : "auth.login" , "params" : [ "' . $this->paswd . '" ], "id" : 2 }',
	        CURLOPT_RETURNTRANSFER => true,
	        CURLOPT_ENCODING => 'gzip',
	        CURLOPT_HEADER => true
        ));
        $output = curl_exec($ch);
        if($output === false) {
			$this->log .= date("H:i:s") . ' CURL ошибка: ' . curl_error($ch) . '<br />';
			return false;
		}
        curl_close($ch);
        preg_match("|Set-Cookie: ([^;]+);|i", $output, $sid);
        if(!empty($sid)) {
            $this->sid = $sid[1];
            return true;
		}
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
	        CURLOPT_POSTFIELDS => $fields
        ));
        $req = curl_exec($ch);
        if($req === false) {
			$this->log .= date("H:i:s") . ' CURL ошибка: ' . curl_error($ch) . '<br />';
			return false;
		}
        curl_close($ch);
        return ($decode ? json_decode($req, true) : $req);
	}
	
	// получение списка раздач
	public function getTorrents() {
		$this->log .= date("H:i:s") . ' Попытка получить данные о раздачах от торрент-клиента "{cm}"...<br />';
		$json = $this->makeRequest('{ "method" : "web.update_ui" , "params" : [[ "name", "message", "progress" ], {} ], "id" : 9 }');
        foreach($json['result']['torrents'] as $hash => $torrent)
		{
			// скачано 100%, нет ошибок
			if(	$torrent['progress'] ==	100 && $torrent['message'] == 'OK')
				$status = 1;
			else
				$status = 0;
			$data[strtoupper($hash)]['status'] = $status;
			//~ $data[strtoupper($hash)]['client'] = '';
		}        
        return $data;
	}
	
	// добавить торрент
	public function torrentAdd($filename, $savepath = "", $label = "") {
		foreach($filename as $filename){
			$localpath = $this->torrentDownload($filename);
			$json = $this->makeRequest('{ "method" : "web.add_torrents" , "params" : [[{ "path" : "' . $localpath . '", "options" : { "download_location" : "'.$savepath.'" }}]], "id" : 1 }');
			//~ return $json['result'] == 1 ? true : false;
		}
	}
	
	// загрузить торрент локально
	private function torrentDownload($filename) {
		$json = $this->makeRequest('{ "method" : "web.download_torrent_from_url" , "params" : ["' . $filename . '"], "id" : 2 }');
		return $json['result']; // return localpath
	}
	
}

// qBittorrent 3.4.4 ~ Windows x32
class qbittorrent {
	
	private static $base = "http://%s:%s/%s";	
	
	public $log;
	public $host;
    public $port;
    public $login;
    public $paswd;
    
    protected $sid;
	
	public function __construct($host = "", $port = "", $login = "", $paswd = "") {
		$this->host = $host;
        $this->port = $port;
        $this->login = $login;
        $this->paswd = $paswd;
	}
	
	public function is_online() {
		if (!$this->getSID()) {
            $this->log .= date("H:i:s") . ' Произошла ошибка при подключении к торрент-клиенту "{cm}".<br />';
            return false;
        }
        return true;
	}
	
	// получение идентификатора сессии
	private function getSID() {
		$this->log .= date("H:i:s") . ' Попытка подключиться к торрент-клиенту "{cm}"...<br />';
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
			$this->log .= date("H:i:s") . ' CURL ошибка: ' . curl_error($ch) . '<br />';
			return false;
		}
        curl_close($ch);
        preg_match("|Set-Cookie: ([^;]+);|i", $output, $sid);
        if(!empty($sid)) {
            $this->sid = $sid[1];
            return true;
		}
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
			$this->log .= date("H:i:s") . ' CURL ошибка: ' . curl_error($ch) . '<br />';
			return false;
		}
        curl_close($ch);
        return ($decode ? json_decode($req, true) : $req);
	}
	
	// получение списка раздач
	public function getTorrents() {
		$this->log .= date("H:i:s") . ' Попытка получить данные о раздачах от торрент-клиента "{cm}"...<br />';
		$json = $this->makeRequest('', 'query/torrents');
        foreach($json as $torrent)
		{
			// скачано 100%, не ошибка
			if($torrent['progress'] == 1 && $torrent['state'] != 'error')
				$status = 1;
			else
				$status = 0;
			$data[strtoupper($torrent['hash'])]['status'] = $status;
			//~ $data[strtoupper($torrent['hash'])]['client'] = '';
		}
        return $data;
	}
	
	// добавить торрент
	public function torrentAdd($filename, $savepath = "", $label = "") {
		$filename = implode("\n", $filename);
		$fields = http_build_query(array(
            'urls' => $filename, 'savepath' => $savepath, 'cookie' => $this->sid, 'label' => $label
		), '', '&', PHP_QUERY_RFC3986);
		$json = $this->makeRequest($fields, 'command/download', false);
	}
	
}

// KTorrent 4.3.1 ~ Linux x64
class ktorrent {
	
	private static $base = "http://%s:%s/%s";
	
	public $log;
	public $host;
    public $port;
    public $login;
    public $paswd;
    
    protected $challenge;
    protected $sid;
	
	public function __construct($host = "", $port = "", $login = "", $paswd = "") {
		$this->host = $host;
        $this->port = $port;
        $this->login = $login;
        $this->paswd = $paswd;
	}
	
	public function is_online() {
		if (!$this->getChallenge()) {
            $this->log .= date("H:i:s") . ' Произошла ошибка при подключении к торрент-клиенту "{cm}".<br />';
            return false;
        }
        return true;
	}
	
	// получение challenge
	private function getChallenge() {
		$this->log .= date("H:i:s") . ' Попытка подключиться к торрент-клиенту "{cm}"...<br />';
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
			$this->log .= date("H:i:s") . ' CURL ошибка: ' . curl_error($ch) . '<br />';
			return false;
		}
        curl_close($ch);
        preg_match('|<challenge>(.*)</challenge>|sei', $output, $challenge);
        if(!empty($challenge)) {
            $this->challenge = sha1($challenge[1] . $this->paswd);;
            if($this->getSID())
	            return true;
	        return false;
		}
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
			$this->log .= date("H:i:s") . ' CURL ошибка: ' . curl_error($ch) . '<br />';
			return false;
		}
        curl_close($ch);
        preg_match("|Set-Cookie: ([^;]+)|i", $output, $sid);
        if(!empty($sid)) {
            $this->sid = $sid[1];
            return true;
		}
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
			$this->log .= date("H:i:s") . ' CURL ошибка: ' . curl_error($ch) . '<br />';
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
	public function getTorrents() {
		$this->log .= date("H:i:s") . ' Попытка получить данные о раздачах от торрент-клиента "{cm}"...<br />';
		$json = $this->makeRequest('data/torrents.xml', true, array(CURLOPT_POST => false), true);
		// вывод отличается, если в клиенте только одна раздача
        foreach($json['torrent'] as $torrent)
		{
			// скачано 100%, раздача
			if($torrent['percentage'] == 100 && $torrent['status'] == 'Раздача')
				$status = 1;
			else
				$status = 0;
			$data[strtoupper($torrent['info_hash'])]['status'] = $status;
			//~ $data[strtoupper($torrent['info_hash'])]['client'] = '';
		}
        return $data;
	}
	
	// добавить торрент
	public function torrentAdd($filename, $savepath = "", $label = "") {
		foreach($filename as $filename){
			$json = $this->makeRequest('action?load_torrent=' . $filename, false); // 200 OK
		}
	}
	
}

?>
