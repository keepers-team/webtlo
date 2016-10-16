<?php

if (!defined('_BR_'))		//*.ini file access, http://develstudio.ru/php-orion/articles/rabotaem-s-fajlami-ini-v-php
	define('_BR_',chr(13).chr(10));
class TIniFileEx {
    public $filename;
    public $arr;
    function __construct($file = false){
        if ($file)
            $this->loadFromFile($file);
    }
    function initArray(){
        $this->arr = parse_ini_file($this->filename, true);
    }
    function loadFromFile($file){
        $result = true;
        $this->filename = $file;
        if (file_exists($file) && is_readable($file)){
            $this->initArray();
        }
        else
            $result = false;
        return $result;
    }
    function read($section, $key, $def = ''){
        if (isset($this->arr[$section][$key])){
            return $this->arr[$section][$key];
        } else
            return $def;
    }
    function write($section, $key, $value){
        if (is_bool($value))
            $value = $value ? 1 : 0;
        $this->arr[$section][$key] = $value;
		if(true)return true;
		else return false;
    }
    function eraseSection($section){
        if (isset($this->arr[$section]))
            unset($this->arr[$section]);
    }
    function deleteKey($section, $key){
        if (isset($this->arr[$section][$key]))
            unset($this->arr[$section][$key]);
    }
    function readSections(&$array){
        $array = array_keys($this->arr);
        return $array;
    }
    function readKeys($section, &$array){
        if (isset($this->arr[$section])){
            $array = array_keys($this->arr[$section]);
            return $array;
        }
        return array();
    }
    function updateFile(){
        $result = '';
        foreach ($this->arr as $sname=>$section){
            $result .= '[' . $sname . ']' . _BR_;
            foreach ($section as $key=>$value){
                $result .= $key .'="'.str_replace('\\', '\\\\', $value) .'"'._BR_;
            }
            $result .= _BR_;
        }
		if(file_put_contents($this->filename, $result)) return get_now_datetime() . 'Настройки успешно сохранены.<br />';
		else return get_now_datetime() . 'Ошибка при сохранении настроек.<br />';
    }
}

function write_config($filename, $cfg, $subsections, $tcs){

	parse_str($cfg);
	$ini = new TIniFileEx($filename);
	
	// т.-клиенты
	if(is_array($tcs)){
		$q = 0;
		foreach($tcs as $cm => $tc){
			$q++;
			if(isset($tc['cl'])	&& $tc['cl'] != '') $ini->write("torrent-client-$q",'client',$tc['cl']);
			if(isset($tc['ht'])	&& $tc['ht'] != '') $ini->write("torrent-client-$q",'hostname',$tc['ht']);
			if(isset($tc['pt'])	&& $tc['pt'] != '') $ini->write("torrent-client-$q",'port',$tc['pt']);
			if(isset($tc['lg'])) $ini->write("torrent-client-$q",'login',$tc['lg']);
			if(isset($tc['pw'])) $ini->write("torrent-client-$q",'password',$tc['pw']);
			if(isset($tc['cm'])	&& $tc['cm'] != '')	$ini->write("torrent-client-$q",'comment',$tc['cm']);
		}
		$ini->write('other', 'qt', $q); // кол-во т.-клиентов
	}
	
	// статусы раздач
	if(isset($topics_status)) $ini->write('sections','topics_status',implode(',', $topics_status));
	
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
			if(isset($subsec['ln'])) $ini->write($subsec['id'],'link',$subsec['ln']);
		}
		$ini->write('sections','subsections', implode(',', array_column_common($subsections, 'id')));	
	}
	
	// кураторы
	if(isset($dir_torrents)) $ini->write('curators','dir_torrents',$dir_torrents);
	if(isset($passkey)) $ini->write('curators','user_passkey',$passkey);
	
	if(isset($TT_login) && $TT_login != '') $ini->write('torrent-tracker','login',$TT_login);
	if(isset($TT_password) && $TT_password != '') $ini->write('torrent-tracker','password',$TT_password);
	if(isset($bt_key) && $bt_key != '') $ini->write('torrent-tracker','bt_key',$bt_key);
	if(isset($api_key) && $api_key != '') $ini->write('torrent-tracker','api_key',$api_key);
	if(isset($api_url) && $api_url != '') $ini->write('torrent-tracker','api_url',$api_url);
	if(isset($forum_url) && $forum_url != '') $ini->write('torrent-tracker','forum_url',$forum_url);
	if(isset($TT_rule_topics) && $TT_rule_topics != '') $ini->write('sections','rule_topics',$TT_rule_topics);
	if(isset($TT_rule_reports) && $TT_rule_reports != '') $ini->write('sections','rule_reports',$TT_rule_reports);
	if(isset($avg_seeders_period) && $avg_seeders_period != '') $ini->write('sections','avg_seeders_period',$avg_seeders_period);
	if(isset($savedir)) $ini->write('download','savedir',$savedir);
	$ini->write('download','savesubdir',isset($savesubdir) ? 1 : 0);
	$ini->write('sections', 'avg_seeders',isset($avg_seeders) ? 1 : 0);
	$ini->write('download','retracker',isset($retracker) ? 1 : 0);
	
	echo $ini->updateFile(); // обновление файла с настройками
}

function get_settings(){
	
	$config = array();
	
	$ini = new TIniFileEx(dirname(__FILE__) . '/config.ini');
	
	// торрент-клиенты
	$qt = $ini->read('other','qt','0');
	for($i = 1; $i <= $qt; $i++){
		$comment = $ini->read("torrent-client-$i","comment","");
		$config['clients'][$comment]['cm'] = $comment;
		$config['clients'][$comment]['cl'] = $ini->read("torrent-client-$i","client","");
		$config['clients'][$comment]['ht'] = $ini->read("torrent-client-$i","hostname","");
		$config['clients'][$comment]['pt'] = $ini->read("torrent-client-$i","port","");
		$config['clients'][$comment]['lg'] = $ini->read("torrent-client-$i","login","");
		$config['clients'][$comment]['pw'] = $ini->read("torrent-client-$i","password","");
	}
	
	// подразделы
	$config['subsections_line'] = $ini->read('sections','subsections','');
	if(!empty($config['subsections_line'])) $subsections = explode(',', $config['subsections_line']);
	if(isset($subsections)){
		foreach($subsections as $id){
			$config['subsections'][$id]['title'] = $ini->read("$id","title","");
			$config['subsections'][$id]['client'] = $ini->read("$id","client","utorrent");
			$config['subsections'][$id]['label'] = $ini->read("$id","label","");
			$config['subsections'][$id]['data-folder'] = $ini->read("$id","data-folder","");
			$config['subsections'][$id]['link'] = $ini->read("$id","link","");
		}
	}
	
	// раздачи
	$config['rule_topics'] = $ini->read('sections','rule_topics',3);
	$config['rule_reports'] = $ini->read('sections','rule_reports',10);
	$config['avg_seeders'] = $ini->read('sections','avg_seeders',0);
	$config['avg_seeders_period'] = $ini->read('sections','avg_seeders_period',14);
	$config['topics_status'] = explode(',', $ini->read('sections','topics_status','2,8'));
	
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
	$config['api_url'] = $ini->read('torrent-tracker','api_url','http://api.rutracker.cc');
	$config['forum_url'] = $ini->read('torrent-tracker','forum_url','http://rutracker.cr');
	
	// загрузки
	$config['save_dir'] = $ini->read('download','savedir','C:\Temp\\');
	$config['savesub_dir'] = $ini->read('download','savesubdir',0);
	$config['retracker'] = $ini->read('download','retracker',0);
	
	// кураторы
	$config['dir_torrents'] = $ini->read('curators','dir_torrents','C:\Temp\\');
	$config['user_passkey'] = $ini->read('curators','user_passkey','');
	
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

// получить текущую дату
function get_now_datetime() {
	return date('d.m.Y H:i:s') . ' ';
}

?>
