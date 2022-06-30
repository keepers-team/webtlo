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
    protected $scheme;

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
     * @var CurlHandle
     */
    protected $ch;

    /**
     * default constructor
     * @param bool|int $ssl
     * @param string $host
     * @param string $port
     * @param string $login
     * @param string $password
     */
    public function __construct($ssl, $host, $port, $login = '', $password = '')
    {
        $this->scheme = $ssl ? 'https' : 'http';
        $this->host = $host;
        $this->port = $port;
        $this->login = $login;
        $this->password = $password;
        $this->ch = curl_init();
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
     * установка пользовательских параметров для cURL
     * в функции makeRequest()
     * @param array $options
     */
    public function setUserConnectionOptions($options)
    {
        curl_setopt_array($this->ch, $options);
    }

    /**
     * получение списка раздач от торрент-клиента
     * @return bool|array array[torrentHash] => torrentStatus
     * torrentStatus: 0 (скачивается), 1 (сидируется), -1 (сидируется на паузе), -2 (с ошибкой)
     */
    abstract public function getTorrents();

    /**
     * получение сведений о раздачах от торрент-клиента
     * @return bool|array
     * array[torrentHash] => (comment, done, error, name, paused, time_added, total_size, tracker_error)
     */
    abstract public function getAllTorrents();

    /**
     * добавить торрент
     * @param string $torrentFilePath полный локальный путь до .torrent файла включая его имя
     * @param string $savePath полный путь до каталога куда сохранять загружаемые данные
     * @return bool|mixed
     */
    abstract public function addTorrent($torrentFilePath, $savePath = '');

    /**
     * установка метки у раздач перечисленных в $torrentHashes
     * @param array $torrentHashes хэши раздач
     * @param string $labelName имя метки
     * @return bool|mixed
     */
    abstract public function setLabel($torrentHashes, $labelName = '');

    /**
     * запуск раздач перечисленных в $torrentHashes
     * @param array $torrentHashes хэши раздач
     * @param bool $forceStart принудительный запуск
     * @return bool|mixed
     */
    abstract public function startTorrents($torrentHashes, $forceStart = false);

    /**
     * остановка раздач перечисленных в $torrentHashes
     * @param array $torrentHashes хэши раздач
     * @return bool|mixed
     */
    abstract public function stopTorrents($torrentHashes);

    /**
     * удаление раздач перечисленных в $torrentHashes
     * @param array $torrentHashes хэши раздач
     * @param bool $deleteFiles удалить раздачу вместе с данными
     * @return bool|mixed
     */
    abstract public function removeTorrents($torrentHashes, $deleteFiles = false);

    /**
     * перепроверить локальные данные раздач (unused)
     * @param array $torrentHashes
     * @return bool|mixed
     */
    abstract public function recheckTorrents($torrentHashes);

    /**
     * default destructor
     */
    public function __destruct()
    {
        curl_close($this->ch);
    }
}
