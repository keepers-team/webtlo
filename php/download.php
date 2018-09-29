<?php

class Download {
	
	public $savedir;

	protected $ch;
	protected $api_key;
	
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
		curl_setopt_array( $this->ch, Proxy::$proxy['forum_url'] );
	}
	
	// скачивание т-.файлов
	public function download_torrent_files( $forum_url, $user_id, $topics_ids, $retracker, $passkey = "", $replace_passkey = false, $tor_for_user = false ) {
		$success = array();
		Log::append( $replace_passkey
			? 'Выполняется скачивание торрент-файлов с заменой Passkey...'
			: 'Выполняется скачивание торрент-файлов...'
		);
		foreach ( $topics_ids as $topic_id ) {
			curl_setopt_array( $this->ch, array(
			    CURLOPT_URL => $forum_url . '/forum/dl.php',
			    CURLOPT_POSTFIELDS => http_build_query( array(
				    'keeper_user_id' => $user_id,
				    'keeper_api_key' => $this->api_key,
				    't' => $topic_id,
				    'add_retracker_url' => $retracker
			    ))
			));
			$torrent_file = $this->savedir . "[webtlo].t$topic_id.torrent";
			if ( PHP_OS == 'WINNT' ) {
				$torrent_file = mb_convert_encoding( $torrent_file, 'Windows-1251', 'UTF-8' );
			}
			$n = 1; // номер попытки
			$try_number = 1; // номер попытки
			$try = 3; // кол-во попыток
			while ( true ) {
				
				// выходим после 3-х попыток
				if ( $n > $try || $try_number > $try ) {
					Log::append( 'Не удалось скачать торрент-файл для ' . $topic_id . '.' );
					break;
				}
				
				$json = curl_exec( $this->ch );
				
				if ( $json === false ) {
					$http_code = curl_getinfo( $this->ch, CURLINFO_HTTP_CODE );
					Log::append( 'CURL ошибка: ' . curl_error( $this->ch ) . " (раздача $topic_id) [$http_code]" );
					if ( $http_code < 300 && $try_number <= $try ) {
						Log::append( "Повторная попытка $try_number/$try получить данные." );
						sleep( 5 );
						$try_number++;
						continue;
					}
					break;
				}
				
				// проверка "торрент не зарегистрирован" и т.д.
				preg_match( '|<center.*>(.*)</center>|si', mb_convert_encoding( $json, 'UTF-8', 'Windows-1251' ), $forbidden );
				if ( ! empty( $forbidden ) ) {
					preg_match( '|<title>(.*)</title>|si', mb_convert_encoding( $json, 'UTF-8', 'Windows-1251' ), $title );
					$error = empty( $title ) ? $forbidden[1] : $title[1];
					Log::append( "Error: $error ($topic_id)." );
					break;
				}
				
				// проверка "ошибка 503" и т.д.
				preg_match( '|<title>(.*)</title>|si', mb_convert_encoding( $json, 'UTF-8', 'Windows-1251'), $error );
				if ( ! empty( $error ) ) {
					Log::append( "Error: $error[1] ($topic_id)." );
					Log::append( "Повторная попытка $n/$try скачать торрент-файл ($topic_id).");
					sleep( 40 );
					$n++;
					continue;
				}
				
				// меняем passkey
				if ( $replace_passkey ) {
					include_once dirname(__FILE__) . '/torrenteditor.php';
					$torrent = new Torrent();
					if ( $torrent->load( $json ) == false ) {
						Log::append( "Error: $torrent->error ($topic_id)." );
						break;
					}
					$trackers = $torrent->getTrackers();
					foreach ( $trackers as &$tracker ) {
						$tracker = preg_replace( '/(?<==)\w+$/', $passkey, $tracker );
						if ( $tor_for_user ) {
							$tracker = preg_replace( '/\w+(?==)/', 'pk', $tracker );
						}
					}
					unset( $tracker );
					$torrent->setTrackers( $trackers );
					$content = $torrent->bencode();
					if ( file_put_contents( $torrent_file, $content) === false ) {
						Log::append( "Произошла ошибка при сохранении торрент-файла ($topic_id)." );
					} else {
						$success[] = $topic_id;
					}
					break;
				}
				
				// сохраняем в файл
				if ( file_put_contents( $torrent_file, $json ) === false ) {
					Log::append( "Произошла ошибка при сохранении торрент-файла ($topic_id)." );
				} else {
					$success[] = $topic_id;
				}

				break;
			}
		}

		Log::append( "Скачивание торрент-файлов завершено." );

		return $success;
	}
	
	public function __destruct(){
		curl_close( $this->ch );
	}
	
}

?>
