<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\Clients;

/** Получение каталогов раздач по хешам пакетными запросами. */
interface SavePathLookupInterface
{
    /**
     * @param string[] $hashes
     *
     * @return array<string, string> каталог по хешу раздачи в верхнем регистре
     */
    public function getTorrentSavePaths(array $hashes): array;
}
