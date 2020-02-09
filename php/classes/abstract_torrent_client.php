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
        $this->host = $host;
        $this->port = $port;
        $this->login = $login;
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
