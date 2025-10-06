<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\Config;

/**
 * Параметры загрузки торрент-файлов.
 */
final class TorrentDownload
{
    /**
     * @param string $folder         путь хранения торрент-файлов
     * @param bool   $subFolder      создавать подкаталог
     * @param bool   $addRetracker   добавлять retracker.local
     * @param string $folderReplace  путь хранения торрент-файлов с заменой ключа
     * @param string $replacePassKey ключ для замены
     * @param bool   $forRegularUser скачивать для не-хранителя
     */
    public function __construct(
        public readonly string $folder,
        public readonly bool   $subFolder,
        public readonly bool   $addRetracker,
        public readonly string $folderReplace,
        public readonly string $replacePassKey,
        public readonly bool   $forRegularUser,
    ) {}
}
