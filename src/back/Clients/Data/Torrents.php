<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\Clients\Data;

use Countable;
use Generator;

/**
 * Набор раздач из одного торрент-клиента.
 */
final class Torrents implements Countable
{
    /**
     * @param array<string, Torrent> $torrents
     */
    public function __construct(public readonly array $torrents = []) {}

    /**
     * @return string[]
     */
    public function getHashes(): array
    {
        return array_map('strval', array_keys($this->torrents));
    }

    public function getTorrent(string $hash): ?Torrent
    {
        return $this->torrents[strtoupper($hash)] ?? null;
    }

    public function getGenerator(): Generator
    {
        foreach ($this->torrents as $hash => $torrent) {
            yield $hash => $torrent;
        }
    }

    // Countable

    public function count(): int
    {
        return count($this->torrents);
    }

    public function empty(): bool
    {
        return [] === $this->torrents;
    }
}
