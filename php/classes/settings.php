<?php

// http://develstudio.ru/php-orion/articles/rabotaem-s-fajlami-ini-v-php
if (!defined('_BR_')) {
    define('_BR_', chr(13) . chr(10));
}

class TIniFileEx
{

    protected static $rcfg;
    protected static $wcfg;

    public static $filename;

    public function __construct($filename = "")
    {
        if (!empty($filename)) {
            self::$filename = dirname(__FILE__) . "/../../data/$filename";
        }
        $this->loadFromFile();
    }

    private static function loadFromFile()
    {
        if (empty(self::$filename)) {
            self::$filename = dirname(__FILE__) . "/../../data/config.ini";
        }
        self::$rcfg = is_readable(self::$filename) ? parse_ini_file(self::$filename, true) : array();
    }

    public static function read($section, $key, $def = "")
    {
        if (!isset(self::$rcfg)) {
            self::loadFromFile();
        }
        return isset(self::$rcfg[$section][$key]) ? self::$rcfg[$section][$key] : $def;
    }

    public static function write($section, $key, $value)
    {
        if (is_bool($value)) {
            $value = $value ? 1 : 0;
        }
        self::$wcfg[$section][$key] = $value;
    }

    public static function updateFile()
    {
        if (empty(self::$wcfg)) {
            return;
        }
        if (!isset(self::$rcfg)) {
            self::loadFromFile();
        }
        self::$rcfg = array_replace_recursive(self::$rcfg, self::$wcfg);
        $result = "";
        foreach (self::$rcfg as $sname => $section) {
            $result .= '[' . $sname . ']' . _BR_;
            foreach ($section as $key => $value) {
                $result .= $key . '="' . str_replace('\\', '\\\\', $value) . '"' . _BR_;
            }
            $result .= _BR_;
        }
        Log::append(file_put_contents(self::$filename, $result)
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
