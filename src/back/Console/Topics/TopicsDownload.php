<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\Console\Topics;

use KeepersTeam\Webtlo\Action\TopicDownload;
use KeepersTeam\Webtlo\Console\ConsoleInput;
use KeepersTeam\Webtlo\TopicList\TopicListing;
use Psr\Log\LoggerInterface;

/**
 * Поиск раздач по имени пресета фильтра.
 * Скачивание торрент-файлов по хешам найденных раздач.
 * Запись торрент-файлов в каталог, указанный в настройках.
 */
final class TopicsDownload
{
    use FilterTrait;

    public function __construct(
        private readonly TopicListing    $listing,
        private readonly TopicDownload   $download,
        private readonly LoggerInterface $logger,
    ) {}

    public function run(ConsoleInput $input): void
    {
        $listingId      = $input->integerArgument(name: 'listingId');
        $filterName     = $input->argument(name: 'filterName');
        $replacePasskey = $input->booleanArgument(name: 'replacePasskey');

        $this->logger->info(
            'Начинаем автоматическое скачивание торрент-файлов раздач.',
            ['listingId' => $listingId, 'filterName' => $filterName]
        );

        // Ищем пресет фильтра по имени.
        $filter = $this->getFilterPreset(filterName: $filterName);

        // Ищем хеши раздач с использованием пресета.
        $hashes = $this->getTopicsHashes(listingId: $listingId, filter: $filter);

        $this->logger->info(
            'С помощью фильтра найдено {count} раздач. Начинаем скачивание торрент-файлов...',
            ['count' => count($hashes)]
        );

        $this->download->process(listingId: $listingId, hashes: $hashes, replacePasskey: $replacePasskey);
    }
}
