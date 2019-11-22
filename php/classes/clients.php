<?php

/**
 * Class torrent_client базовый класс торрент клиента
 */
abstract class torrentClient
{

    /**
     * @var string
     */
    protected static $base;

    /**
     * @var string
     */
    public $host;

    /**
     * @var string
     */
    public $port;

    /**
     * @var string
     */
    public $login;

    /**
     * @var string
     */
    public $password;

    /**
     * @var string
     */
    public $comment;

    /**
     * @var string Session ID, полученный от торрент-клиента
     */
    protected $sid;

    /**
     * default constructor.
     * @param string $host
     * @param string $port
     * @param string $login
     * @param string $password
     * @param string $comment
     */
    public function __construct($host = "", $port = "", $login = "", $password = "", $comment = "")
    {
        $this->host = $host;
        $this->port = $port;
        $this->login = $login;
        $this->password = $password;
        $this->comment = $comment;
    }

    /**
     * проверка онлайн ли торрент клиент или нет
     * @return bool
     */
    public function is_online()
    {
        return $this->getSID();
    }

    /**
     * получение идентификатора сессии и запись его в $this->sid
     * @return bool true в случе успеха, false в случае неудачи
     */
    abstract protected function getSID();

    /**
     * получение списка раздач от клиента
     * @return array|bool array[hash] => status,
     * false в случае пустого ответа от клиента
     */
    abstract public function getTorrents();

    /**
     * добавить торрент
     * @param string $torrent_file_path путь до .torrent файла включая имя файла
     * @param string $save_path путь куда сохранять загружаемые данные
     */
    abstract public function torrentAdd($torrent_file_path, $save_path = "");

    /**
     * установка метки
     * @param array $hashes
     * @param string $label
     */
    abstract public function setLabel($hashes, $label = "");

    /**
     * запуск раздач перечисленных в $hash
     * @param array $hashes
     * @param bool $force
     */
    abstract public function torrentStart($hashes, $force = false);

    /**
     * остановка раздач
     * @param array $hashes
     */
    abstract public function torrentStop($hashes);

    /**
     * удаление раздач
     * @param array $hashes
     * @param bool $delete_local_data
     */
    abstract public function torrentRemove($hashes, $delete_local_data = false);

    /**
     * перепроверить локальные данные раздач (unused)
     * @param array $hashes
     */
    abstract public function torrentRecheck($hashes);
}

/**
 * Class utorrent uTorrent 1.8.2 ~ Windows x32
 */
class utorrent extends torrentClient
{

    protected static $base = "http://%s:%s/gui/%s";

    protected $token;
    protected $guid;

    /**
     * получение токена
     * @return bool
     */
    protected function getSID()
    {
        // Log::append ( 'Попытка подключиться к торрент-клиенту "' . $this->comment . '"...' );
        $ch = curl_init();
        curl_setopt_array($ch, array(
            CURLOPT_URL => sprintf(self::$base, $this->host, $this->port, 'token.html'),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERPWD => $this->login . ":" . $this->password,
            CURLOPT_HEADER => true,
        ));
        $output = curl_exec($ch);
        if ($output === false) {
            Log::append('CURL ошибка: ' . curl_error($ch));
            Log::append('Проверьте в настройках правильность введённого IP-адреса и порта для доступа к торрент-клиенту.');
            return false;
        }
        $info = curl_getinfo($ch);
        curl_close($ch);
        $headers = substr($output, 0, $info['header_size']);
        preg_match("|Set-Cookie: GUID=([^;]+);|i", $headers, $matches);
        if (!empty($matches)) {
            $this->guid = $matches[1];
        }
        preg_match('/<div id=\'token\'.+>(.*)<\/div>/', $output, $m);
        if (!empty($m)) {
            $this->token = $m[1];
            return true;
        }
        Log::append('Не удалось подключиться к веб-интерфейсу торрент-клиента.');
        Log::append('Проверьте в настройках правильность введённого логина и пароля для доступа к торрент-клиенту.');
        return false;
    }

    /**
     * выполнение запроса
     * @param $request
     * @param bool $decode
     * @param array $options
     * @return bool|mixed|string
     */
    private function makeRequest($request, $decode = true, $options = array())
    {
        $request = preg_replace('/^\?/', '?token=' . $this->token . '&', $request);
        $ch = curl_init();
        curl_setopt_array($ch, $options);
        curl_setopt_array($ch, array(
            CURLOPT_URL => sprintf(self::$base, $this->host, $this->port, $request),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERPWD => $this->login . ":" . $this->password,
            CURLOPT_COOKIE => "GUID=" . $this->guid,
        ));
        $req = curl_exec($ch);
        if ($req === false) {
            Log::append('CURL ошибка: ' . curl_error($ch));
            return false;
        }
        curl_close($ch);
        return $decode ? json_decode($req, true) : $req;
    }

    public function getTorrents()
    {
        $data = $this->makeRequest("?list=1");
        if (empty($data['torrents'])) {
            return false;
        }
        foreach ($data['torrents'] as $torrent) {
            $status = decbin($torrent[1]);
            // 0 - Started, 2 - Paused, 3 - Error, 4 - Checked, 7 - Loaded, 100% Downloads
            if (!$status{3}) {
                if (
                    $status{0}
                    && $status{4}
                    && $torrent[4] == 1000
                ) {
                    $status = !$status{2} && $status{7} ? 1 : -1;
                } else {
                    $status = 0;
                }
                $torrents[$torrent[0]] = $status;
            }
        }
        return isset($torrents) ? $torrents : array();
    }

    public function torrentAdd($filename, $save_path = "")
    {
        $this->setSetting('dir_active_download_flag', true);
        if (!empty($save_path)) {
            $this->setSetting('dir_active_download', urlencode($save_path));
        }
        $this->makeRequest("?action=add-url&s=" . urlencode($filename), false);
    }

    /**
     * изменение свойств торрента
     * @param $hash
     * @param $property
     * @param $value
     */
    public function setProperties($hash, $property, $value)
    {
        $request = preg_replace('|^(.*)$|', "hash=$0&s=" . $property . "&v=" . urlencode($value), $hash);
        $request = implode('&', $request);
        $this->makeRequest("?action=setprops&" . $request, false);
    }

    /**
     * изменение настроек
     * @param $setting
     * @param $value
     */
    public function setSetting($setting, $value)
    {
        $this->makeRequest("?action=setsetting&s=" . $setting . "&v=" . $value, false);
    }

    /**
     * "склеивание" параметров в строку
     * @param $glue
     * @param $param
     * @return string
     */
    private function paramImplode($glue, $param)
    {
        return $glue . implode($glue, is_array($param) ? $param : array($param));
    }

    public function setLabel($hash, $label = "")
    {
        $this->setProperties($hash, 'label', $label);
    }

    public function torrentStart($hashes, $force = false)
    {
        $this->makeRequest("?action=" . ($force ? "forcestart" : "start") . $this->paramImplode("&hash=", $hashes),
            false);
    }

    /**
     * пауза раздач (unused)
     * @param $hashes
     */
    public function torrentPause($hashes)
    {
        $this->makeRequest("?action=pause" . $this->paramImplode("&hash=", $hashes), false);
    }

    public function torrentRecheck($hashes)
    {
        $this->makeRequest("?action=recheck" . $this->paramImplode("&hash=", $hashes), false);
    }

    public function torrentStop($hashes)
    {
        $this->makeRequest("?action=stop" . $this->paramImplode("&hash=", $hashes), false);
    }

    public function torrentRemove($hashes, $delete_local_data = false)
    {
        $this->makeRequest("?action=" . ($delete_local_data ? "removedata" : "remove") . $this->paramImplode("&hash=", $hashes),
            false);
    }

}

/**
 * Class transmission Transmission 2.94 ~ Linux x32 (режим демона)
 */
class transmission extends torrentClient
{

    protected static $base = "http://%s:%s/transmission/rpc";

    protected function getSID()
    {
        $ch = curl_init();
        curl_setopt_array($ch, array(
            CURLOPT_URL => sprintf(self::$base, $this->host, $this->port),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERPWD => $this->login . ":" . $this->password,
            CURLOPT_HEADER => true,
        ));
        $output = curl_exec($ch);
        if ($output === false) {
            Log::append('CURL ошибка: ' . curl_error($ch));
            Log::append('Проверьте в настройках правильность введённого IP-адреса и порта для доступа к торрент-клиенту.');
            return false;
        }
        curl_close($ch);
        preg_match("|.*\r\n(X-Transmission-Session-Id: .*?)(\r\n.*)|", $output, $sid);
        if (!empty($sid)) {
            $this->sid = $sid[1];
            return true;
        }
        Log::append('Не удалось подключиться к веб-интерфейсу торрент-клиента.');
        Log::append('Проверьте в настройках правильность введённого логина и пароля для доступа к торрент-клиенту.');
        return false;
    }

    /**
     * выполнение запроса
     *
     * @param $fields
     * @param array $options
     * @return bool|mixed|string
     */
    private function makeRequest($fields, $options = array())
    {
        $ch = curl_init();
        curl_setopt_array($ch, $options);
        curl_setopt_array($ch, array(
            CURLOPT_URL => sprintf(self::$base, $this->host, $this->port),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERPWD => $this->login . ":" . $this->password,
            CURLOPT_HTTPHEADER => array($this->sid),
            CURLOPT_POSTFIELDS => json_encode($fields),
        ));
        $i = 1; // номер попытки
        $n = 3; // количество попыток
        while (true) {
            $req = curl_exec($ch);
            if ($req === false) {
                Log::append('CURL ошибка: ' . curl_error($ch));
                curl_close($ch);
                return false;
            }
            $req = json_decode($req, true);
            if ($req['result'] != 'success') {
                if (empty($req['result']) && $i <= $n) {
                    Log::append("Повторная попытка $i/$n выполнить запрос.");
                    sleep(10);
                    $i++;
                    continue;
                }
                $error = empty($req['result']) ? "Неизвестная ошибка" : $req['result'];
                Log::append("Error: $error");
                curl_close($ch);
                return false;
            }
            curl_close($ch);
            return $req;
        }
    }

    public function getTorrents()
    {
        $request = array(
            'method' => 'torrent-get',
            'arguments' => array(
                'fields' => array(
                    'hashString',
                    'status',
                    'error',
                    'percentDone',
                ),
            ),
        );
        $data = $this->makeRequest($request);
        if (empty($data['arguments']['torrents'])) {
            return false;
        }
        foreach ($data['arguments']['torrents'] as $torrent) {
            if (empty($torrent['error'])) {
                if ($torrent['percentDone'] == 1) {
                    $status = $torrent['status'] == 0 ? -1 : 1;
                } else {
                    $status = 0;
                }
                $hash = strtoupper($torrent['hashString']);
                $torrents[$hash] = $status;
            }
        }
        return isset($torrents) ? $torrents : false;
    }

    public function torrentAdd($torrent_file_path, $save_path = "")
    {
        $request = array(
            'method' => 'torrent-add',
            'arguments' => array(
                'metainfo' => base64_encode(file_get_contents($torrent_file_path)),
                'paused' => false,
            ),
        );
        if (!empty($save_path)) {
            $request['arguments']['download-dir'] = $save_path;
        }
        $data = $this->makeRequest($request);
        if (empty($data['arguments'])) {
            return false;
        }
        // if ( ! empty( $data['arguments']['torrent-added'] ) ) {
        //     $hash = $data['arguments']['torrent-added']['hashString']
        //     $success[] = strtoupper( $hash );
        // }
        if (!empty($data['arguments']['torrent-duplicate'])) {
            $hash = $data['arguments']['torrent-duplicate']['hashString'];
            Log::append("Warning: Эта раздача уже раздаётся в торрент-клиенте ($hash).");
        }
        // return $success;
    }

    public function setLabel($hashes, $label = "")
    {
        return 'Торрент-клиент не поддерживает установку меток.';
    }

    public function torrentStart($hashes, $force = false)
    {
        $method = $force ? 'torrent-start-now' : 'torrent-start';
        $request = array(
            'method' => $method,
            'arguments' => array(
                'ids' => $hashes,
            ),
        );
        $data = $this->makeRequest($request);
    }

    public function torrentStop($hashes)
    {
        $request = array(
            'method' => 'torrent-stop',
            'arguments' => array(
                'ids' => $hashes,
            ),
        );
        $data = $this->makeRequest($request);
    }

    public function torrentRecheck($hashes)
    {
        $request = array(
            'method' => 'torrent-verify',
            'arguments' => array(
                'ids' => $hashes,
            ),
        );
        $data = $this->makeRequest($request);
    }

    public function torrentRemove($hashes, $delete_local_data = false)
    {
        $request = array(
            'method' => 'torrent-remove',
            'arguments' => array(
                'ids' => $hashes,
                'delete-local-data' => $delete_local_data,
            ),
        );
        $data = $this->makeRequest($request);
    }

}

// Vuze 5.7.0.0/4 az3 [ plugin Web Remote 0.5.11 ] ~ Linux x32
class_alias('transmission', 'vuze');

/**
 * Class deluge Deluge 1.3.6 [ plugin WebUi 0.1 ] ~ Linux x64
 */
class deluge extends torrentClient
{

    protected static $base = "http://%s:%s/json";

    protected function getSID()
    {
        // Log::append ( 'Попытка подключиться к торрент-клиенту "' . $this->comment . '"...' );
        $ch = curl_init();
        curl_setopt_array($ch, array(
            CURLOPT_URL => sprintf(self::$base, $this->host, $this->port),
            CURLOPT_POSTFIELDS => '{ "method" : "auth.login" , "params" : [ "' . $this->password . '" ], "id" : 2 }',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => 'gzip',
            CURLOPT_HEADER => true,
            CURLOPT_HTTPHEADER => array('Content-Type: application/json'),
        ));
        $output = curl_exec($ch);
        if ($output === false) {
            Log::append('CURL ошибка: ' . curl_error($ch));
            Log::append('Проверьте в настройках правильность введённого IP-адреса и порта для доступа к торрент-клиенту.');
            return false;
        }
        curl_close($ch);
        preg_match("|Set-Cookie: ([^;]+);|i", $output, $sid);
        if (!empty($sid)) {
            $this->sid = $sid[1];
            $webUIIsConnected = $this->makeRequest(
                array(
                    'method' => 'web.connected',
                    'params' => array(),
                    'id' => 7,
                )
            );
            if (!$webUIIsConnected['result']) {
                $firstHost = $this->makeRequest(
                    array(
                        'method' => 'web.get_hosts',
                        'params' => array(),
                        'id' => 7,
                    )
                );
                $firstHostStatus = $this->makeRequest(
                    array(
                        'method' => 'web.get_host_status',
                        'params' => array($firstHost['result'][0][0]),
                        'id' => 7,
                    )
                );
                if ($firstHostStatus['result'][3] === 'Offline') {
                    Log::append('Deluge daemon сейчас недоступен');
                    return false;
                } elseif ($firstHostStatus['result'][3] === 'Online') {
                    $response = $this->makeRequest(
                        array(
                            'method' => 'web.connect',
                            'params' => array($firstHost['result'][0][0]),
                            'id' => 7,
                        )
                    );
                    if ($response['error'] === null) {
                        Log::append('Подключение Deluge webUI к Deluge daemon прошло успешно');
                        return true;
                    } else {
                        Log::append('Подключение Deluge webUI к Deluge daemon не удалось');
                        return false;
                    }
                }
            }
            return true;
        }
        Log::append('Не удалось подключиться к веб-интерфейсу торрент-клиента.');
        Log::append('Проверьте в настройках правильность введённого логина и пароля для доступа к торрент-клиенту.');
        return false;
    }

    /**
     * выполнение запроса
     *
     * @param $fields
     * @param bool $decode
     * @param array $options
     * @return bool|mixed|string
     */
    private function makeRequest($fields, $decode = true, $options = array())
    {
        $ch = curl_init();
        curl_setopt_array($ch, $options);
        curl_setopt_array($ch, array(
            CURLOPT_URL => sprintf(self::$base, $this->host, $this->port),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_COOKIE => $this->sid,
            CURLOPT_ENCODING => 'gzip',
            CURLOPT_POSTFIELDS => json_encode($fields),
            CURLOPT_HTTPHEADER => array('Content-Type: application/json'),
        ));
        $req = curl_exec($ch);
        if ($req === false) {
            Log::append('CURL ошибка: ' . curl_error($ch));
            return false;
        }
        curl_close($ch);
        return $decode ? json_decode($req, true) : $req;
    }

    public function getTorrents()
    {
        $request = array(
            'method' => 'web.update_ui',
            'params' => array(
                array(
                    'paused',
                    'message',
                    'progress',
                ),
                (object)array(),
            ),
            'id' => 9,
        );
        $data = $this->makeRequest($request);
        if (empty($data['result']['torrents'])) {
            return false;
        }
        foreach ($data['result']['torrents'] as $hash => $torrent) {
            if ($torrent['message'] == 'OK') {
                if ($torrent['progress'] == 100) {
                    $status = $torrent['paused'] ? -1 : 1;
                } else {
                    $status = 0;
                }
                $hash = strtoupper($hash);
                $torrents[$hash] = $status;
            }
        }
        return isset($torrents) ? $torrents : array();
    }

    public function torrentAdd($filename, $save_path = "")
    {
        $local_path = $this->torrentDownload($filename);
        if (empty($local_path)) {
            return false;
        }
        $request = array(
            'method' => 'web.add_torrents',
            'params' => array(
                array(
                    array(
                        'path' => $local_path,
                        'options' => array(
                            'download_location' => $save_path,
                        ),
                    ),
                ),
            ),
            'id' => 1,
        );
        $data = $this->makeRequest($request);
        // return $data['result'] == 1 ? true : false;
    }

    /**
     * загрузить торрент локально
     *
     * @param $filename
     * @return mixed
     */
    private function torrentDownload($filename)
    {
        $request = array(
            'method' => 'web.download_torrent_from_url',
            'params' => array(
                $filename,
            ),
            'id' => 2,
        );
        $data = $this->makeRequest($request);
        return $data['result']; // return localpath
    }

    /**
     * включение плагинов
     *
     * @param string $name
     */
    private function enablePlugin($name = "")
    {
        $request = array(
            'method' => 'core.enable_plugin',
            'params' => array(
                $name,
            ),
            'id' => 3,
        );
        $data = $this->makeRequest($request);
    }

    /**
     * добавить метку
     *
     * @param string $label
     * @return bool
     */
    private function addLabel($label = "")
    {
        // не знаю как по-другому вытащить список уже имеющихся label
        $request = array(
            'method' => 'core.get_filter_tree',
            'params' => array(),
            'id' => 3,
        );
        $filters = $this->makeRequest($request);
        $labels = array_column_common($filters['result']['label'], 0);
        if (in_array($label, $labels)) {
            return false;
        }
        $request = array(
            'method' => 'label.add',
            'params' => array(
                $label,
            ),
            'id' => 3,
        );
        $data = $this->makeRequest($request);
    }

    public function setLabel($hashes, $label = "")
    {
        $label = str_replace(' ', '_', $label);
        if (!preg_match("|^[aA-zZ0-9\-_]+$|", $label)) {
            Log::append('В названии метки присутствуют недопустимые символы.');
            return 'В названии метки присутствуют недопустимые символы.';
        }
        $this->enablePlugin('Label');
        $this->addLabel($label);
        foreach ($hashes as $hash) {
            $request = array(
                'method' => 'label.set_torrent',
                'params' => array(
                    strtolower($hash),
                    $label,
                ),
                'id' => 1,
            );
            $data = $this->makeRequest($request);
        }
    }

    /**
     * запустить все (unused)
     */
    public function startAll()
    {
        $request = array(
            'method' => 'core.resume_all_torrents',
            'params' => array(),
            'id' => 7,
        );
        $data = $this->makeRequest($request);
    }

    public function torrentStart($hashes, $force = false)
    {
        $request = array(
            'method' => 'core.resume_torrent',
            'params' => array(
                array_map('strtolower', $hashes),
            ),
            'id' => 7,
        );
        $data = $this->makeRequest($request);
    }

    public function torrentStop($hashes)
    {
        $request = array(
            'method' => 'core.pause_torrent',
            'params' => array(
                array_map('strtolower', $hashes),
            ),
            'id' => 8,
        );
        $data = $this->makeRequest($request);
    }

    public function torrentRemove($hashes, $delete_local_data = false)
    {
        foreach ($hashes as $hash) {
            $request = array(
                'method' => 'core.remove_torrent',
                'params' => array(
                    strtolower($hash),
                    $delete_local_data,
                ),
                'id' => 6,
            );
            $data = $this->makeRequest($request);
        }
    }

    public function torrentRecheck($hashes)
    {
        $request = array(
            'method' => 'core.force_recheck',
            'params' => array(
                array_map('strtolower', $hashes),
            ),
            'id' => 5,
        );
        $data = $this->makeRequest($request);
    }

}

/**
 * Class qbittorrent qBittorrent 4.1+
 */
class qbittorrent extends torrentClient
{

    protected static $base = "http://%s:%s/%s";

    protected function getSID()
    {
        // Log::append ( 'Попытка подключиться к торрент-клиенту "' . $this->comment . '"...' );
        $ch = curl_init();
        curl_setopt_array($ch, array(
            CURLOPT_URL => sprintf(self::$base, $this->host, $this->port, 'api/v2/auth/login'),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POSTFIELDS => http_build_query(
                array(
                    'username' => "$this->login",
                    'password' => "$this->password",
                )
            ),
            CURLOPT_HEADER => true,
        ));
        $output = curl_exec($ch);
        if ($output === false) {
            Log::append('CURL ошибка: ' . curl_error($ch));
            Log::append('Проверьте в настройках правильность введённого IP-адреса и порта для доступа к торрент-клиенту.');
            return false;
        }
        curl_close($ch);
        preg_match("|Set-Cookie: ([^;]+);|i", $output, $sid);
        if (!empty($sid)) {
            $this->sid = $sid[1];
            return true;
        }
        Log::append('Не удалось подключиться к веб-интерфейсу торрент-клиента.');
        Log::append('Проверьте в настройках правильность введённого логина и пароля для доступа к торрент-клиенту.');
        return false;
    }

    /**
     * выполнение запроса
     * @param        $fields
     * @param string $url
     * @param bool $decode
     * @param array $options
     *
     * @return bool|mixed|string
     */
    private function makeRequest($fields, $url = "", $decode = true, $options = array())
    {
        $ch = curl_init();
        curl_setopt_array($ch, $options);
        curl_setopt_array($ch, array(
            CURLOPT_URL => sprintf(self::$base, $this->host, $this->port, $url),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_COOKIE => $this->sid,
            CURLOPT_POSTFIELDS => $fields,
        ));
        $req = curl_exec($ch);
        if ($req === false) {
            Log::append('CURL ошибка: ' . curl_error($ch));
            return false;
        }
        curl_close($ch);
        return $decode ? json_decode($req, true) : $req;
    }

    public function getTorrents()
    {
        $data = $this->makeRequest('', 'api/v2/torrents/info');
        if (empty($data)) {
            return false;
        }
        foreach ($data as $torrent) {
            if ($torrent['state'] != 'error') {
                if ($torrent['progress'] == 1) {
                    $status = $torrent['state'] == 'pausedUP' ? -1 : 1;
                } else {
                    $status = 0;
                }
                $hash = strtoupper($torrent['hash']);
                $torrents[$hash] = $status;
            }
        }
        return isset($torrents) ? $torrents : array();
    }

    public function torrentAdd($torrent_file_path, $save_path = "")
    {
        if (version_compare(PHP_VERSION, '5.5.0') >= 0) {
            $torrent_data = new CurlFile($torrent_file_path, 'application/x-bittorrent');
        } else {
            $torrent_data = '@' . $torrent_file_path;
        }
        $request = [
            'torrents' => $torrent_data,
            'savepath' => $save_path,
        ];
        $this->makeRequest($request, 'api/v2/torrents/add', false);
    }

    public function setLabel($hashes, $label = "")
    {
        $hashes = array_map(function ($hash) {
            return strtolower($hash);
        }, $hashes);
        $fields = http_build_query(
            array(
                'hashes' => implode('|', $hashes),
                'category' => $label,
            ),
            '',
            '&',
            PHP_QUERY_RFC3986
        );
        $this->makeRequest($fields, 'api/v2/torrents/setCategory', false);
    }

    public function torrentStart($hashes, $force = false)
    {
        $hashes = array_map(function ($hash) {
            return strtolower($hash);
        }, $hashes);
        $this->makeRequest(
            'hashes=' . implode('|', $hashes),
            'api/v2/torrents/resume',
            false
        );
    }

    public function torrentStop($hashes)
    {
        $hashes = array_map(function ($hash) {
            return strtolower($hash);
        }, $hashes);
        $this->makeRequest(
            'hashes=' . implode('|', $hashes),
            'api/v2/torrents/pause',
            false
        );
    }

    public function torrentRemove($hashes, $delete_local_data = false)
    {
        $hashes = array_map(function ($hash) {
            return strtolower($hash);
        }, $hashes);
        $this->makeRequest(
            'hashes=' . implode('|', $hashes) . ($delete_local_data ? '&deleteFiles=true' : ''),
            'api/v2/torrents/delete',
            false
        );
    }

    public function torrentRecheck($hashes)
    {
        $hashes = array_map(function ($hash) {
            return strtolower($hash);
        }, $hashes);
        $this->makeRequest(
            'hashes=' . implode('|', $hashes),
            '/api/v2/torrents/recheck',
            false
        );
    }

}

/**
 * Class ktorrent KTorrent 4.3.1 ~ Linux x64
 */
class ktorrent extends torrentClient
{

    protected static $base = "http://%s:%s/%s";

    protected $challenge;

    public function is_online()
    {
        return $this->getChallenge();
    }

    /**
     * получение challenge
     * @return bool
     */
    private function getChallenge()
    {
        // Log::append ( 'Попытка подключиться к торрент-клиенту "' . $this->comment . '"...' );
        $ch = curl_init();
        curl_setopt_array($ch, array(
            CURLOPT_URL => sprintf(self::$base, $this->host, $this->port, 'login/challenge.xml'),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POSTFIELDS => http_build_query(
                array(
                    'username' => "$this->login",
                    'password' => "$this->password",
                )
            ),
            CURLOPT_HEADER => true,
        ));
        $output = curl_exec($ch);
        if ($output === false) {
            Log::append('CURL ошибка: ' . curl_error($ch));
            Log::append('Проверьте в настройках правильность введённого IP-адреса и порта для доступа к торрент-клиенту.');
            return false;
        }
        curl_close($ch);
        preg_match('|<challenge>(.*)</challenge>|sei', $output, $challenge);
        if (!empty($challenge)) {
            $this->challenge = sha1($challenge[1] . $this->password);
            return $this->getSID();
        }
        Log::append('Не удалось подключиться к веб-интерфейсу торрент-клиента.');
        Log::append('Проверьте в настройках правильность введённого логина и пароля для доступа к торрент-клиенту.');
        return false;
    }

    protected function getSID()
    {
        $ch = curl_init();
        curl_setopt_array($ch, array(
            CURLOPT_URL => sprintf(
                self::$base,
                $this->host, $this->port,
                'login?page=interface.html'
            ),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POSTFIELDS => http_build_query(
                array(
                    'username' => "$this->login",
                    'challenge' => "$this->challenge",
                    'Login' => 'Sign in',
                )
            ),
            CURLOPT_HEADER => true,
        ));
        $output = curl_exec($ch);
        if ($output === false) {
            Log::append('CURL ошибка: ' . curl_error($ch));
            Log::append('Проверьте в настройках правильность введённого IP-адреса и порта для доступа к торрент-клиенту.');
            return false;
        }
        curl_close($ch);
        preg_match("|Set-Cookie: ([^;]+)|i", $output, $sid);
        if (!empty($sid)) {
            $this->sid = $sid[1];
            return true;
        }
        Log::append('Не удалось подключиться к веб-интерфейсу торрент-клиента.');
        Log::append('Проверьте в настройках правильность введённого логина и пароля для доступа к торрент-клиенту.');
        return false;
    }

    /**
     * выполнение запроса
     * @param $url
     * @param bool $decode
     * @param array $options
     * @param bool $xml
     * @return bool|false|mixed|string
     */
    private function makeRequest($url, $decode = true, $options = array(), $xml = false)
    {
        $ch = curl_init();
        curl_setopt_array($ch, $options);
        curl_setopt_array($ch, array(
            CURLOPT_URL => sprintf(self::$base, $this->host, $this->port, $url),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_COOKIE => $this->sid,
        ));
        $req = curl_exec($ch);
        if ($req === false) {
            Log::append('CURL ошибка: ' . curl_error($ch));
            return false;
        }
        curl_close($ch);
        if ($xml) {
            $req = new SimpleXMLElement($req);
            $req = json_encode($req);
        }
        return $decode ? json_decode($req, true) : $req;
    }

    public function getTorrents($full = false)
    {
        $data = $this->makeRequest(
            'data/torrents.xml',
            true,
            array(CURLOPT_POST => false),
            true
        );
        if (empty($data['torrent'])) {
            return false;
        }
        // вывод отличается, если в клиенте только одна раздача
        if ($full) {
            return $data;
        }
        foreach ($data['torrent'] as $torrent) {
            if ($torrent['status'] != 'Ошибка') {
                if ($torrent['percentage'] == 100) {
                    $status = $torrent['status'] == 'Пауза' ? -1 : 1;
                } else {
                    $status = 0;
                }
                $hash = strtoupper($torrent['info_hash']);
                $torrents[$hash] = $status;
            }
        }
        return isset($torrents) ? $torrents : array();
    }

    public function torrentAdd($filename, $save_path = "")
    {
        $data = $this->makeRequest('action?load_torrent=' . $filename, false); // 200 OK
    }

    public function setLabel($hashes, $label = "")
    {
        return 'Торрент-клиент не поддерживает установку меток.';
    }

    /**
     * запустить все (unused)
     */
    public function startAll()
    {
        $json = $this->makeRequest('action?startall=true');
    }

    public function torrentStart($hashes, $force = false)
    {
        $torrents = $this->getTorrents(true);
        if ($torrents === false) {
            return false;
        }
        $hashes_from_client = array_flip(
            array_column_common(
                $torrents['torrent'],
                'info_hash'
            )
        );
        unset($torrents);
        foreach ($hashes as $hash) {
            if (isset($hashes_from_client[strtolower($hash)])) {
                $json = $this->makeRequest('action?start=' . $hashes_from_client[strtolower($hash)]);
            }

        }
    }

    public function torrentStop($hashes)
    {
        $torrents = $this->getTorrents(true);
        if ($torrents === false) {
            return false;
        }
        $hashes_from_client = array_flip(
            array_column_common(
                $torrents['torrent'],
                'info_hash'
            )
        );
        unset($torrents);
        foreach ($hashes as $hash) {
            if (isset($hashes_from_client[strtolower($hash)])) {
                $json = $this->makeRequest('action?stop=' . $hashes_from_client[strtolower($hash)]);
            }

        }
    }

    public function torrentRemove($hashes, $delete_local_data = false)
    {
        $torrents = $this->getTorrents(true);
        if ($torrents === false) {
            return false;
        }
        $hashes_from_client = array_flip(
            array_column_common(
                $torrents['torrent'],
                'info_hash'
            )
        );
        unset($torrents);
        foreach ($hashes as $hash) {
            if (isset($hashes_from_client[strtolower($hash)])) {
                $json = $this->makeRequest('action?remove=' . $hashes_from_client[strtolower($hash)]);
            }

        }
    }

    public function torrentRecheck($hashes)
    {
        return 'Торрент-клиент не поддерживает проверку локальных данных.';
    }

}

/**
 * Class rtorrent rTorrent 0.9.x ~ Linux Added by: advers222@ya.ru
 */
class rtorrent extends torrentClient
{
    // предлагается вводить ссылку полностью в веб интерфейсе
    // потому что при нескольких клиентах вполне может меняться последняя часть (RPC2)
    // http://localhost/RPC2
    protected static $base = "http://%s";

    protected function getSID()
    {
        return $this->makeRequest("get_name") ? true : false;
    }

    /**
     * выполнение запроса
     * @param $cmd
     * @param null $param
     * @return bool|mixed
     */
    public function makeRequest($cmd, $param = null)
    {
        // XML RPC запрос
        $request = xmlrpc_encode_request($cmd, $param);
        $header[] = "Content-type: text/xml";
        $header[] = "Content-length: " . strlen($request);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, sprintf(self::$base, $this->host));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        //curl_setopt($ch, CURLOPT_TIMEOUT, 1);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $header);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $request);

        $data = curl_exec($ch);
        if (curl_errno($ch)) {
            Log::append('CURL ошибка: ' . curl_error($ch));
            return false;
        }
        curl_close($ch);
        // Грязный хак для приведения ответа XML RPC к понятному для PHP
        return xmlrpc_decode(str_replace('i8>', 'i4>', $data));
    }

    public function getTorrents()
    {
        $data = $this->makeRequest(
            "d.multicall",
            array(
                "main",
                "d.get_hash=",
                "d.get_state=",
                "d.get_complete=",
            )
        );
        if (empty($data)) {
            return false;
        }
        // ответ в формате array(HASH, STATE active/stopped, COMPLETED)
        foreach ($data as $torrent) {
            // $status:
            //        0 - Не скачано
            //        1 - Скачано и активно
            //        -1 - Скачано и остановлено
            if ($torrent[2]) {
                $status = $torrent[1] ? 1 : -1;
            } else {
                $status = 0;
            }
            $torrents[$torrent[0]] = $status;
        }
        return isset($torrents) ? $torrents : array();
    }

    public function torrentAdd($torrent_file_path, $save_path = "", $label = "")
    {
        $result = $this->makeRequest("load_start", $torrent_file_path); // === false
    }

    public function setLabel($hashes, $label = "")
    {
        $result_ok = 0;
        $result_fail = 0;
        foreach ($hashes as $hash) {
            $result = $this->makeRequest(
                "d.set_custom1",
                array($hash, $label)
            );
            $result === false ? $result_fail += 1 : $result_ok += 1;
        }
        Log::append('Установлено меток успешно: ' . $result_ok . '. С ошибкой: ' . $result_fail);
    }

    public function torrentStart($hashes, $force = false)
    {
        $result_ok = 0;
        $result_fail = 0;
        foreach ($hashes as $hash) {
            $result = $this->makeRequest("d.start", $hash);
            $result === false ? $result_fail += 1 : $result_ok += 1;
        }
        Log::append('Запущено раздач успешно: ' . $result_ok . '. С ошибкой: ' . $result_fail);
    }

    /**
     * пауза раздач (unused)
     * @param $hashes
     */
    public function torrentPause($hashes)
    {
        $result_ok = 0;
        $result_fail = 0;
        foreach ($hashes as $hash) {
            $result = $this->makeRequest("d.pause", $hash);
            $result === false ? $result_fail += 1 : $result_ok += 1;
        }
        Log::append('Приостановлено раздач успешно: ' . $result_ok . '. С ошибкой: ' . $result_fail);
    }

    public function torrentRecheck($hashes)
    {
        $result_ok = 0;
        $result_fail = 0;
        foreach ($hashes as $hash) {
            $result = $this->makeRequest("d.check_hash", $hash);
            $result === false ? $result_fail += 1 : $result_ok += 1;
        }
        Log::append('Проверка файлов запущена успешно: ' . $result_ok . '. С ошибкой: ' . $result_fail);
    }

    public function torrentStop($hashes)
    {
        $result_ok = 0;
        $result_fail = 0;
        foreach ($hashes as $hash) {
            $result = $this->makeRequest("d.stop", $hash);
            $result === false ? $result_fail += 1 : $result_ok += 1;
        }
        Log::append('Остановлено раздач успешно: ' . $result_ok . '. С ошибкой: ' . $result_fail);
    }

    public function torrentRemove($hashes, $data = false)
    {
        // FIXME: Не знаю стоит ли делать удаление, мне пока не нужно и страшно. Удаленного не вернешь :))
        Log::append("Удаление раздачи заблокировано.");
    }

}
