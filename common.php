<?php

include_once dirname(__FILE__) . '/php/log.php';
include_once dirname(__FILE__) . '/php/db.php';
include_once dirname(__FILE__) . '/php/user_details.php';

// http://develstudio.ru/php-orion/articles/rabotaem-s-fajlami-ini-v-php
if (!defined('_BR_'))
	define('_BR_',chr(13).chr(10));

class TIniFileEx {
	
    protected static $rcfg;
    protected static $wcfg;
    
    public static $filename = "config.ini";
    
    public function __construct( $filename = "config.ini" ) {
		self::$filename = $filename;
        $this->loadFromFile();
    }
    
    private static function loadFromFile() {
		self::$rcfg = is_readable( self::$filename )
			? parse_ini_file( self::$filename, true )
			: array();
    }
    
    public static function read( $section, $key, $def = "" ) {
		if( !isset( self::$rcfg ) ) self::loadFromFile();
        return isset( self::$rcfg[$section][$key] )
			? self::$rcfg[$section][$key]
			: $def;
    }
    
    public static function write( $section, $key, $value ) {
        if( is_bool( $value ) ) $value = $value ? 1 : 0;
        self::$wcfg[$section][$key] = $value;
    }
	
    public static function updateFile() {
		if( empty( self::$wcfg ) ) return;
		if( !isset( self::$rcfg ) ) self::loadFromFile();
		self::$rcfg = array_replace_recursive( self::$rcfg, self::$wcfg );
        $result = "";
        foreach( self::$rcfg as $sname => $section ) {
            $result .= '[' . $sname . ']' . _BR_;
            foreach( $section as $key => $value ) {
                $result .= $key .'="'.str_replace('\\', '\\\\', $value) .'"'._BR_;
            }
            $result .= _BR_;
        }
		Log::append( file_put_contents( self::$filename, $result )
			? 'Настройки успешно сохранены в файл.'
			: 'Не удалось записать настройки в файл.'
		);
    }
    
    //~ public function eraseSection( $section ) {
        //~ if( isset( self::$wcfg[$section] ) )
            //~ unset( self::$wcfg[$section] );
    //~ }
    
    //~ public function deleteKey( $section, $key ) {
        //~ if( isset( self::$wcfg[$section][$key] ) )
            //~ unset( self::$wcfg[$section][$key] );
    //~ }
    
    //~ public function readSections( &$array ) {
        //~ $array = array_keys( self::$rcfg );
        //~ return $array;
    //~ }
    
    //~ public function readKeys( $section, &$array ) {
        //~ if( isset( self::$rcfg[$section] ) ) {
            //~ $array = array_keys( self::$rcfg[$section] );
            //~ return $array;
        //~ }
        //~ return array();
    //~ }
    
}

function write_config($filename, $cfg, $subsections, $tcs){

	parse_str($cfg);
	$ini = new TIniFileEx($filename);
	
	// т.-клиенты
	if(is_array($tcs)){
		$q = 0;
		foreach($tcs as $id => $tc){
			$q++;
			$ini->write( "torrent-client-$q", 'id', $id );
			if( !empty($tc['cm']) )	$ini->write( "torrent-client-$q", 'comment', $tc['cm'] );
			if( !empty($tc['cl']) ) $ini->write( "torrent-client-$q", 'client', $tc['cl'] );
			if( !empty($tc['ht']) ) $ini->write( "torrent-client-$q", 'hostname', $tc['ht'] );
			if( !empty($tc['pt']) ) $ini->write( "torrent-client-$q", 'port', $tc['pt'] );
			if( isset($tc['lg']) ) $ini->write( "torrent-client-$q", 'login', $tc['lg'] );
			if( isset($tc['pw']) ) $ini->write( "torrent-client-$q", 'password', $tc['pw'] );
		}
		$ini->write('other', 'qt', $q); // кол-во т.-клиентов
	}
	
	// статусы раздач
	if(isset($topics_status)) $ini->write('sections','topics_status',implode(',', $topics_status));
	
	// регулировка раздач
	if( is_numeric( $peers ) ) $ini->write( 'topics_control', 'peers', $peers );
	$ini->write( 'topics_control', 'leechers', isset( $leechers ) ? 1 : 0 );
	$ini->write( 'topics_control', 'no_leechers', isset( $no_leechers ) ? 1 : 0 );
	
	// прокси
	$ini->write('proxy', 'activate', isset($proxy_activate) ? 1 : 0);
	if(isset($proxy_type)) $ini->write('proxy','type',$proxy_type);
	if(isset($proxy_hostname)) $ini->write('proxy','hostname',$proxy_hostname);
	if(isset($proxy_port)) $ini->write('proxy','port',$proxy_port);
	if(isset($proxy_login)) $ini->write('proxy','login',$proxy_login);
	if(isset($proxy_paswd)) $ini->write('proxy','password',$proxy_paswd);
	
	// подразделы
	if(is_array($subsections)){
		foreach($subsections as $subsec){
			if(isset($subsec['na']) && $subsec['na'] != '') $ini->write($subsec['id'],'title',$subsec['na']);
			if(isset($subsec['cl'])) $ini->write($subsec['id'],'client',!empty($subsec['cl']) ? $subsec['cl'] : '');
			if(isset($subsec['lb'])) $ini->write($subsec['id'],'label',$subsec['lb']);
			if(isset($subsec['fd'])) $ini->write($subsec['id'],'data-folder',$subsec['fd']);
			if( !empty($subsec['ln']) ) $ini->write( $subsec['id'],'link',$subsec['ln'] );
		}
		$ini->write('sections','subsections', implode(',', array_column_common($subsections, 'id')));	
	}
	
	// кураторы
	if(isset($dir_torrents)) $ini->write('curators','dir_torrents',$dir_torrents);
	if(isset($passkey)) $ini->write('curators','user_passkey',$passkey);
	
	if( !empty( $TT_login ) ) $ini->write( 'torrent-tracker', 'login', $TT_login );
	if( !empty( $TT_password ) ) $ini->write( 'torrent-tracker', 'password', $TT_password );
	if( !empty( $user_id ) ) $ini->write( 'torrent-tracker', 'user_id', $user_id );
	if( !empty( $bt_key ) ) $ini->write( 'torrent-tracker', 'bt_key', $bt_key );
	if( !empty( $api_key ) ) $ini->write( 'torrent-tracker', 'api_key', $api_key );
	if( !empty( $api_url ) ) $ini->write( 'torrent-tracker', 'api_url', $api_url );
	if( !empty( $forum_url ) ) $ini->write( 'torrent-tracker', 'forum_url', $forum_url );
	if( !empty( $TT_rule_topics ) ) $ini->write( 'sections', 'rule_topics', $TT_rule_topics );
	if( !empty( $TT_rule_reports ) ) $ini->write( 'sections', 'rule_reports', $TT_rule_reports );
	if( !empty( $avg_seeders_period ) ) $ini->write( 'sections', 'avg_seeders_period', $avg_seeders_period );
	if(isset($savedir)) $ini->write('download','savedir',$savedir);
	$ini->write('download','savesubdir',isset($savesubdir) ? 1 : 0);
	$ini->write('sections', 'avg_seeders',isset($avg_seeders) ? 1 : 0);
	$ini->write('download','retracker',isset($retracker) ? 1 : 0);
	
	echo $ini->updateFile(); // обновление файла с настройками
}

function get_settings( $filename = 'config.ini' ){
	
	$config = array();
	
	$ini = new TIniFileEx( dirname(__FILE__) . '/' . $filename );
	
	// торрент-клиенты
	$qt = $ini->read('other','qt','0');
	for($i = 1; $i <= $qt; $i++){
		$id = $ini->read( "torrent-client-$i", "id", $i );
		$config['clients'][$id]['cm'] = $ini->read("torrent-client-$i","comment","");
		$config['clients'][$id]['cl'] = $ini->read("torrent-client-$i","client","");
		$config['clients'][$id]['ht'] = $ini->read("torrent-client-$i","hostname","");
		$config['clients'][$id]['pt'] = $ini->read("torrent-client-$i","port","");
		$config['clients'][$id]['lg'] = $ini->read("torrent-client-$i","login","");
		$config['clients'][$id]['pw'] = $ini->read("torrent-client-$i","password","");
	}
	if ( isset( $config['clients'] ) && is_array( $config['clients'] ) ) {
		uksort($config['clients'], function($a, $b) {
			return strnatcasecmp($a, $b);
		});
	}
	
	// подразделы
	$config['subsec'] = $ini->read('sections','subsections','');
	if( !empty($config['subsec']) ) {
		$subsections = explode( ',', $config['subsec'] );
		$titles = Db::query_database(
			"SELECT id,na FROM Forums WHERE id IN (${config['subsec']})",
			array(), true, PDO::FETCH_KEY_PAIR
		);
	}
	if(isset($subsections)){
		foreach($subsections as $id){
			$config['subsections'][$id]['cl'] = $ini->read("$id","client","utorrent");
			$config['subsections'][$id]['lb'] = $ini->read("$id","label","");
			$config['subsections'][$id]['df'] = $ini->read("$id","data-folder","");
			$config['subsections'][$id]['ln'] = $ini->read("$id","link","");
			$config['subsections'][$id]['id'] = $id;
			$config['subsections'][$id]['na'] = isset( $titles[$id] )
				? $titles[$id]
				: $ini->read( "$id", "title", "$id" );
		}
		uasort($config['subsections'], function($a, $b){
			return strnatcasecmp($a['na'], $b['na']);
		});
	}
	
	// раздачи
	$config['rule_topics'] = $ini->read('sections','rule_topics',3);
	$config['rule_reports'] = $ini->read('sections','rule_reports',10);
	$config['avg_seeders'] = $ini->read('sections','avg_seeders',0);
	$config['avg_seeders_period'] = $ini->read('sections','avg_seeders_period',14);
	$config['topics_status'] = explode(',', $ini->read('sections','topics_status','2,8'));
	
	// регулировка раздач
	$config['topics_control']['peers'] = $ini->read( 'topics_control', 'peers', 10 );
	$config['topics_control']['leechers'] = $ini->read( 'topics_control', 'leechers', 0 );
	$config['topics_control']['no_leechers'] = $ini->read( 'topics_control', 'no_leechers', 1 );
	
	// прокси
	$config['proxy_activate'] = $ini->read('proxy','activate',0);
	$config['proxy_type'] = $ini->read('proxy','type','http');
	$config['proxy_hostname'] = $ini->read('proxy','hostname','195.82.146.100');
	$config['proxy_port'] = $ini->read('proxy','port',3128);
	$config['proxy_login'] = $ini->read('proxy','login','');
	$config['proxy_paswd'] = $ini->read('proxy','password','');
	$config['proxy_address'] = $config['proxy_hostname'] . ':' . $config['proxy_port'];
	$config['proxy_auth'] = $config['proxy_login'] . ':' . $config['proxy_paswd'];
	
	// авторизация
	$config['tracker_login'] = $ini->read('torrent-tracker','login','');
	$config['tracker_paswd'] = $ini->read('torrent-tracker','password','');
	$config['bt_key'] = $ini->read('torrent-tracker','bt_key','');
	$config['api_key'] = $ini->read('torrent-tracker','api_key','');
	$config['api_url'] = $ini->read('torrent-tracker','api_url','http://api.t-ru.org');
	$config['user_id'] = $ini->read('torrent-tracker','user_id','');
	$config['forum_url'] = $ini->read('torrent-tracker','forum_url','http://rutracker.cr');
	
	// загрузки
	$config['save_dir'] = $ini->read('download','savedir','C:\Temp\\');
	$config['savesub_dir'] = $ini->read('download','savesubdir',0);
	$config['retracker'] = $ini->read('download','retracker',0);
	
	// кураторы
	$config['dir_torrents'] = $ini->read('curators','dir_torrents','C:\Temp\\');
	$config['user_passkey'] = $ini->read('curators','user_passkey','');
	
	// установка настроек прокси
	Proxy::options( $config['proxy_activate'], $config['proxy_type'], $config['proxy_address'], $config['proxy_auth'] );
	
	// получение bt_key, api_key, user_id
	if( !empty($config['tracker_login']) && !empty($config['tracker_paswd']) ) {
		if( empty($config['bt_key']) || empty($config['api_key']) || empty($config['user_id']) ) {
			UserDetails::get_details( $config['forum_url'], $config['tracker_login'], $config['tracker_paswd'] );
			$ini->write( 'torrent-tracker', 'bt_key', UserDetails::$bt );
			$ini->write( 'torrent-tracker', 'api_key', UserDetails::$api );
			$ini->write( 'torrent-tracker', 'user_id', UserDetails::$uid );
			$ini->updateFile();
			$config['bt_key'] = UserDetails::$bt;
			$config['api_key'] = UserDetails::$api;
			$config['user_id'] = UserDetails::$uid;
		}
	}
	
	return $config;
	
}

function convert_bytes($size) {
    $filesizename = array(" Bytes", " KB", " MB", " GB", " TB", " PB", " EB", " ZB", " YB");
	$i = $size >= pow(1024,4) ? 3 : floor(log($size, 1024));
	return $size ? round($size / pow(1024, $i), 2) . $filesizename[$i] : '0';
}

function rmdir_recursive($dir, $basedir = false) {
    foreach(scandir($dir) as $file) {
        if ('.' === $file || '..' === $file) continue;
        if (is_dir("$dir/$file")) rmdir_recursive("$dir/$file");
        else unlink("$dir/$file");
    }
    $basedir ? rmdir($dir) : false;
}

function array_column_common(array $input, $columnKey, $indexKey = null) {
	$array = array();
	foreach ($input as $value) {
		if ( ! isset($value[$columnKey])) {
			trigger_error("Key \"$columnKey\" does not exist in array");
			return false;
		}
		if (is_null($indexKey)) {
			$array[] = $value[$columnKey];
		}
		else {
			if ( ! isset($value[$indexKey])) {
				trigger_error("Key \"$indexKey\" does not exist in array");
				return false;
			}
			if ( ! is_scalar($value[$indexKey])) {
				trigger_error("Key \"$indexKey\" does not contain scalar value");
				return false;
			}
			$array[$value[$indexKey]] = $value[$columnKey];
		}
	}
	return $array;
}

// установка параметров прокси
class Proxy {
	
	public static $proxy = array();
	
	protected static $auth;
	protected static $type;
	protected static $address;
	
	private static $types = array( 'http' => 0, 'socks4' => 4, 'socks4a' => 6, 'socks5' => 5 );
	
	public static function options ( $active = false, $type = "http", $address = "", $auth = "" ) {
		self::$type = (array_key_exists($type, self::$types) ? self::$types[$type] : null );
		self::$address = (in_array(null, explode(':', $address)) ? null : $address);
		self::$auth = (in_array(null, explode(':', $auth)) ? null : $auth);
		self::$proxy = $active ? self::set_proxy() : array();
		Log::append ( $active
			? 'Используется ' . mb_strtoupper ( $type ) . '-прокси: "' . $address . '".'
			: 'Прокси-сервер не используется.'
		);
	}
	
	private static function set_proxy () {
		return array(
			CURLOPT_PROXYTYPE => self::$type,
			CURLOPT_PROXY => self::$address,
			CURLOPT_PROXYUSERPWD => self::$auth
		);
	}
	
}

?>
