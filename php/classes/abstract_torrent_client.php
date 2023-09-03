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
     * Домен, по которому определять ид раздачи.
     */
    protected string $defaultDomain = 'rutracker';

    /**
     * Домен, по которому определять ид раздачи.
     */
    protected ?string $customDomain = null;

    /**
     * @var string Session ID, полученный от торрент-клиента
     */
    protected $sid;

    /**
     * @var CurlHandle
     */
    protected $ch;

    /** Пауза при добавлении раздач в клиент, мс. */
    protected int $torrentAddingSleep = 500000;

    /** Позволяет ли клиент присваивать раздаче категорию при добавлении. */
    protected bool $categoryAddingAllowed = false;

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

    /** Установка кастомного докена трекера. */
    public function setDomain(?string $domain): void
    {
        $this->customDomain = $domain;
    }

    /** Значение паузы при добавлении раздач, мс */
    public function getTorrentAddingSleep(): int
    {
        return $this->torrentAddingSleep;
    }

    public function isCategoryAddingAllowed(): bool
    {
        return $this->categoryAddingAllowed;
    }

    /** Получить ид раздачи из комментария. */
    public function getTorrentTopicId(string $comment): ?int
    {
        if (empty($comment)) {
            return null;
        }

        // если комментарий содержит подходящий домен
        $isCustom = null !== $this->customDomain && str_contains($comment, $this->customDomain);
        if ($isCustom || str_contains($comment, $this->defaultDomain)) {
            $topicID = preg_replace('/.*?([0-9]*)$/', '$1', $comment);
            $topicID = (int)$topicID;
        }

        return $topicID ?? null;
    }

    /**
     * получение сведений о раздачах от торрент-клиента
     * @return bool|array
     * array[torrentHash] => (comment, done, error, name, paused, time_added, total_size, tracker_error)
     */
    abstract public function getAllTorrents(array $filter = []);

    /**
     * добавить торрент
     * @param string $torrentFilePath полный локальный путь до .torrent файла включая его имя
     * @param string $savePath полный путь до каталога куда сохранять загружаемые данные
     * @return bool|mixed
     */
    abstract public function addTorrent(string $torrentFilePath, string $savePath = '', string $label = '');

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
