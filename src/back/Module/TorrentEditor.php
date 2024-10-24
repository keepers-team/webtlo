<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\Module;

use Arokettu\Torrent\TorrentFile;
use Psr\Http\Message\StreamInterface;
use Psr\Log\LoggerInterface;

/**
 * Class TorrentEditor.
 *
 * This class provides methods to load, edit, and retrieve torrent files and their trackers.
 */
final class TorrentEditor
{
    /**
     * @param LoggerInterface $logger  Logger instance
     * @param TorrentFile     $torrent Torrent file instance
     */
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly TorrentFile     $torrent,
    ) {}

    /**
     * Load a torrent file from a file path.
     *
     * @param LoggerInterface $logger   Logger instance
     * @param string          $filePath Path to the torrent file
     */
    public static function loadFromFile(LoggerInterface $logger, string $filePath): self
    {
        $torrent = TorrentFile::load($filePath);
        $logger->debug('Load file [{name}]', [
            'name' => $torrent->getName(),
            'file' => $torrent->getFileName(),
        ]);

        return new self($logger, $torrent);
    }

    /**
     * Load a torrent file from a stream.
     *
     * @param LoggerInterface $logger Logger instance
     * @param StreamInterface $stream Stream interface instance
     */
    public static function loadFromStream(LoggerInterface $logger, StreamInterface $stream): self
    {
        $torrent = TorrentFile::loadFromString($stream->getContents());
        $logger->debug('Load file [{name}]', [
            'name' => $torrent->getName(),
            'file' => $torrent->getFileName(),
        ]);

        return new self($logger, $torrent);
    }

    /**
     * Get the list of trackers from the torrent file.
     *
     * @return string[] Returns an array of tracker URLs
     */
    public function getTrackers(): array
    {
        $torrent = $this->torrent;

        $list = $torrent->getAnnounceList();

        $trackers = [];
        if (!$list->empty()) {
            $trackers = array_merge(...$list->toArray());
        } else {
            if ($announce = $torrent->getAnnounce()) {
                $trackers[] = $announce;
            }
        }

        return $trackers;
    }

    /**
     * Set the list of trackers for the torrent file.
     *
     * @param string[] $trackers Array of tracker URLs
     */
    public function setTrackers(array $trackers): void
    {
        $torrent = $this->torrent;

        if (count($trackers) >= 1) {
            $torrent->setAnnounceList(null);
            $torrent->setAnnounce($trackers[0]);
        }

        if (count($trackers) > 1) {
            $torrent->setAnnounceList($trackers);
        }

        $this->logger->debug('Replace announceList [{name}]', [
            'name' => $this->torrent->getName(),
            'file' => $trackers,
        ]);
    }

    /**
     * Get the torrent file instance.
     *
     * @return TorrentFile Returns the torrent file instance
     */
    public function getTorrent(): TorrentFile
    {
        return $this->torrent;
    }

    /**
     * Replace the trackers in the torrent file with a user passkey and optional tracker modifications.
     *
     * @param string $passkey     User passkey for the tracker
     * @param bool   $regularUser If true, modifies the tracker URL for regular users
     */
    public function replaceTrackers(string $passkey, bool $regularUser = false): void
    {
        $trackers = $this->getTrackers();

        foreach ($trackers as &$tracker) {
            // Если задан пустой ключ, то записываем 'ann?magnet'.
            if (empty($passkey)) {
                $tracker = (string) preg_replace('/(?<=ann\?).+$/', 'magnet', $tracker);
            } else {
                $tracker = (string) preg_replace('/(?<==)\w+$/', $passkey, $tracker);
            }

            // Для обычных пользователей заменяем адрес трекера и тип ключа.
            if ($regularUser) {
                $tracker = (string) preg_replace(['/(?<=\.)([-\w]+\.\w+)/', '/\w+(?==)/'], ['t-ru.org', 'pk'], $tracker);
            }

            unset($tracker);
        }

        $this->setTrackers($trackers);
    }
}
