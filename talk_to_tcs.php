<?php
/*
 * web-TLO (Web Torrent List Organizer)
 * talk_to_tcs.php
 * combine: berkut_174 (webtlo@yandex.ru)
 * last change: 11.01.2016
 */

function get_tor_client_data($tcs, &$log){
	$log .= date("H:i:s") . ' Получение данных от торрент-клиентов...<br />';
	$log .= date("H:i:s") . ' Количество торрент-клиентов: ' . count($tcs) . '.<br />';
	$tc_topics = array();
	foreach($tcs as $cm => $tc) {			
		$tmp = array();
		$log .= date("H:i:s") . ' ' . $tc['cm'].' ('.$tc['cl'].') - ';
		switch($tc['cl'])
		{
			case 'utorrent':
					Talk_to_tc_UT::TC_get_data($tc['ht'],$tc['pt'],$tc['lg'],$tc['pw'],$tmp,$log);			//$TC_torrents filling with utorrent
					break;
			case 'transmission':
					Talk_to_tc_TR::TC_get_data($tc['ht'],$tc['pt'],$tc['lg'],$tc['pw'],$tmp,$log);			//$TC_torrents filling with transmission
					//~ $tmp = array();
					//~ $ch = curl_init();
					//~ curl_setopt($ch, CURLOPT_HEADER, 1);
					//~ curl_setopt($ch, CURLOPT_URL, "http://$host:$port/transmission/rpc");
					//~ curl_setopt($ch, CURLOPT_POST, 1);
					//~ curl_setopt($ch, CURLOPT_POSTFIELDS, '{ "method" : "torrent-get", "arguments" : { "fields" : [ "hashString", "name", "error", "percentDone"] } }');
					//~ curl_setopt($ch, CURLOPT_HTTPAUTH, 1);
					//~ curl_setopt($ch, CURLOPT_USERPWD, "$user:$paswd");
					//~ curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
					//~ $json = curl_exec($ch);
					//~ preg_match("%.*\r\n(X-Transmission-Session-Id: .*?)(\r\n.*)%", $json, $X_Transmission_Session_Id);
					//~ curl_setopt($ch, CURLOPT_HEADER, 0);
					//~ curl_setopt($ch, CURLOPT_HTTPHEADER, array($X_Transmission_Session_Id[1]));
					//~ $json = curl_exec($ch);
					//~ curl_close($ch);
					//~ $data = json_decode($json, true);
					//~ foreach($data['arguments']['torrents'] as $topic){
						//~ if($topic['percentDone'] == 1 && $topic['error'] == 0){
							//~ $tmp[$topic['hashString']][] = 1;
						//~ } else {
							//~ $tmp[$topic['hashString']][] = 0;
						//~ }
					//~ }
					break;
			case 'vuze':
					Talk_to_tc_VU::TC_get_data($tc['ht'],$tc['pt'],$tc['lg'],$tc['pw'],$tmp,$log);			//$TC_torrents filling with vuze
					break;
			case 'deluge':
					Talk_to_tc_DE::TC_get_data($tc['ht'],$tc['pt'],$tc['lg'],$tc['pw'],$tmp,$log);			//$TC_torrents filling with deluge
					break;
			case 'qbittorrent':
					Talk_to_tc_QB::TC_get_data($tc['ht'],$tc['pt'],$tc['lg'],$tc['pw'],$tmp,$log);			//$TC_torrents filling with qbittorrent
					break;
			case 'ktorrent':
					Talk_to_tc_KT::TC_get_data($tc['ht'],$tc['pt'],$tc['lg'],$tc['pw'],$tmp,$log);			//$TC_torrents filling with ktorrent
					break;
		}
		$tc_topics += $tmp;
	}
	return $tc_topics;
}

// взято отсюда https://forum.transmissionbt.com/viewtopic.php?t=6810

//~ function get_data_transmission($host, $port, $login, $paswd, &$tc_topics, &$log){
	//~ $ch = curl_init();
	//~ curl_setopt($ch, CURLOPT_HEADER, 1);
	//~ curl_setopt($ch, CURLOPT_URL, "http://$host:$port/transmission/rpc");
	//~ curl_setopt($ch, CURLOPT_POST, 1);
	//~ curl_setopt($ch, CURLOPT_POSTFIELDS, '{ "method" : "torrent-get", "arguments" : { "fields" : [ "hashString", "name", "error", "percentDone"] } }');
	//~ curl_setopt($ch, CURLOPT_HTTPAUTH, 1);
	//~ curl_setopt($ch, CURLOPT_USERPWD, "$user:$paswd");
	//~ curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
	//~ $data = curl_exec($ch);
	//~ preg_match("%.*\r\n(X-Transmission-Session-Id: .*?)(\r\n.*)%", $data, $X_Transmission_Session_Id);
	//~ curl_setopt($ch, CURLOPT_HEADER, 0);
	//~ curl_setopt($ch, CURLOPT_HTTPHEADER, array($X_Transmission_Session_Id[1]));
	//~ $data = curl_exec($ch);
	//~ curl_close($ch);
	//~ $data = json_decode($data, true);
	//~ foreach($data['arguments']['torrents'] as $topic){
		//~ if($topic['percentDone'] == 1 && $topic['error'] == 0){
			//~ $tc_topics[$topic['hashString']][] = 1;
		//~ } else {
			//~ $tc_topics[$topic['hashString']][] = 0;
		//~ }
	//~ }
	//~ print_r ($tc_topics);
//~ }

class Talk_to_tc_TR{
 
	public static function TC_get_data($hostname, $port, $login, $password, &$TC_torrents, &$log){

		//----------------------------------------------------------------------------------------------------------
		//~ $log .= '<span class="rp-header">Получение данных от торрент-клиента (Transmission)</span><br/>';
		$starttime = microtime(true);
		//----------------------------------------------------------------------------------------------------------

		$base = base64_encode($login .':'. $password);
				
		//вход, получение идентификатора сессии
		$session_id =	self::TC_request($hostname, $port, $base, 'transmission/rpc', '', '{"method":"session-get"}');
		//получение данных о торрентах
		$raw_data =		self::TC_request($hostname, $port, $base, 'transmission/rpc', $session_id,  '{"method":"torrent-get","arguments":{"fields":["hashString","name","error","percentDone"]}}');
				
		$data = json_decode($raw_data, true);
		
		foreach($data['arguments']['torrents'] as $torrent)							//приведение данных от клиента к виду (hash => (0 => status))
		{
			if(	$torrent['percentDone'] ==	1						//скачано 100%;
				&& $torrent['error'] == 0							//нет ошибок;
																	//возможно стоит добавить еще какие-нибудь проверки;
																	//вместе с тем, я не уверен, что делать проверку на наличие/отсутствие ошибок корректно
																	//с точки зрения хранения раздач (ошибка может возникать при соединении с трекером, при этом с данными все впорядке);
				) $status = 1;
			else $status = 0;
			$TC_torrents[strtoupper($torrent['hashString'])][0] = $status;						//статус: 1 - скачан на 100%, ошибок нет, 0 - хотя бы одно условие не удовлетворено
			//$TC_torrents[strtoupper($torrent['hashString'])][1] = $torrent['name'];			//имя
		}
		//----------------------------------------------------------------------------------------------------------
		$endtime1 = microtime(true);
		$log .= 'получено раздач: '.count($TC_torrents)./*' (за '.round($endtime1-$starttime, 1).' с).*/'.<br />';
		//~ $log .= '<br/>';
		//----------------------------------------------------------------------------------------------------------
		
	}


	private static function TC_request($hostname, $port, $base, $url, $session_id, $json_req){

	$fp = fsockopen($hostname, $port, $errno, $errstr, 1);
		if (!$fp)
		{
			//echo "$errstr ($errno)<br />\n";
		}
		else
		{
			$req_heads  = "POST /" . $url . " HTTP/1.1\r\n";
			$req_heads .= "Host: " . $hostname . ":" .  $port . "\r\n";
			$req_heads .= "Authorization: Basic " . $base . "\r\n";
			$req_heads .= "Accept: application/json, text/javascript, */*; q=0.01\r\n";
			$req_heads .= "Accept-Encoding: gzip, deflate\r\n";
			$req_heads .= "Accept-Language: ru-RU,ru;q=0.8,en-US;q=0.5,en;q=0.3\r\n";
			$req_heads .= "Content-Type: json; charset=UTF-8\r\n";
			$req_heads .= "X-Requested-With: XMLHttpRequest\r\n";
			if($session_id != '')
			{
				$req_heads .= "X-Transmission-Session-Id: " . $session_id . "\r\n";		
			}
			$req_heads .= "Connection: close\r\n";
			$req_heads .= "User-Agent: Mozilla/5.0 (Windows NT 6.1; WOW64; rv:23.0) Gecko/20100101 Firefox/23\r\n";
			$req_heads .= "Content-length: " . strlen($json_req) . "\r\n\r\n";
			$req_heads .= $json_req . "\r\n\r\n";
			
			fwrite($fp, $req_heads);
			$response = stream_get_contents($fp);
			fclose($fp);
			
			$pos =			strpos($response, "\r\n\r\n");
			$resp_heads =	substr($response, 0, $pos + 2);
			$resp_body =	substr($response, $pos + 4);
			
			if(substr_count($resp_heads,'chunked') > 0)			$resp_body = untichunk($resp_body);							//смотри common.php
			if(substr_count($resp_heads,'gzip') > 0)			$resp_body = gzinflate(substr($resp_body, 10));
			if(substr_count($resp_heads,'windows-1251') > 0)	$resp_body = iconv('windows-1251', 'UTF-8', $resp_body);
			
			/*
			echo $req_heads.	'<br/><br/>';
			echo $resp_heads.	'<br/><br/>';
			echo $resp_body.	'<br/><br/><br/><br/><hr>';
			*/
			if($session_id == '')
			{
				$session_id = substr($resp_heads, strpos($resp_heads, 'X-Transmission-Session-Id')+27, 48);
				return $session_id;
			}
			else return $resp_body;
		}
	}
	
}

class Talk_to_tc_UT{
 
	public static function TC_get_data($hostname, $port, $login, $password, &$TC_torrents, &$log){

		//----------------------------------------------------------------------------------------------------------
		//~ $log .= '<span class="rp-header">Получение данных от торрент-клиента (uTorrent)</span><br/>';
		$starttime = microtime(true);
		//----------------------------------------------------------------------------------------------------------

		$base = base64_encode($login .':'. $password);

		$token_and_guid =	self::TC_request($hostname, $port, $base, 'gui/token.html', '');	
		//~ var_dump($token_and_guid);							//вход, получение идентификаторов сессии (token'а и куковского guid)
				$token =	substr($token_and_guid,0, -20);
				$guid =		substr($token_and_guid,-20);
				//~ $log .= '"' . $token_and_guid . '" ';
		$raw_data =			self::TC_request($hostname, $port, $base, "gui/?token=".$token."&list=1", 'GUID='.$guid);		//получение данных о торрентах
		
		$data = json_decode($raw_data);
		
		foreach($data->torrents as $torrent)								//приведение данных от клиента к виду (hash => (0 => status, 1 => name, 2 => loaded percentage))
		{
			$status = decbin($torrent[1]);
			if(	$torrent[4] == 1000					//скачано 100%;
				&& $status{0} == 1						//загружено;
				&& $status{4} == 1						//проверено;
				&& $status{3} == 0						//нет ошибок;
														//я не уверен, что делать проверку на наличие/отсутствие ошибок корректно
														//с точки зрения хранения раздач (ошибка может возникать при соединении с трекером, при этом с данными все впорядке);
				) $status = 1;
			else $status = 0;
			$TC_torrents[$torrent[0]][0] = $status;						//статус: 1 - скачан на 100%, проверен, ошибок нет, 0 - хотя бы одно условие не удовлетворено
			//$TC_torrents[$uttorrent[0]][1] = $torrent[2];				//имя
		}
		//----------------------------------------------------------------------------------------------------------
		$endtime1 = microtime(true);
		$log .= 'получено раздач: '.count($TC_torrents)./*' (за '.round($endtime1-$starttime, 1).' с).*/'.<br />';
		//~ $log .= '<br/>';
		//----------------------------------------------------------------------------------------------------------
		
	}


	private static function TC_request($hostname, $port, $base, $url, $cookie){

	$fp = fsockopen($hostname, $port, $errno, $errstr, 1);
		if (!$fp)
		{
			//echo "$errstr ($errno)<br />\n";
		}
		else
		{
			$req_heads  = "GET /" . $url . " HTTP/1.1\r\n";
			$req_heads .= "Host: " . $hostname . ":" .  $port . "\r\n";
			$req_heads .= "Authorization: Basic " . $base . "\r\n";
			if($url != 'gui/token.html')
			{
				if($cookie != 'GUID=emptyemptyemptyempty')$req_heads .= "Cookie: " . $cookie . "\r\n";
				$req_heads .= "X-Requested-With: XMLHttpRequest\r\n";
				$req_heads .= "X-Request: JSON\r\n";
				$req_heads .= "Accept: application/json\r\n";			
			}		
			else
			{
				$req_heads .= "Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8\r\n";
			}
			$req_heads .= "Accept-Language: ru-RU,ru;q=0.8,en-US;q=0.5,en;q=0.3\r\n";
			$req_heads .= "Connection: close\r\n";
			$req_heads .= "User-Agent: Mozilla/5.0 (Windows NT 6.1; WOW64; rv:23.0) Gecko/20100101 Firefox/23\r\n\r\n";
			
			fwrite($fp, $req_heads);
			$response = stream_get_contents($fp);
			fclose($fp);
			//~ return addslashes($response);
			$pos =			strpos($response, "\r\n\r\n");
			$resp_heads =	substr($response, 0, $pos + 2);
			$resp_body =	substr($response, $pos + 4);
			//~ return $resp_body;
			if(substr_count($resp_heads,'chunked') > 0)			$resp_body = untichunk($resp_body);							//смотри common.php
			if(substr_count($resp_heads,'gzip') > 0)			$resp_body = gzinflate(substr($resp_body, 10));
			if(substr_count($resp_heads,'windows-1251') > 0)	$resp_body = iconv('windows-1251', 'UTF-8', $resp_body);
			//~ return $resp_body;
			/*
			echo $req_heads.	'<br/><br/>';
			echo $resp_heads.	'<br/><br/>';
			echo $resp_body.	'<br/><br/><br/><br/><hr>';
			*/
			if($url == 'gui/token.html')
			{
				$token = substr($resp_body,44, -13);
				//~ return addslashes($resp_body);
				if(substr_count($resp_heads, 'GUID') > 0) $guid = substr($resp_heads, strpos($resp_heads, 'GUID')+5, 20);
				else $guid = 'emptyemptyemptyempty';																			//совместимость с версией 2.2.1, там нет куковского guid'а
				
				return $token . $guid;
			}
			else return $resp_body;
		}
	}
	
}

class Talk_to_tc_DE{
 
	public static function TC_get_data($hostname, $port, $login, $password, &$TC_torrents, &$log){

		//----------------------------------------------------------------------------------------------------------
		//~ $log .= '<span class="rp-header">Получение данных от торрент-клиента (Deluge, WebUi)</span><br/>';
		$starttime = microtime(true);
		//----------------------------------------------------------------------------------------------------------
				
		//вход, получение идентификатора сессии
		$session_id =	self::TC_request($hostname, $port, $base, 'json', '', '{"method":"auth.login","params":["'.$password.'"],"id":2}');
		//получение данных о торрентах
		$raw_data =		self::TC_request($hostname, $port, $base, 'json', $session_id,  '{"method":"web.update_ui","params":[["name","message","progress"],{}],"id":9}');
				
		$data = json_decode($raw_data, true);
		
		foreach($data['result']['torrents'] as $hash => $torrent)					//приведение данных от клиента к виду (hash => (0 => status))
		{
			if(	$torrent['progress'] ==	100							//скачано 100%;
				&& $torrent['message'] == 'OK'						//нет ошибок;
																	//возможно стоит добавить еще какие-нибудь проверки;
																	//вместе с тем, я не уверен, что делать проверку на наличие/отсутствие ошибок корректно
																	//с точки зрения хранения раздач (ошибка может возникать при соединении с трекером, при этом с данными все впорядке);
				) $status = 1;
			else $status = 0;
			$TC_torrents[strtoupper($hash)][0] = $status;						//статус: 1 - скачан на 100%, ошибок нет, 0 - хотя бы одно условие не удовлетворено
			//$TC_torrents[strtoupper($hash)][1] = $torrent['name'];			//имя
		}
		//----------------------------------------------------------------------------------------------------------
		$endtime1 = microtime(true);
		$log .= 'получено раздач: '.count($TC_torrents)./*' (за '.round($endtime1-$starttime, 1).' с).*/'.<br />';
		//~ $log .= '<br/>';
		//----------------------------------------------------------------------------------------------------------
		
	}


	private static function TC_request($hostname, $port, $base, $url, $session_id, $json_req){

	$fp = fsockopen($hostname, $port, $errno, $errstr, 1);
		if (!$fp)
		{
			//echo "$errstr ($errno)<br />\n";
		}
		else
		{
			$req_heads  = "POST /" . $url . " HTTP/1.1\r\n";
			$req_heads .= "Host: " . $hostname . ":" .  $port . "\r\n";
			$req_heads .= "Authorization: Basic " . $base . "\r\n";
			$req_heads .= "Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8\r\n";
			$req_heads .= "Accept-Encoding: gzip, deflate\r\n";
			$req_heads .= "Accept-Language: ru-RU,ru;q=0.8,en-US;q=0.5,en;q=0.3\r\n";
			$req_heads .= "Content-Type: application/json; charset=UTF-8\r\n";
			$req_heads .= "X-Requested-With: XMLHttpRequest\r\n";
			if($session_id != '')
			{
				$req_heads .= "Cookie: _session_id=" . $session_id . "\r\n";
			}
			$req_heads .= "Connection: close\r\n";
			$req_heads .= "User-Agent: Mozilla/5.0 (Windows NT 6.1; WOW64; rv:23.0) Gecko/20100101 Firefox/23\r\n";
			$req_heads .= "Content-length: " . strlen($json_req) . "\r\n\r\n";
			$req_heads .= $json_req . "\r\n\r\n";
			
			fwrite($fp, $req_heads);
			$response = stream_get_contents($fp);
			fclose($fp);
			
			$pos =			strpos($response, "\r\n\r\n");
			$resp_heads =	substr($response, 0, $pos + 2);
			$resp_body =	substr($response, $pos + 4);
			
			if(substr_count($resp_heads,'chunked') > 0)			$resp_body = untichunk($resp_body);							//смотри common.php
			if(substr_count($resp_heads,'gzip') > 0)			$resp_body = gzinflate(substr($resp_body, 10));
			if(substr_count($resp_heads,'windows-1251') > 0)	$resp_body = iconv('windows-1251', 'UTF-8', $resp_body);
			
			/*
			echo $req_heads.	'<br/><br/>';
			echo $resp_heads.	'<br/><br/>';
			echo $resp_body.	'<br/><br/><br/><br/><hr>';
			*/
			if($session_id == '')
			{
				$session_id = substr($resp_heads, strpos($resp_heads, '_session_id')+12, 36);
				return $session_id;
			}
			else return $resp_body;
		}
	}
	
}

class Talk_to_tc_KT{
 
	public static function TC_get_data($hostname, $port, $login, $password, &$TC_torrents, &$log){

		//----------------------------------------------------------------------------------------------------------
		//~ $log .= '<span class="rp-header">Получение данных от торрент-клиента (KTorrent)</span><br/>';
		$starttime = microtime(true);
		//----------------------------------------------------------------------------------------------------------
		
		//получение challenge
		$challenge =	self::TC_request($hostname, $port, 'login/challenge.xml', '', '', '');
		$challenge = new SimpleXMLElement($challenge);
				
		$challenge = sha1($challenge . $password);														//смесь challenge с паролем
		
		//вход, получение kt_sessid
		$kt_sessid =	self::TC_request($hostname, $port, 'login?page=interface.html', $login, $challenge, '');
		
		//получение данных о торрентах
		$raw_data =		self::TC_request($hostname, $port, 'data/torrents.xml', '', '', $kt_sessid);
				
		$raw_data = new SimpleXMLElement($raw_data);					//из xml в array через json, спасибо комментариям в онлайн мануалах по php (раздел по xml)
		$raw_data = json_encode($raw_data);
		$data = json_decode($raw_data, true);
				
		foreach($data['torrent'] as $torrent)							//приведение данных от клиента к виду (hash => (0 => status))
		{
			if(	$torrent['percentage'] ==	100					//скачано 100%;
																//параметра, несущего статус ошибки, не нашел.
																//возможно стоит добавить еще какие-нибудь проверки;
																//вместе с тем, я не уверен, что делать проверку на наличие/отсутствие ошибок корректно
																//с точки зрения хранения раздач (ошибка может возникать при соединении с трекером, при этом с данными все впорядке);
				) $status = 1;
			else $status = 0;
			$TC_torrents[strtoupper($torrent['info_hash'])][0] = $status;						//статус: 1 - скачан на 100%, ошибок нет, 0 - хотя бы одно условие не удовлетворено
			//$TC_torrents[strtoupper($torrent['info_hash'])][1] = $torrent['name'];				//имя
		}
		//----------------------------------------------------------------------------------------------------------
		$endtime1 = microtime(true);
		$log .= 'получено раздач: '.count($TC_torrents)./*' (за '.round($endtime1-$starttime, 1).' с).*/'.<br />';
		//~ $log .= '<br/>';
		//----------------------------------------------------------------------------------------------------------
		
	}


	private static function TC_request($hostname, $port, $url, $login, $challenge, $kt_sessid){

	$fp = fsockopen($hostname, $port, $errno, $errstr, 1);
		if (!$fp)
		{
			//echo "$errstr ($errno)<br />\n";
		}
		else
		{
			$req_heads  = '';
			if($url == 'login?page=interface.html')
			{
				$req_heads  = "POST /" . $url . " HTTP/1.1\r\n";
			}
			else
			{
				$req_heads  = "GET /" . $url . " HTTP/1.1\r\n";
			}
			$req_heads .= "Host: " . $hostname . ":" .  $port . "\r\n";
			$req_heads .= "Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8\r\n";
			$req_heads .= "Accept-Language: ru-RU,ru;q=0.8,en-US;q=0.5,en;q=0.3\r\n";
			$req_heads .= "Accept-Encoding: gzip, deflate\r\n";
			$req_heads .= "User-Agent: Mozilla/5.0 (Windows NT 6.1; WOW64; rv:23.0) Gecko/20100101 Firefox/23\r\n";
			if($url == 'login?page=interface.html')
			{
				$data = "username=".$login."&password=&Login=Sign+in&challenge=".$challenge;
				$req_heads .= "Content-Type: application/x-www-form-urlencoded\r\n";
				$req_heads .= "Content-Length: ". strlen($data) ."\r\n";
				$req_heads .= "Connection: close\r\n\r\n";
				$req_heads .= $data ."\r\n\r\n";
			}
			if($url == 'data/torrents.xml')
			{
				$req_heads .= "Cookie: KT_SESSID=".$kt_sessid."\r\n";
				$req_heads .= "Connection: close\r\n\r\n";
			}
			else
			{
				$req_heads .= "Connection: close\r\n\r\n";
			}
			
			fwrite($fp, $req_heads);
			$response = stream_get_contents($fp);
			fclose($fp);
			
			$pos =			strpos($response, "\r\n\r\n");
			$resp_heads =	substr($response, 0, $pos + 2);
			$resp_body =	substr($response, $pos + 4);
			
			if(substr_count($resp_heads,'chunked') > 0)			$resp_body = untichunk($resp_body);							//смотри common.php
			if(substr_count($resp_heads,'gzip') > 0)			$resp_body = gzinflate(substr($resp_body, 10));
			if(substr_count($resp_heads,'windows-1251') > 0)	$resp_body = iconv('windows-1251', 'UTF-8', $resp_body);
			
			/*
			echo $req_heads.	'<br/><br/>';
			echo $resp_heads.	'<br/><br/>';
			echo $resp_body.	'<br/><br/><br/><br/><hr>';
			*/
			if($url == 'login?page=interface.html')
			{
				$kt_sessid	= substr($resp_heads, strpos($resp_heads, 'KT_SESSID')+10, 10);
				return $kt_sessid;
			}
			else return $resp_body;
		}
	}
	
}

class Talk_to_tc_QB{
 
	public static function TC_get_data($hostname, $port, $login, $password, &$TC_torrents, &$log){

		//----------------------------------------------------------------------------------------------------------
		//~ $log .= '<span class="rp-header">Получение данных от торрент-клиента (qBittorrent)</span><br/>';
		$starttime = microtime(true);
		//----------------------------------------------------------------------------------------------------------
		
		//получение параметров авторизации
		$auth_param =	self::TC_request($hostname, $port, '', '');
				
		$realm = 'Web UI Access';
		$url = 'json/torrents';
		$nc = sprintf("%08d", 1);
		$nonce = substr($auth_param,0,32);
		$opaque = substr($auth_param,-32);
		$ha1 = md5( $login . ':' . $realm . ':' . $password );
		$ha2 = md5( 'GET:/' . $url );
		$cnonce = substr (md5(rand(0,256)), 16);
		$qop = 'auth';
		$response = md5( $ha1 . ':' . $nonce . ':' . $nc . ':' . $cnonce . ':' . $qop . ':' . $ha2);
		
		$auth =	'Digest username="'.$login.
				'", realm="'.$realm.
				'", nonce="'.$nonce.
				'", uri="/'.$url.
				'", algorithm="MD5", response="'.$response.
				'", opaque="'.$opaque.
				'", qop="auth", nc='.$nc.
				', cnonce="'.$cnonce.'"';
				
		//вход, получение данных о торрентах
		$raw_data = self::TC_request($hostname, $port, $url, $auth);
		
		$data = json_decode($raw_data, true);
		
		foreach($data as $torrent)							//приведение данных от клиента к виду (hash => (0 => status))
		{
			if(	$torrent['progress'] ==	1						//скачано 100%;
																//параметра, несущего статус ошибки, не нашел.
																//возможно стоит добавить еще какие-нибудь проверки;
																//вместе с тем, я не уверен, что делать проверку на наличие/отсутствие ошибок корректно
																//с точки зрения хранения раздач (ошибка может возникать при соединении с трекером, при этом с данными все впорядке);
				) $status = 1;
			else $status = 0;
			$TC_torrents[strtoupper($torrent['hash'])][0] = $status;						//статус: 1 - скачан на 100%, ошибок нет, 0 - хотя бы одно условие не удовлетворено
			//$TC_torrents[strtoupper($torrent['hash'])][1] = $torrent['name'];				//имя
		}
		//----------------------------------------------------------------------------------------------------------
		$endtime1 = microtime(true);
		$log .= 'получено раздач: '.count($TC_torrents)./*' (за '.round($endtime1-$starttime, 1).' с).*/'.<br />';
		//~ $log .= '<br/>';
		//----------------------------------------------------------------------------------------------------------
		
	}


	private static function TC_request($hostname, $port, $url, $auth){

	$fp = fsockopen($hostname, $port, $errno, $errstr, 1);
		if (!$fp)
		{
			//echo "$errstr ($errno)<br />\n";
		}
		else
		{
			$req_heads  = "GET /" . $url . " HTTP/1.1\r\n";
			$req_heads .= "Host: " . $hostname . ":" .  $port . "\r\n";
			$req_heads .= "Accept-Encoding: gzip, deflate\r\n";
			$req_heads .= "Accept-Language: ru-RU,ru;q=0.8,en-US;q=0.5,en;q=0.3\r\n";
			if($url != '')
			{
				$req_heads .= "Accept: application/json\r\n";
				$req_heads .= "X-Requested-With: XMLHttpRequest\r\n";
				$req_heads .= "X-Request: JSON\r\n";
				$req_heads .= "Authorization: " . $auth . "\r\n";
			}
			else
			{
				$req_heads .= "Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8\r\n";
			}
			$req_heads .= "User-Agent: Mozilla/5.0 (Windows NT 6.1; WOW64; rv:23.0) Gecko/20100101 Firefox/23\r\n";
			$req_heads .= "Connection: close\r\n\r\n";
			
			
			fwrite($fp, $req_heads);
			$response = stream_get_contents($fp);
			fclose($fp);
			
			$pos =			strpos($response, "\r\n\r\n");
			$resp_heads =	substr($response, 0, $pos + 2);
			$resp_body =	substr($response, $pos + 4);
			
			if(substr_count($resp_heads,'chunked') > 0)			$resp_body = untichunk($resp_body);							//смотри common.php
			if(substr_count($resp_heads,'gzip') > 0)			$resp_body = gzinflate(substr($resp_body, 10));
			if(substr_count($resp_heads,'windows-1251') > 0)	$resp_body = iconv('windows-1251', 'UTF-8', $resp_body);
			
			/*
			echo $req_heads.	'<br/><br/>';
			echo $resp_heads.	'<br/><br/>';
			echo $resp_body.	'<br/><br/><br/><br/><hr>';
			*/
			if($url == '')
			{
				$auth_param	= substr($resp_heads, strpos($resp_heads, 'nonce')+7, 32);
				$auth_param	.= substr($resp_heads, strpos($resp_heads, 'opaque')+8, 32);
				return $auth_param;
			}
			else return $resp_body;
		}
	}
	
}

class Talk_to_tc_VU{
 
	public static function TC_get_data($hostname, $port, $login, $password, &$TC_torrents, &$log){

		//----------------------------------------------------------------------------------------------------------
		//~ $log .= '<span class="rp-header">Получение данных от торрент-клиента (Vuze, Vuze Web Remote)</span><br/>';
		$starttime = microtime(true);
		//----------------------------------------------------------------------------------------------------------

		$base = base64_encode($login .':'. $password);
		
		//вход, получение идентификатора сессии
		$session_id =	self::TC_request($hostname, $port, $base, '', '', '');
		//получение данных о торрентах
		$raw_data =		self::TC_request($hostname, $port, $base, 'transmission/rpc', $session_id,  '{"method":"torrent-get","arguments":{"fields":["hashString","name","error","percentDone"]}}');
		
		$data = json_decode($raw_data, true);
		
		foreach($data['arguments']['torrents'] as $torrent)							//приведение данных от клиента к виду (hash => (0 => status))
		{
			if(	$torrent['percentDone'] ==	1						//скачано 100%;
				&& $torrent['error'] == 0							//нет ошибок;
																	//возможно стоит добавить еще какие-нибудь проверки;
																	//вместе с тем, я не уверен, что делать проверку на наличие/отсутствие ошибок корректно
																	//с точки зрения хранения раздач (ошибка может возникать при соединении с трекером, при этом с данными все впорядке);
				) $status = 1;
			else $status = 0;
			$TC_torrents[strtoupper($torrent['hashString'])][0] = $status;						//статус: 1 - скачан на 100%, ошибок нет, 0 - хотя бы одно условие не удовлетворено
			//$TC_torrents[strtoupper($torrent['hashString'])][1] = $torrent['name'];			//имя
		}
		//----------------------------------------------------------------------------------------------------------
		$endtime1 = microtime(true);
		$log .= 'получено раздач: '.count($TC_torrents)./*' (за '.round($endtime1-$starttime, 1).' с).*/'.<br />';
		//~ $log .= '<br/>';
		//----------------------------------------------------------------------------------------------------------
		
	}


	private static function TC_request($hostname, $port, $base, $url, $session_id, $json_req){

	$fp = fsockopen($hostname, $port, $errno, $errstr, 1);
		if (!$fp)
		{
			//echo "$errstr ($errno)<br />\n";
		}
		else
		{
			$req_heads  = "POST /" . $url . " HTTP/1.1\r\n";
			$req_heads .= "Host: " . $hostname . ":" .  $port . "\r\n";
			$req_heads .= "Authorization: Basic " . $base . "\r\n";
			$req_heads .= "Accept-Encoding: gzip, deflate\r\n";
			$req_heads .= "Accept-Language: ru-RU,ru;q=0.8,en-US;q=0.5,en;q=0.3\r\n";
			$req_heads .= "Connection: close\r\n";
			$req_heads .= "User-Agent: Mozilla/5.0 (Windows NT 6.1; WOW64; rv:23.0) Gecko/20100101 Firefox/23\r\n";
			if($session_id != '')
			{
				$req_heads .= "Accept: application/json, text/javascript, */*; q=0.01\r\n";
				$req_heads .= "Content-Type: json; charset=UTF-8\r\n";
				$req_heads .= "X-Requested-With: XMLHttpRequest\r\n";
				$req_heads .= "Cookie: X-Transmission-Session-Id=" . $session_id . "\r\n";
				$req_heads .= "Content-length: " . strlen($json_req) . "\r\n\r\n";
				$req_heads .= $json_req . "\r\n\r\n";
			}
			else
			{
				$req_heads .= "Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8\r\n\r\n";
			}
			
			fwrite($fp, $req_heads);
			$response = stream_get_contents($fp);
			fclose($fp);
			
			$pos =			strpos($response, "\r\n\r\n");
			$resp_heads =	substr($response, 0, $pos + 2);
			$resp_body =	substr($response, $pos + 4);
			
			if(substr_count($resp_heads,'chunked') > 0)			$resp_body = untichunk($resp_body);							//смотри common.php
			if(substr_count($resp_heads,'gzip') > 0)			$resp_body = gzinflate(substr($resp_body, 10));
			if(substr_count($resp_heads,'windows-1251') > 0)	$resp_body = iconv('windows-1251', 'UTF-8', $resp_body);
			
			/*
			echo $req_heads.	'<br/><br/>';
			echo $resp_heads.	'<br/><br/>';
			echo $resp_body.	'<br/><br/><br/><br/><hr>';
			*/
			if($session_id == '')
			{
				$session_id = substr($resp_heads, strpos($resp_heads, 'X-Transmission-Session-Id:')+27, 20);
				return $session_id;
			}
			else return $resp_body;
		}
	}
	
}

?>
