<?php

use KeepersTeam\Webtlo\Helper;
use KeepersTeam\Webtlo\Config\Credentials;

include_once dirname(__FILE__) . '/../phpQuery.php';

final class UserDetails
{
    /** Ключи для авторизации. Строки, чтобы не переписывать проверки на фронте. */
    public static string $bt;
    public static string $api;
    public static string $uid;
    public static string $cookie;

    public static array  $captcha = [];
    public static string $captcha_path = '';

    public static string $forum_url;
    public static string $form_token;

    private static bool $backendRequest = false;

    /** Проверить наличие данных авторизации или авторизоваться если нужно.
     *
     * @throws Exception
     */
    public static function checkSession(string $forum_url, Credentials $user): Credentials
    {
        self::$backendRequest = true;

        if (null === $user->session) {
            // Авторизуемся на форуме.
            self::get_details($forum_url, $user->userName, $user->password);

            Log::append('Notice: Отсутствуют данные сессии. Пройдите авторизацию в настройках.');
            // Перезаписываем данные авторизации для текущего процесса.
            $user = new Credentials(
                $user->userName,
                $user->password,
                self::$uid,
                self::$bt,
                self::$api,
                self::$cookie
            );
        } else {
            self::$forum_url = $forum_url;
            self::$uid       = $user->userId;
            self::$api       = $user->apiKey;
            self::$bt        = $user->btKey;
            self::$cookie    = $user->session;
        }

        return $user;
    }

    /**
     * @throws Exception
     */
    public static function get_details($forum_url, $login, $passwd, $cap_fields = []): void
    {
        self::$forum_url = $forum_url;
        self::get_cookie($login, $passwd, $cap_fields);
        self::get_keys();
    }

    /**
     * @throws Exception
     */
    public static function make_request($url, $fields = [], $options = [])
    {
        $ch = curl_init();
        curl_setopt_array($ch, $options);
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => 1,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_POSTFIELDS => http_build_query($fields),
            CURLOPT_USERAGENT => "Mozilla/5.0 (Windows NT 6.1) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/55.0.2883.87 Safari/537.36",
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CONNECTTIMEOUT => 20,
            CURLOPT_TIMEOUT => 20,
        ]);
        curl_setopt_array($ch, Proxy::$proxy['forum']);
        $try_number = 1; // номер попытки
        $try = 3; // кол-во попыток
        while (true) {
            $data = curl_exec($ch);
            if ($data === false) {
                $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                if (
                    $http_code < 300
                    && $try_number <= $try
                ) {
                    Log::append("Повторная попытка $try_number/$try получить данные.");
                    sleep(5);
                    $try_number++;
                    continue;
                }
                throw new Exception("CURL ошибка: " . curl_error($ch) . " [$http_code]");
            }
            return $data;
        }
    }

    /**
     * @throws Exception
     */
    public static function get_cookie($login, $passwd, $cap_fields = []): void
    {
        $passwd = mb_convert_encoding($passwd, 'Windows-1251', 'UTF-8');
        $login = mb_convert_encoding($login, 'Windows-1251', 'UTF-8');
        $fields = [
            'login_username' => "$login",
            'login_password' => "$passwd",
            'login' => 'Вход',
        ];
        $fields += $cap_fields;
        $data = self::make_request(
            self::$forum_url . '/forum/login.php',
            $fields,
            [CURLOPT_HEADER => 1]
        );
        preg_match("|.*bb_session=[^-]*-([0-9]*)|", $data, $uid);
        preg_match("|.*(bb_session=[^;]*);.*|", $data, $cookie);
        if (
            empty($uid[1])
            || empty($cookie[1])
        ) {
            if (self::$backendRequest) {
                throw new Exception('Error: Не удалось авторизоваться на форуме. Пройдите авторизацию в настройках.');
            }
            preg_match('|<title> *(.*)</title>|si', $data, $title);
            if (!empty($title)) {
                if ($title[1] == 'rutracker.org') {
                    preg_match('|<h4[^>]*?>(.*)</h4>|si', $data, $text);
                    if (!empty($text)) {
                        $html = phpQuery::newDocumentHTML($data, 'UTF-8');
                        $captcha = $html->find('div.mrg_16 > table tr')->eq(2);
                        unset($html);
                        if (!empty($captcha)) {
                            $captcha = pq($captcha);
                            $sourcePath = $captcha->find('img')->attr('src');
                            if (!self::get_captcha($sourcePath)) {
                                throw new Exception('Error: Не удалось получить изображение капчи, ' . $sourcePath);
                            }
                            self::$captcha_path = $sourcePath;
                            foreach ($captcha->find('input') as $input) {
                                $input = pq($input);
                                self::$captcha[] = $input->attr('name');
                                self::$captcha[] = $input->val();
                            }
                        }
                        unset($captcha);
                        Log::append('Error: ' . $title[1] . ' - ' . mb_convert_encoding($text[1], 'UTF-8', 'Windows-1251') . '.');
                        phpQuery::unloadDocuments();
                    }
                } else {
                    Log::append('Error: ' . mb_convert_encoding($title[1], 'UTF-8', 'Windows-1251') . '.');
                }
            }
            throw new Exception('Error: Не удалось авторизоваться на форуме.');
        }
        self::$uid = $uid[1];
        self::$cookie = $cookie[1];
    }

    /**
     * @throws Exception
     */
    public static function get_keys(): void
    {
        $keys = '';
        $data = self::make_request(
            self::$forum_url . '/forum/profile.php?u=' . self::$uid,
            ['mode' => 'viewprofile'],
            [CURLOPT_COOKIE => self::$cookie]
        );
        $html = phpQuery::newDocumentHTML($data, 'UTF-8');
        $rows = $html->find('table.user_details');
        unset($html);
        $rows = pq($rows);
        foreach ($rows->find('tr') as $row) {
            $row = pq($row);
            $title = $row->find('th')->text();
            if ($title == 'Хранительские ключи:') {
                $keys = $row->find('td')->text();
                unset($row);
                break;
            }
        }
        unset($rows);
        preg_match('|.*bt: ([^ ]+).*api: ([^ ]+)|', $keys, $keys);
        self::$bt = $keys[1];
        self::$api = $keys[2];
    }

    /**
     * @throws Exception
     */
    public static function get_form_token(): void
    {
        $data = self::make_request(
            self::$forum_url . '/forum/profile.php?u=' . self::$uid,
            ['mode' => 'viewprofile'],
            [CURLOPT_COOKIE => self::$cookie]
        );
        $html = phpQuery::newDocumentHTML($data, 'UTF-8');
        preg_match("|.*form_token[^']*'([^,]*)',.*|si", $html->find('script:first'), $form_token);
        if (empty($form_token[1])) {
            throw new Exception('Error: Не получен form_token.');
        }

        self::$form_token = $form_token[1];
    }

    /**
     * @throws Exception
     */
    public static function get_captcha($url): bool
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => 1,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => "Mozilla/5.0 (Windows NT 6.1) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/55.0.2883.87 Safari/537.36",
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CONNECTTIMEOUT => 2,
        ]);
        $sourceData = curl_exec($ch);
        curl_close($ch);

        if (!$sourceData || !strlen($sourceData)) {
            return false;
        }
        $targetPath = Helper::getStorageDir() . DIRECTORY_SEPARATOR . 'captcha.jpg';

        $targetData = file_put_contents($targetPath, $sourceData);
        if ($targetData === false) {
            throw new Exception('Error: Не удалось сохранить изображение капчи');
        }

        return true;
    }
}
