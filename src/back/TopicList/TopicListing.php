<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\TopicList;

use KeepersTeam\Webtlo\TopicList\Rule\Factory;

final class TopicListing
{
    public function __construct(
        private readonly Factory $factory,
    ) {}

    /**
     * @param array<string, mixed> $filter
     *
     * @throws ValidationException
     */
    public function findTopics(int $listingId, array $filter): Topics
    {
        // Кодировка для regexp.
        mb_regex_encoding('UTF-8');

        // Проверяем наличие сортировки.
        $sorting = Validate::sortFilter(filter: $filter);

        // Получаем нужные правила поиска раздач.
        $ruleSet = $this->factory->getRule(listingId: $listingId);

        // Ищем и форматируем раздачи.
        return $ruleSet->getTopics(filter: $filter, sort: $sorting);
    }
}
