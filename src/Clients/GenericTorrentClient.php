<?php

namespace KeepersTeam\Webtlo\Clients;

use CurlHandle;
use KeepersTeam\Webtlo\Config\Timeout;
use KeepersTeam\Webtlo\Config\V0\TorrentClientConfig;
use Psr\Log\LoggerInterface;

/**
 * Class TorrentClient
 * Базовый класс для всех торрент-клиентов
 */
abstract class GenericTorrentClient
{
    protected static string $base;
    protected readonly string $scheme;
    protected readonly string $host;
    protected readonly int $port;
    protected readonly string $login;
    protected readonly string $password;
    protected string $sid;
    protected CurlHandle $ch;
    protected readonly Timeout $timeout;

    /**
     * default constructor
     */
    public function __construct(protected readonly LoggerInterface $logger, TorrentClientConfig $config)
    {
        $this->scheme = $config->secure ? 'https' : 'http';
        $this->host = $config->host;
        $this->port = $config->port;
        if (null !== $config->credentials) {
            $login = $config->credentials->username;
            $password = $config->credentials->password;
        } else {
            $login = '';
            $password = '';
        }
        $this->login = $login;
        $this->password = $password;
        $this->timeout = $config->timeout;
        $this->ch = curl_init();
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
