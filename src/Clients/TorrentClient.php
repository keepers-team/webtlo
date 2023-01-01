<?php

namespace KeepersTeam\Webtlo\Clients;

use CurlHandle;
use Psr\Log\LoggerInterface;

/**
 * Class TorrentClient
 * Базовый класс для всех торрент-клиентов
 */
abstract class TorrentClient
{
    protected static string $base;
    protected string $scheme;
    protected string $host;
    protected int $port;
    protected string $login;
    protected string $password;
    protected string $sid;
    protected CurlHandle $ch;
    protected LoggerInterface $logger;

    /**
     * default constructor
     */
    public function __construct(LoggerInterface $logger, bool $ssl, string $host, int $port, string $login = '', string $password = '')
    {
        $this->scheme = $ssl ? 'https' : 'http';
        $this->host = $host;
        $this->port = $port;
        $this->login = $login;
        $this->password = $password;
        $this->ch = curl_init();
        $this->logger = $logger;
    }

    /**
     * проверка доступен торрент-клиент или нет
     */
    public function isOnline(): bool
    {
        return $this->getSID();
    }

    /**
     * получение идентификатора сессии и запись его в $this->sid
     * @return bool true в случе успеха, false в случае неудачи
     */
    abstract protected function getSID(): bool;

    /**
     * установка пользовательских параметров для cURL
     * в функции makeRequest()
     */
    public function setUserConnectionOptions(array $options): void
    {
        curl_setopt_array($this->ch, $options);
    }

    /**
     * получение сведений о раздачах от торрент-клиента
     * array[torrentHash] => (comment, done, error, name, paused, time_added, total_size, tracker_error)
     */
    abstract public function getAllTorrents(): array|false;

    /**
     * добавить торрент
     * @param string $torrentFilePath полный локальный путь до .torrent файла включая его имя
     * @param string $savePath полный путь до каталога куда сохранять загружаемые данные
     */
    abstract public function addTorrent(string $torrentFilePath, string $savePath = ''): bool;

    /**
     * установка метки у раздач перечисленных в $torrentHashes
     * @param array $torrentHashes хэши раздач
     * @param string $labelName имя метки
     */
    abstract public function setLabel(array $torrentHashes, string $labelName = ''): bool;

    /**
     * запуск раздач перечисленных в $torrentHashes
     * @param array $torrentHashes хэши раздач
     * @param bool $forceStart принудительный запуск
     */
    abstract public function startTorrents(array $torrentHashes, bool $forceStart = false): bool;

    /**
     * остановка раздач перечисленных в $torrentHashes
     * @param array $torrentHashes хэши раздач
     */
    abstract public function stopTorrents(array $torrentHashes): bool;

    /**
     * удаление раздач перечисленных в $torrentHashes
     * @param array $torrentHashes хэши раздач
     * @param bool $deleteFiles удалить раздачу вместе с данными
     */
    abstract public function removeTorrents(array $torrentHashes, bool $deleteFiles = false): bool;

    /**
     * перепроверить локальные данные раздач (unused)
     * @param array $torrentHashes
     */
    abstract public function recheckTorrents(array $torrentHashes): bool;

    /**
     * default destructor
     */
    public function __destruct()
    {
        curl_close($this->ch);
    }
}
