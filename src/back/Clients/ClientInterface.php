<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\Clients;

/**
 * Набор функциональных возможностей торрент-клиента.
 */
interface ClientInterface
{
    /**
     * Проверка доступности торрент-клиента.
     */
    public function isOnline(): bool;

    /**
     * Получение сведений о раздачах от торрент-клиента
     */
    public function getTorrents(array $filter = []): array;

    /**
     * Добавить раздачу в торрент-клиент из файла.
     */
    public function addTorrent(string $torrentFilePath, string $savePath = '', string $label = ''): bool;

    /**
     * Добавить раздачу в торрент-клиент из данных.
     */
    public function addTorrentContent(string $content, string $savePath = '', string $label = ''): bool;

    /**
     * Присвоить метку раздачам, которые перечислены в $torrentHashes.
     *
     * @param string[] $torrentHashes
     */
    public function setLabel(array $torrentHashes, string $label = ''): bool;

    /**
     * Запуск раздач перечисленных в $torrentHashes.
     *
     * @param string[] $torrentHashes
     */
    public function startTorrents(array $torrentHashes, bool $forceStart = false): bool;

    /**
     * Остановка раздач перечисленных в $torrentHashes.
     *
     * @param string[] $torrentHashes
     */
    public function stopTorrents(array $torrentHashes): bool;

    /**
     * Удаление раздач перечисленных в $torrentHashes
     *
     * @param string[] $torrentHashes
     */
    public function removeTorrents(array $torrentHashes, bool $deleteFiles = false): bool;

    /**
     * Проверить целостность хеша раздач.
     *
     * @param string[] $torrentHashes
     */
    public function recheckTorrents(array $torrentHashes): bool;

    /**
     * Пауза между добавлением раздач в торрент-клиент, микросекунды.
     */
    public function getTorrentAddingSleep(): int;

    /**
     * Может ли торрент-клиент присваивать метки при добавлении раздачи.
     */
    public function isLabelAddingAllowed(): bool;

    /**
     * Установка своего домена трекера.
     */
    public function setDomain(?string $domain): void;
}
