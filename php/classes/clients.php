<?php

/**
 * Class TorrentClient
 * Базовый класс для всех торрент-клиентов
 */
abstract class TorrentClient
{

    /**
     * @var string
     */
    protected static $base;

    /**
     * @var string
     */
    protected $host;

    /**
     * @var string
     */
    protected $port;

    /**
     * @var string
     */
    protected $login;

    /**
     * @var string
     */
    protected $password;

    /**
     * @var string Session ID, полученный от торрент-клиента
     */
    protected $sid;

    /**
     * default constructor
     * @param string $host
     * @param string $port
     * @param string $login
     * @param string $password
     */
    public function __construct($host, $port, $login = '', $password = '')
    {
        $this->host     = $host;
        $this->port     = $port;
        $this->login    = $login;
        $this->password = $password;
    }

    /**
     * проверка доступен торрент-клиент или нет
     * @return bool
     */
    public function isOnline()
    {
        return $this->getSID();
    }

    /**
     * получение списка загруженных на 100% раздач от торрент-клиента
     * @return array|bool array[hash] => status,
     * false в случае пустого ответа от торрент-клиента
     */
    abstract public function getTorrents();

    /**
     * добавить торрент
     * @param string $torrentFilePath путь до .torrent файла включая имя файла
     * @param string $savePath путь куда сохранять загружаемые данные
     */
    abstract public function addTorrent($torrentFilePath, $savePath = '');

    /**
     * установка метки у раздач перечисленных в $hashes
     * @param array $hashes хэши раздач
     * @param string $label метка
     */
    abstract public function setLabel($hashes, $label = '');

    /**
     * запуск раздач перечисленных в $hashes
     * @param array $hashes хэши раздач
     * @param bool $force
     */
    abstract public function startTorrents($hashes, $force = false);

    /**
     * остановка раздач перечисленных в $hashes
     * @param array $hashes
     */
    abstract public function stopTorrents($hashes);

    /**
     * удаление раздач перечисленных в $hashes
     * @param array $hashes
     * @param bool $deleteLocalData
     */
    abstract public function removeTorrents($hashes, $deleteLocalData = false);

    /**
     * перепроверить локальные данные раздач (unused)
     * @param array $hashes
     */
    abstract public function recheckTorrents($hashes);
}

/**
 * Class Utorrent
 * Supported by uTorrent 1.8.2 and later
 */
class Utorrent extends TorrentClient
{

    protected static $base = 'http://%s:%s/gui/%s';

    protected $token;
    protected $guid;

    /**
     * получение токена
     * @return bool
     */
    protected function getSID()
    {
        $ch = curl_init();
        curl_setopt_array($ch, array(
            CURLOPT_URL => sprintf(self::$base, $this->host, $this->port, 'token.html'),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERPWD => $this->login . ':' . $this->password,
            CURLOPT_HEADER => true,
        ));
        $response = curl_exec($ch);
        if ($response === false) {
            Log::append('CURL ошибка: ' . curl_error($ch));
            Log::append('Проверьте в настройках правильность введённого IP-адреса и порта для доступа к торрент-клиенту.');
            return false;
        }
        $responseInfo = curl_getinfo($ch);
        curl_close($ch);
        $headers = substr($response, 0, $responseInfo['header_size']);
        preg_match('|Set-Cookie: GUID=([^;]+);|i', $headers, $headersMatches);
        if (!empty($headersMatches)) {
            $this->guid = $headersMatches[1];
        }
        preg_match('|<div id=\' token \'.+>(.*)<\/div>|', $response, $responseMatches);
        if (!empty($responseMatches)) {
            $this->token = $responseMatches[1];
            return true;
        }
        Log::append('Не удалось подключиться к веб-интерфейсу торрент-клиента.');
        Log::append('Проверьте в настройках правильность введённого логина и пароля для доступа к торрент-клиенту.');
        return false;
    }

    /**
     * выполнение запроса к торрент-клиенту
     * @param $request
     * @param bool $decode
     * @param array $options
     * @return bool|mixed|string
     */
    private function makeRequest($url, $decode = true, $options = array())
    {
        $url = preg_replace('|^\?|', '?token=' . $this->token . '&', $url);
        $ch = curl_init();
        curl_setopt_array($ch, array(
            CURLOPT_URL => sprintf(self::$base, $this->host, $this->port, $url),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERPWD => $this->login . ':' . $this->password,
            CURLOPT_COOKIE => 'GUID=' . $this->guid,
        ));
        curl_setopt_array($ch, $options);
        $response = curl_exec($ch);
        if ($response === false) {
            Log::append('CURL ошибка: ' . curl_error($ch));
            return false;
        }
        curl_close($ch);
        return $decode ? json_decode($response, true) : $response;
    }

    public function getTorrents()
    {
        $data = $this->makeRequest('?list=1');
        if (empty($data['torrents'])) {
            return false;
        }
        foreach ($data['torrents'] as $torrent) {
            $torrentState = decbin($torrent[1]);
            // 0 - Started, 2 - Paused, 3 - Error, 4 - Checked, 7 - Loaded, 100% Downloads
            if (!$torrentState[3]) {
                if (
                    $torrentState[0]
                    && $torrentState[4]
                    && $torrentState[4] == 1000
                ) {
                    $torrentStatus = !$torrentState[2] && $torrentState[7] ? 1 : -1;
                } else {
                    $torrentStatus = 0;
                }
                $torrents[$torrent[0]] = $torrentStatus;
            }
        }
        return isset($torrents) ? $torrents : array();
    }

    public function addTorrent($filename, $savePath = '')
    {
        $this->setSetting('dir_active_download_flag', true);
        if (!empty($savePath)) {
            $this->setSetting('dir_active_download', urlencode($savePath));
        }
        $this->makeRequest('?action=add-url&s=' . urlencode($filename), false);
    }

    /**
     * изменение свойств торрента
     * @param $hash
     * @param $property
     * @param $value
     */
    public function setProperties($hash, $property, $value)
    {
        $request = preg_replace('|^(.*)$|', 'hash=$0&s=' . $property . '&v=' . urlencode($value), $hash);
        $request = implode('&', $request);
        $this->makeRequest('?action=setprops&' . $request, false);
    }

    /**
     * изменение настроек
     * @param $setting
     * @param $value
     */
    public function setSetting($setting, $value)
    {
        $this->makeRequest('?action=setsetting&s=' . $setting . '&v=' . $value, false);
    }

    /**
     * "склеивание" параметров в строку
     * @param $glue
     * @param $params
     * @return string
     */
    private function implodeParams($glue, $params)
    {
        $params = is_array($params) ? $params : array($params);
        return $glue . implode($glue, $params);
    }

    public function setLabel($hash, $label = '')
    {
        $this->setProperties($hash, 'label', $label);
    }

    public function startTorrents($hashes, $force = false)
    {
        $action = $force ? 'forcestart' : 'start';
        $this->makeRequest('?action=' . $action . $this->implodeParams('&hash=', $hashes), false);
    }

    /**
     * пауза раздач (unused)
     * @param $hashes
     */
    public function pauseTorrents($hashes)
    {
        $this->makeRequest('?action=pause' . $this->implodeParams('&hash=', $hashes), false);
    }

    public function recheckTorrents($hashes)
    {
        $this->makeRequest('?action=recheck' . $this->implodeParams('&hash=', $hashes), false);
    }

    public function stopTorrents($hashes)
    {
        $this->makeRequest('?action=stop' . $this->implodeParams('&hash=', $hashes), false);
    }

    public function removeTorrents($hashes, $deleteLocalData = false)
    {
        $action = $deleteLocalData ? 'removedata' : 'remove';
        $this->makeRequest('?action=' . $action . $this->implodeParams('&hash=', $hashes), false);
    }
}

/**
 * Class Transmission
 * Supported by Transmission 2.80 and later
 */
class Transmission extends TorrentClient
{

    protected static $base = 'http://%s:%s/transmission/rpc';

    /**
     * получение идентификатора сессии и запись его в $this->sid
     * @return bool true в случе успеха, false в случае неудачи
     */
    protected function getSID()
    {
        $ch = curl_init();
        curl_setopt_array($ch, array(
            CURLOPT_URL => sprintf(self::$base, $this->host, $this->port),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERPWD => $this->login . ':' . $this->password,
            CURLOPT_HEADER => true,
        ));
        $response = curl_exec($ch);
        if ($response === false) {
            Log::append('CURL ошибка: ' . curl_error($ch));
            Log::append('Проверьте в настройках правильность введённого IP-адреса и порта для доступа к торрент-клиенту.');
            return false;
        }
        curl_close($ch);
        preg_match('|.*\r\n(X-Transmission-Session-Id: .*?)(\r\n.*)|', $response, $matches);
        if (!empty($matches)) {
            $this->sid = $matches[1];
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
        curl_setopt_array($ch, array(
            CURLOPT_URL => sprintf(self::$base, $this->host, $this->port),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERPWD => $this->login . ':' . $this->password,
            CURLOPT_HTTPHEADER => array($this->sid),
            CURLOPT_POSTFIELDS => json_encode($fields),
        ));
        curl_setopt_array($ch, $options);
        $i = 1; // номер попытки
        $n = 3; // количество попыток
        while (true) {
            $response = curl_exec($ch);
            if ($response === false) {
                Log::append('CURL ошибка: ' . curl_error($ch));
                curl_close($ch);
                return false;
            }
            $response = json_decode($response, true);
            if ($response['result'] != 'success') {
                if (empty($response['result']) && $i <= $n) {
                    Log::append('Повторная попытка ' . $i . '/' . $n . ' выполнить запрос.');
                    sleep(10);
                    $i++;
                    continue;
                }
                $error = empty($response['result']) ? 'Неизвестная ошибка' : $response['result'];
                Log::append('Error: ' . $error);
                curl_close($ch);
                return false;
            }
            curl_close($ch);
            return $response;
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

    public function addTorrent($torrentFilePath, $savePath = '')
    {
        $request = array(
            'method' => 'torrent-add',
            'arguments' => array(
                'metainfo' => base64_encode(file_get_contents($torrentFilePath)),
                'paused' => false,
            ),
        );
        if (!empty($savePath)) {
            $request['arguments']['download-dir'] = $savePath;
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
            Log::append('Warning: Эта раздача уже раздаётся в торрент-клиенте (' . $hash . ').');
        }
        // return $success;
    }

    public function setLabel($hashes, $label = '')
    {
        return 'Торрент-клиент не поддерживает установку меток.';
    }

    public function startTorrents($hashes, $force = false)
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

    public function stopTorrents($hashes)
    {
        $request = array(
            'method' => 'torrent-stop',
            'arguments' => array(
                'ids' => $hashes,
            ),
        );
        $data = $this->makeRequest($request);
    }

    public function recheckTorrents($hashes)
    {
        $request = array(
            'method' => 'torrent-verify',
            'arguments' => array(
                'ids' => $hashes,
            ),
        );
        $data = $this->makeRequest($request);
    }

    public function removeTorrents($hashes, $deleteLocalData = false)
    {
        $request = array(
            'method' => 'torrent-remove',
            'arguments' => array(
                'ids' => $hashes,
                'delete-local-data' => $deleteLocalData,
            ),
        );
        $data = $this->makeRequest($request);
    }
}

/**
 * Class Vuze
 * Supported by Vuze 5.7.0.0/4 az3 [ plugin Web Remote 0.5.11 ] and later
 */
class_alias('Transmission', 'Vuze');

/**
 * Class Deluge
 * Supported by Deluge 1.3.6 [ plugins WebUi 0.1 and Label 0.2 ] and later
 */
class Deluge extends TorrentClient
{

    protected static $base = 'http://%s:%s/json';

    /**
     * получение идентификатора сессии и запись его в $this->sid
     * @return bool true в случе успеха, false в случае неудачи
     */
    protected function getSID()
    {
        $ch = curl_init();
        $fields = array(
            'method' => 'auth.login',
            'params' => array($this->password),
            'id' => 2
        );
        curl_setopt_array($ch, array(
            CURLOPT_URL => sprintf(self::$base, $this->host, $this->port),
            CURLOPT_POSTFIELDS => json_encode($fields),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => 'gzip',
            CURLOPT_HEADER => true,
            CURLOPT_HTTPHEADER => array('Content-Type: application/json'),
        ));
        $response = curl_exec($ch);
        if ($response === false) {
            Log::append('CURL ошибка: ' . curl_error($ch));
            Log::append('Проверьте в настройках правильность введённого IP-адреса и порта для доступа к торрент-клиенту.');
            return false;
        }
        curl_close($ch);
        preg_match('|Set-Cookie: ([^;]+);|i', $response, $matches);
        if (!empty($matches)) {
            $this->sid = $matches[1];
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
        curl_setopt_array($ch, array(
            CURLOPT_URL => sprintf(self::$base, $this->host, $this->port),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_COOKIE => $this->sid,
            CURLOPT_ENCODING => 'gzip',
            CURLOPT_POSTFIELDS => json_encode($fields),
            CURLOPT_HTTPHEADER => array('Content-Type: application/json'),
        ));
        curl_setopt_array($ch, $options);
        $response = curl_exec($ch);
        if ($response === false) {
            Log::append('CURL ошибка: ' . curl_error($ch));
            return false;
        }
        curl_close($ch);
        return $decode ? json_decode($response, true) : $response;
    }

    public function getTorrents()
    {
        $fields = array(
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
        $data = $this->makeRequest($fields);
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

    public function addTorrent($filename, $savePath = '')
    {
        $localPath = $this->downloadTorrent($filename);
        if (empty($localPath)) {
            return false;
        }
        $fields = array(
            'method' => 'web.add_torrents',
            'params' => array(
                array(
                    array(
                        'path' => $localPath,
                        'options' => array('download_location' => $savePath),
                    ),
                ),
            ),
            'id' => 1,
        );
        $data = $this->makeRequest($fields);
        // return $data['result'] == 1 ? true : false;
    }

    /**
     * загрузить торрент локально
     *
     * @param $filename
     * @return mixed
     */
    public function downloadTorrent($filename)
    {
        $fields = array(
            'method' => 'web.download_torrent_from_url',
            'params' => array(
                $filename,
            ),
            'id' => 2,
        );
        $data = $this->makeRequest($fields);
        return $data['result']; // return localpath
    }

    /**
     * включение плагинов
     *
     * @param string $name
     */
    public function enablePlugin($pluginName)
    {
        $fields = array(
            'method' => 'core.enable_plugin',
            'params' => array(
                $pluginName,
            ),
            'id' => 3,
        );
        $data = $this->makeRequest($fields);
    }

    /**
     * добавить метку
     *
     * @param string $label
     * @return bool
     */
    public function addLabel($label)
    {
        // не знаю как по-другому вытащить список уже имеющихся label
        $fields = array(
            'method' => 'core.get_filter_tree',
            'params' => array(),
            'id' => 3,
        );
        $filters = $this->makeRequest($fields);
        $labels = array_column_common($filters['result']['label'], 0);
        if (in_array($label, $labels)) {
            return false;
        }
        $fields = array(
            'method' => 'label.add',
            'params' => array(
                $label,
            ),
            'id' => 3,
        );
        $data = $this->makeRequest($fields);
    }

    public function setLabel($hashes, $label = '')
    {
        $label = str_replace(' ', '_', $label);
        if (!preg_match('|^[aA-zZ0-9\-_]+$|', $label)) {
            Log::append('В названии метки присутствуют недопустимые символы.');
            return 'В названии метки присутствуют недопустимые символы.';
        }
        $this->enablePlugin('Label');
        $this->addLabel($label);
        foreach ($hashes as $hash) {
            $fields = array(
                'method' => 'label.set_torrent',
                'params' => array(
                    strtolower($hash),
                    $label,
                ),
                'id' => 1,
            );
            $data = $this->makeRequest($fields);
        }
    }

    /**
     * запустить все (unused)
     */
    public function startAllTorrents()
    {
        $fields = array(
            'method' => 'core.resume_all_torrents',
            'params' => array(),
            'id' => 7,
        );
        $data = $this->makeRequest($fields);
    }

    public function startTorrents($hashes, $force = false)
    {
        $fields = array(
            'method' => 'core.resume_torrent',
            'params' => array(
                array_map('strtolower', $hashes),
            ),
            'id' => 7,
        );
        $data = $this->makeRequest($fields);
    }

    public function stopTorrents($hashes)
    {
        $fields = array(
            'method' => 'core.pause_torrent',
            'params' => array(
                array_map('strtolower', $hashes),
            ),
            'id' => 8,
        );
        $data = $this->makeRequest($fields);
    }

    public function removeTorrents($hashes, $deleteLocalData = false)
    {
        foreach ($hashes as $hash) {
            $fields = array(
                'method' => 'core.remove_torrent',
                'params' => array(
                    strtolower($hash),
                    $deleteLocalData,
                ),
                'id' => 6,
            );
            $data = $this->makeRequest($fields);
        }
    }

    public function recheckTorrents($hashes)
    {
        $fields = array(
            'method' => 'core.force_recheck',
            'params' => array(
                array_map('strtolower', $hashes),
            ),
            'id' => 5,
        );
        $data = $this->makeRequest($fields);
    }
}

/**
 * Class Qbittorrent
 * Supported by qBittorrent 4.1 and later
 */
class Qbittorrent extends TorrentClient
{

    protected static $base = 'http://%s:%s/%s';

    /**
     * получение идентификатора сессии и запись его в $this->sid
     * @return bool true в случе успеха, false в случае неудачи
     */
    protected function getSID()
    {
        $ch = curl_init();
        curl_setopt_array($ch, array(
            CURLOPT_URL => sprintf(self::$base, $this->host, $this->port, 'api/v2/auth/login'),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POSTFIELDS => http_build_query(
                array(
                    'username' => $this->login,
                    'password' => $this->password,
                )
            ),
            CURLOPT_HEADER => true,
        ));
        $response = curl_exec($ch);
        if ($response === false) {
            Log::append('CURL ошибка: ' . curl_error($ch));
            Log::append('Проверьте в настройках правильность введённого IP-адреса и порта для доступа к торрент-клиенту.');
            return false;
        }
        curl_close($ch);
        preg_match('|Set-Cookie: ([^;]+);|i', $response, $matches);
        if (!empty($matches)) {
            $this->sid = $matches[1];
            return true;
        }
        Log::append('Не удалось подключиться к веб-интерфейсу торрент-клиента.');
        Log::append('Проверьте в настройках правильность введённого логина и пароля для доступа к торрент-клиенту.');
        return false;
    }

    /**
     * выполнение запроса
     * @param $fields
     * @param string $url
     * @param bool $decode
     * @param array $options
     *
     * @return bool|mixed|string
     */
    private function makeRequest($url, $fields = '', $decode = true, $options = array())
    {
        $ch = curl_init();
        curl_setopt_array($ch, array(
            CURLOPT_URL => sprintf(self::$base, $this->host, $this->port, $url),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_COOKIE => $this->sid,
            CURLOPT_POSTFIELDS => $fields,
        ));
        curl_setopt_array($ch, $options);
        $response = curl_exec($ch);
        if ($response === false) {
            Log::append('CURL ошибка: ' . curl_error($ch));
            return false;
        }
        curl_close($ch);
        return $decode ? json_decode($response, true) : $response;
    }

    public function getTorrents()
    {
        $data = $this->makeRequest('api/v2/torrents/info');
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

    public function addTorrent($torrentFilePath, $savePath = '')
    {
        if (version_compare(PHP_VERSION, '5.5.0') >= 0) {
            $torrentData = new CurlFile($torrentFilePath, 'application/x-bittorrent');
        } else {
            $torrentData = '@' . $torrentFilePath;
        }
        $fields = array(
            'torrents' => $torrentData,
            'savepath' => $savePath,
        );
        $this->makeRequest('api/v2/torrents/add', $fields, false);
    }

    public function setLabel($hashes, $label = '')
    {
        $hashes = array_map(function ($hash) {
            return strtolower($hash);
        }, $hashes);
        $fields = http_build_query(
            array('hashes' => implode('|', $hashes), 'category' => $label),
            '',
            '&',
            PHP_QUERY_RFC3986
        );
        $this->makeRequest('api/v2/torrents/setCategory', $fields, false);
    }

    public function startTorrents($hashes, $force = false)
    {
        $hashes = array_map(function ($hash) {
            return strtolower($hash);
        }, $hashes);
        $fields = 'hashes=' . implode('|', $hashes);
        $this->makeRequest('api/v2/torrents/resume', $fields, false);
    }

    public function stopTorrents($hashes)
    {
        $hashes = array_map(function ($hash) {
            return strtolower($hash);
        }, $hashes);
        $fields = 'hashes=' . implode('|', $hashes);
        $this->makeRequest('api/v2/torrents/pause', $fields, false);
    }

    public function removeTorrents($hashes, $deleteLocalData = false)
    {
        $hashes = array_map(function ($hash) {
            return strtolower($hash);
        }, $hashes);
        $action = $deleteLocalData ? '&deleteFiles=true' : '';
        $fields = 'hashes=' . implode('|', $hashes) . $action;
        $this->makeRequest('api/v2/torrents/delete', $fields, false);
    }

    public function recheckTorrents($hashes)
    {
        $hashes = array_map(function ($hash) {
            return strtolower($hash);
        }, $hashes);
        $fields = 'hashes=' . implode('|', $hashes);
        $this->makeRequest('/api/v2/torrents/recheck', $fields, false);
    }
}

/**
 * Class Ktorrent
 * Supported by KTorrent 4.3.1 and later
 */
class Ktorrent extends TorrentClient
{

    protected static $base = 'http://%s:%s/%s';

    protected $challenge;

    public function isOnline()
    {
        return $this->getChallenge();
    }

    /**
     * получение challenge
     * @return bool
     */
    protected function getChallenge()
    {
        $ch = curl_init();
        curl_setopt_array($ch, array(
            CURLOPT_URL => sprintf(self::$base, $this->host, $this->port, 'login/challenge.xml'),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POSTFIELDS => http_build_query(
                array(
                    'username' => $this->login,
                    'password' => $this->password,
                )
            ),
            CURLOPT_HEADER => true,
        ));
        $response = curl_exec($ch);
        if ($response === false) {
            Log::append('CURL ошибка: ' . curl_error($ch));
            Log::append('Проверьте в настройках правильность введённого IP-адреса и порта для доступа к торрент-клиенту.');
            return false;
        }
        curl_close($ch);
        preg_match('|<challenge>(.*)</challenge>|sei', $response, $matches);
        if (!empty($matches)) {
            $this->challenge = sha1($matches[1] . $this->password);
            return $this->getSID();
        }
        Log::append('Не удалось подключиться к веб-интерфейсу торрент-клиента.');
        Log::append('Проверьте в настройках правильность введённого логина и пароля для доступа к торрент-клиенту.');
        return false;
    }

    /**
     * получение идентификатора сессии и запись его в $this->sid
     * @return bool true в случе успеха, false в случае неудачи
     */
    protected function getSID()
    {
        $ch = curl_init();
        curl_setopt_array($ch, array(
            CURLOPT_URL => sprintf(
                self::$base,
                $this->host,
                $this->port,
                'login?page=interface.html'
            ),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POSTFIELDS => http_build_query(
                array(
                    'username' => $this->login,
                    'challenge' => $this->challenge,
                    'Login' => 'Sign in',
                )
            ),
            CURLOPT_HEADER => true,
        ));
        $response = curl_exec($ch);
        if ($response === false) {
            Log::append('CURL ошибка: ' . curl_error($ch));
            Log::append('Проверьте в настройках правильность введённого IP-адреса и порта для доступа к торрент-клиенту.');
            return false;
        }
        curl_close($ch);
        preg_match('|Set-Cookie: ([^;]+)|i', $response, $matches);
        if (!empty($matches)) {
            $this->sid = $matches[1];
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
        curl_setopt_array($ch, array(
            CURLOPT_URL => sprintf(self::$base, $this->host, $this->port, $url),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_COOKIE => $this->sid,
        ));
        curl_setopt_array($ch, $options);
        $response = curl_exec($ch);
        if ($response === false) {
            Log::append('CURL ошибка: ' . curl_error($ch));
            return false;
        }
        curl_close($ch);
        if ($xml) {
            $response = new SimpleXMLElement($response);
            $response = json_encode($response);
        }
        return $decode ? json_decode($response, true) : $response;
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

    public function addTorrent($filename, $savePath = '')
    {
        $this->makeRequest('action?load_torrent=' . $filename, false); // 200 OK
    }

    public function setLabel($hashes, $label = '')
    {
        return 'Торрент-клиент не поддерживает установку меток.';
    }

    /**
     * запустить все (unused)
     */
    public function startAllTorrents()
    {
        $this->makeRequest('action?startall=true');
    }

    public function startTorrents($hashes, $force = false)
    {
        $torrents = $this->getTorrents(true);
        if ($torrents === false) {
            return false;
        }
        $hashesFromClient = array_flip(
            array_column_common($torrents['torrent'], 'info_hash')
        );
        unset($torrents);
        foreach ($hashes as $hash) {
            if (isset($hashesFromClient[strtolower($hash)])) {
                $this->makeRequest('action?start=' . $hashesFromClient[strtolower($hash)]);
            }
        }
    }

    public function stopTorrents($hashes)
    {
        $torrents = $this->getTorrents(true);
        if ($torrents === false) {
            return false;
        }
        $hashesFromClient = array_flip(
            array_column_common($torrents['torrent'], 'info_hash')
        );
        unset($torrents);
        foreach ($hashes as $hash) {
            if (isset($hashesFromClient[strtolower($hash)])) {
                $this->makeRequest('action?stop=' . $hashesFromClient[strtolower($hash)]);
            }
        }
    }

    public function removeTorrents($hashes, $deleteLocalData = false)
    {
        $torrents = $this->getTorrents(true);
        if ($torrents === false) {
            return false;
        }
        $hashesFromClient = array_flip(
            array_column_common($torrents['torrent'], 'info_hash')
        );
        unset($torrents);
        foreach ($hashes as $hash) {
            if (isset($hashesFromClient[strtolower($hash)])) {
                $this->makeRequest('action?remove=' . $hashesFromClient[strtolower($hash)]);
            }
        }
    }

    public function recheckTorrents($hashes)
    {
        return 'Торрент-клиент не поддерживает проверку локальных данных.';
    }
}

/**
 * Class Rtorrent
 * Supported by rTorrent 0.9.x and later
 * Added by: advers222@ya.ru
 */
class Rtorrent extends TorrentClient
{
    // предлагается вводить ссылку полностью в веб интерфейсе
    // потому что при нескольких клиентах вполне может меняться последняя часть (RPC2)
    // http://localhost/RPC2
    protected static $base = 'http://%s';

    /**
     * получение идентификатора сессии и запись его в $this->sid
     * @return bool true в случе успеха, false в случае неудачи
     */
    protected function getSID()
    {
        return $this->makeRequest('get_name') ? true : false;
    }

    /**
     * выполнение запроса
     * @param $cmd
     * @param null $param
     * @return bool|mixed
     */
    public function makeRequest($command, $params = null)
    {
        // XML RPC запрос
        $request = xmlrpc_encode_request($command, $params);
        $header = array(
            'Content-type: text/xml',
            'Content-length: ' . strlen($request)
        );
        $ch = curl_init();
        curl_setopt_array($ch, array(
            CURLOPT_URL => sprintf(self::$base, $this->host),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $header,
            CURLOPT_POSTFIELDS => $request,
        ));
        $response = curl_exec($ch);
        if (curl_errno($ch)) {
            Log::append('CURL ошибка: ' . curl_error($ch));
            return false;
        }
        curl_close($ch);
        // Грязный хак для приведения ответа XML RPC к понятному для PHP
        return xmlrpc_decode(str_replace('i8>', 'i4>', $response));
    }

    public function getTorrents()
    {
        $data = $this->makeRequest(
            'd.multicall',
            array(
                'main',
                'd.get_hash=',
                'd.get_state=',
                'd.get_complete=',
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

    public function addTorrent($torrentFilePath, $savePath = '', $label = '')
    {
        $result = $this->makeRequest('load_start', $torrentFilePath); // === false
    }

    public function setLabel($hashes, $label = '')
    {
        foreach ($hashes as $hash) {
            $result = $this->makeRequest('d.set_custom1', array($hash, $label)); // === false
        }
    }

    public function startTorrents($hashes, $force = false)
    {
        foreach ($hashes as $hash) {
            $result = $this->makeRequest('d.start', $hash); // === false
        }
    }

    /**
     * пауза раздач (unused)
     * @param $hashes
     */
    public function pauseTorrents($hashes)
    {
        foreach ($hashes as $hash) {
            $result = $this->makeRequest('d.pause', $hash); // === false
        }
    }

    public function recheckTorrents($hashes)
    {
        foreach ($hashes as $hash) {
            $result = $this->makeRequest('d.check_hash', $hash); // === false
        }
    }

    public function stopTorrents($hashes)
    {
        foreach ($hashes as $hash) {
            $result = $this->makeRequest('d.stop', $hash); // === false
        }
    }

    public function removeTorrents($hashes, $data = false)
    {
        Log::append('Удаление раздачи не реализовано.');
    }
}
