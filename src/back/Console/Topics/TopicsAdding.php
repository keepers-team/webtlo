<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\Console\Topics;

use KeepersTeam\Webtlo\Action\ClientAddTopics;
use KeepersTeam\Webtlo\Console\ConsoleInput;
use KeepersTeam\Webtlo\TopicList\TopicListing;
use Psr\Log\LoggerInterface;

/**
 * Поиск раздач по имени пресета фильтра.
 * Скачивание торрент-файлов по хешам найденных раздач.
 * Добавление торрент-файлов в торрент-клиенты.
 */
final class TopicsAdding
{
    use FilterTrait;

    public function __construct(
        private readonly TopicListing    $listing,
        private readonly ClientAddTopics $addClient,
        private readonly LoggerInterface $logger,
    ) {}

    public function run(ConsoleInput $input): void
    {
        $listingId  = $input->integerArgument(name: 'listingId');
        $filterName = $input->argument(name: 'filterName');

        $this->logger->info(
            'Начинаем автоматическое добавление раздач в торрент-клиенты.',
            ['listingId' => $listingId, 'filterName' => $filterName]
        );

        // Ищем пресет фильтра по имени.
        $filter = $this->getFilterPreset(filterName: $filterName);

        // Ищем хеши раздач с использованием пресета.
        $hashes = $this->getTopicsHashes(listingId: $listingId, filter: $filter);

        $this->logger->info(
            'С помощью фильтра найдено {count} раздач. Начинаем добавление в торрент-клиенты...',
            ['count' => count($hashes)]
        );

        $this->addClient->process(hashes: $hashes);
    }
}
