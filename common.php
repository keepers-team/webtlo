<?php
/*
 * web-TLO (Web Torrent List Organizer)
 * common.php
 * author: Cuser (cuser@yandex.ru)
 * previous change: 30.04.2014
 * editor: berkut_174 (webtlo@yandex.ru)
 * last change: 11.02.2016
 */

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
                $result .= $key .'='.$value . _BR_;
            }
            $result .= _BR_;
        }
		if(file_put_contents($this->filename, $result)) return date("H:i:s") . ' Настройки успешно сохранены.<br />';
		else return date("H:i:s") . ' Ошибка при сохранении настроек.<br />';
		//~ return false;
    }
    function __destruct(){
        //~ if($this->updateFile())return date("H:i:s") . ' Настройки успешно сохранены.<br />';
		//~ else return date("H:i:s") . ' Ошибка при сохранении настроек.<br />';
    }
}

function write_config(
		$cfg, &$lg, &$pw, &$ss, &$rt, &$rr, &$sdir, &$ssdir, $retracker,
		$tcs, $bt_key, $api_key, $api_url, $tor_status, $proxy_activate,
		$proxy_type, $proxy_address, $proxy_auth
	){

	$q = 0;
	$ini = new TIniFileEx($cfg);
	//~ if($cfg == 'config.ini'){
		// т.-клиенты
		foreach($tcs as $cm => $tc){
			$q++;
			if(isset($tc['cl'])	&& $tc['cl'] != '') $ini->write("torrent-client-$q",'client',		$tc['cl']);
			if(isset($tc['ht'])	&& $tc['ht'] != '') $ini->write("torrent-client-$q",'hostname',		$tc['ht']);
			if(isset($tc['pt'])	&& $tc['pt'] != '') $ini->write("torrent-client-$q",'port',			$tc['pt']);
			if(isset($tc['lg'])) $ini->write("torrent-client-$q",'login',							$tc['lg']);
			if(isset($tc['pw'])) $ini->write("torrent-client-$q",'password',						$tc['pw']);
			if(isset($tc['cm'])	&& $tc['cm'] != '')	$ini->write("torrent-client-$q",'comment',		$tc['cm']);
		}
		$ini->write('other', 'qt', $q); // кол-во т.-клиентов
		foreach($tor_status as $status => $value){
			if(isset($value)) $ini->write("tor_status", $status, $value);
		}
		// прокси
		if(isset($proxy_activate)) $ini->write('proxy', 'activate', $proxy_activate);
		if(isset($proxy_type)) $ini->write('proxy', 'type', $proxy_type);
		list($proxy_hostname, $proxy_port) = explode(":", $proxy_address);
		list($proxy_login, $proxy_paswd) = explode(":", $proxy_auth);
		if(isset($proxy_hostname)) $ini->write('proxy','hostname',	$proxy_hostname);
		if(isset($proxy_port)) $ini->write('proxy','port', $proxy_port);
		if(isset($proxy_login)) $ini->write('proxy','login', $proxy_login);
		if(isset($proxy_paswd)) $ini->write('proxy','password', $proxy_paswd);		
		
		if(isset($lg) && $lg != '') $ini->write('torrent-tracker','login',		$lg);
		if(isset($pw) && $pw != '') $ini->write('torrent-tracker','password',	$pw);
		if(isset($bt_key) && $bt_key != '') $ini->write('torrent-tracker','bt_key', $bt_key);
		if(isset($api_key) && $api_key != '') $ini->write('torrent-tracker','api_key', $api_key);
		if(isset($api_url) && $api_url != '') $ini->write('torrent-tracker','api_url', $api_url);
		if(isset($ss) && $ss != '') $ini->write('sections','subsections',		$ss);
		if(isset($rt) && $rt != '') $ini->write('sections','rule_topics',		$rt);
		if(isset($rr) && $rr != '') $ini->write('sections','rule_reports',		$rr);
		if(isset($sdir) && $sdir != '') $ini->write('download','savedir',		$sdir);
		if(isset($ssdir)) $ini->write('download','savesubdir', $ssdir);
		if(isset($retracker)) $ini->write('download','retracker', $retracker);
	//~ }
	echo $ini->updateFile(); // обновление файла с настройками
}

function convert_bytes($size) {
    $filesizename = array(" Bytes", " KB", " MB", " GB", " TB", " PB", " EB", " ZB", " YB");
	return $size ? round($size / pow(1024, ($i = floor(log($size, 1024)))), 2) . $filesizename[$i] : '0';
}


function untichunk($data){	//с небольшими изменениями, спасибо vmunt (http://phpforum.ru/txt/index.php/t58204.html)

	$buffer='';
	$pos=-2;
	while (($size = hexdec(substr($data,$pos+2,($pnext=strpos($data, "\r\n", $pos+2)+2)-$pos-4))) != 0)
					{
						$buffer.=substr($data, $pnext, $size);
						$pos = $pnext+$size;
					}
	return $buffer;
}

?>
