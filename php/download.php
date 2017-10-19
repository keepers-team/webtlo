<?php

class Download {

	protected $ch;
	protected $api_key;
	
	private $savedir;
	
	public function __construct ( $api_key ) {
		$this->api_key = $api_key;
		$this->init_curl();
	}
	
	private function init_curl() {
		$this->ch = curl_init();
		curl_setopt_array( $this->ch, array(
			CURLOPT_RETURNTRANSFER => 1,
			CURLOPT_SSL_VERIFYPEER => false,
			CURLOPT_SSL_VERIFYHOST => 2,
			CURLOPT_USERAGENT => "Mozilla/5.0 (Windows NT 6.1) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/55.0.2883.87 Safari/537.36",
			CURLOPT_CONNECTTIMEOUT => 20,
			CURLOPT_TIMEOUT => 20
		));
		curl_setopt_array( $this->ch, Proxy::$proxy );
	}
	
	// подготовка каталогов
	public function create_directories ( $savedir, &$dl_log ) {
		if ( empty( $savedir ) ) {
			$dl_log = "<span class=\"errors\">Не указан каталог для скачивания торрент-файлов, проверьте настройки. Скачивание невозможно.</span>";
			throw new Exception();
		}
		$winsavedir = mb_convert_encoding( $savedir, 'Windows-1251', 'UTF-8' );
		// проверяем существование указанного каталога
		$result = mkdir_recursive( PHP_OS == 'WINNT' ? $winsavedir : $savedir );
		if ( ! $result ) {
			$dl_log = "<span class=\"errors\">Не удалось создать каталог \"$savedir\": неверно указан путь или недостаточно прав. Скачивание невозможно.</span>";
			throw new Exception();
		}
		$this->savedir = $savedir;
	}
	
	// скачивание т-.файлов
	public function download_torrent_files( $forum_url, $user_id, $topics, $retracker, &$dl_log, $passkey = "", $replace_passkey = false, $tor_for_user = false ) {
		if( empty( $user_id ) ) {
			$dl_log = "Необходимо указать в настройках авторизации \"Ключ id\".";
			throw new Exception();
		}
		$q = 0; // кол-во успешно скачанных торрент-файлов
		//~ $err = 0;
		$starttime = microtime(true);
		Log::append ( $replace_passkey
			? 'Выполняется скачивание торрент-файлов с заменой Passkey...'
			: 'Выполняется скачивание торрент-файлов...'
		);
		$basename = $_SERVER['SERVER_ADDR'] . str_replace( 'php/', '', substr($_SERVER['SCRIPT_NAME'], 0, strpos($_SERVER['SCRIPT_NAME'], '/' , 1) + 1) ) . basename( $this->savedir );
		//~ $topics = array_chunk($topics, 30, true);
		foreach( $topics as $topic ) {
			curl_setopt_array($this->ch, array(
			    CURLOPT_URL => $forum_url . '/forum/dl.php',
			    CURLOPT_POSTFIELDS => http_build_query(array(
				    'keeper_user_id' => $user_id,
				    'keeper_api_key' => "$this->api_key",
				    't' => $topic['id'],
				    'add_retracker_url' => $retracker
			    ))
			));
			$torrent_file = $this->savedir . '[webtlo].t' . $topic['id'] . '.torrent';
			if( PHP_OS == 'WINNT' ) $torrent_file = mb_convert_encoding( $torrent_file, 'Windows-1251', 'UTF-8' );
			$n = 1; // номер попытки
			$try_number = 1; // номер попытки
			$try = 3; // кол-во попыток
			while ( true ) {
				
				// выходим после 3-х попыток
				if ( $n > $try || $try_number > $try ) {
					Log::append ( 'Не удалось скачать торрент-файл для ' . $topic['id'] . '.' );
					break;
				}
				
				$json = curl_exec( $this->ch );
				
				if ( $json === false ) {
					$http_code = curl_getinfo( $this->ch, CURLINFO_HTTP_CODE );
					Log::append ( 'CURL ошибка: ' . curl_error( $this->ch ) . " (раздача ${topic['id']}) [$http_code]" );
					if ( $http_code < 300 && $try_number <= $try ) {
						Log::append( "Повторная попытка $try_number/$try получить данные." );
						sleep( 5 );
						$try_number++;
						continue;
					}
					break;
				}
				
				// проверка "торрент не зарегистрирован" и т.д.
				preg_match('|<center.*>(.*)</center>|si', mb_convert_encoding($json, 'UTF-8', 'Windows-1251'), $forbidden);
				if(!empty($forbidden)) {
					preg_match('|<title>(.*)</title>|si', mb_convert_encoding($json, 'UTF-8', 'Windows-1251'), $title);
					Log::append ( 'Error: ' . (empty($title) ? $forbidden[1] : $title[1]) . ' (' . $topic['id'] . ').' );
					break;
				}
				
				// проверка "ошибка 503" и т.д.
				preg_match('|<title>(.*)</title>|si', mb_convert_encoding($json, 'UTF-8', 'Windows-1251'), $error);
				if(!empty($error)) {
					Log::append ( 'Error: ' . $error[1] . ' (' . $topic['id'] . ').' );
					Log::append ( "Повторная попытка $n/$try скачать торрент-файл (${topic['id']}).");
					sleep(40);
					$n++;
					continue;
				}
				
				// меняем passkey
				if( $replace_passkey ) {
					include_once dirname(__FILE__) . '/torrenteditor.php';
					$torrent = new Torrent();
					if($torrent->load($json) == false)
					{
						Log::append ( $torrent->error . '(' . $topic_id . ').' );
						break;
					}
					$trackers = $torrent->getTrackers();
					foreach($trackers as &$tracker){
						$tracker = preg_replace('/(?<==)\w+$/', $passkey, $tracker);
						if ( $tor_for_user ) {
							$tracker = preg_replace( '/\w+(?==)/', 'pk', $tracker );
						}
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
					$success[$q]['filename'] = "http://${basename}/[webtlo].t${topic['id']}.torrent";
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
