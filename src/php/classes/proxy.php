<?php

// установка параметров прокси
class Proxy
{
    public static $proxy = [
        'forum' => [],
        'api' => [],
    ];

    protected static $auth;
    protected static $type;
    protected static $address;

    private static $types = ['http' => 0, 'socks4' => 4, 'socks4a' => 6, 'socks5' => 5, 'socks5h' => 7];

    public static function options($activate_forum, $activate_api, $type, $address = "", $auth = "")
    {
        self::$type = array_key_exists($type, self::$types) ? self::$types[$type] : null;
        self::$address = in_array(null, explode(':', $address)) ? null : $address;
        self::$auth = in_array(null, explode(':', $auth)) ? null : $auth;
        if (
            $activate_forum
            || $activate_api
        ) {
            self::$proxy = self::set_proxy($activate_forum, $activate_api);
            Log::append(
                'Используется ' . mb_strtoupper($type) . '-прокси: "' . $address .
                    '" для форума(' . $activate_forum . ') и API(' . $activate_api . ')'
            );
        } else {
            Log::append('Прокси-сервер не используется.');
        }
    }

    private static function set_proxy($activate_forum, $activate_api)
    {
        $param = [
            CURLOPT_PROXYTYPE => self::$type,
            CURLOPT_PROXY => self::$address,
            CURLOPT_PROXYUSERPWD => self::$auth,
        ];
        $param_forum = $activate_forum ? $param : [];
        $param_api = $activate_api ? $param : [];
        return [
            'forum' => $param_forum,
            'api' => $param_api,
        ];
    }
}
