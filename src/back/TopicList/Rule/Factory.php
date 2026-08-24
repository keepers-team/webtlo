<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\TopicList\Rule;

use KeepersTeam\Webtlo\Infrastructure\Database\ConnectionInterface;
use KeepersTeam\Webtlo\Storage\Table\Forums;
use KeepersTeam\Webtlo\TopicList\ConfigFilter;
use KeepersTeam\Webtlo\TopicList\Formatter;
use KeepersTeam\Webtlo\TopicList\ListingType;
use RuntimeException;

final class Factory
{
    public function __construct(
        private readonly ConnectionInterface $con,
        private readonly Forums              $forums,
        private readonly ConfigFilter        $configFilter,
        private readonly Formatter           $formatter,
    ) {}

    /**
     * Получить соответствующий класс для поиска раздач.
     *
     * @param ?string[] $filter
     */
    public function getRule(int $forumId, ?array $filter = null): ListInterface
    {
        if ($filter !== null) {
            $this->formatter->setFilter(filter: $filter);
        }

        $listingType = ListingType::tryFrom($forumId);

        // Хранимые раздачи из других подразделов.
        if ($listingType === ListingType::OtherSubForums) {
            return new UntrackedTopics($this->con, $this->forums, $this->formatter);
        }

        // Хранимые раздачи незарегистрированные на форуме.
        if ($listingType === ListingType::Unregistered) {
            return new UnregisteredTopics($this->con, $this->formatter);
        }

        // Раздачи из "Черного списка".
        if ($listingType === ListingType::BlackListed) {
            return new BlackListedTopics($this->con, $this->forums, $this->formatter);
        }

        // Хранимые дублирующиеся раздачи.
        if ($listingType === ListingType::Duplicated) {
            return new DuplicatedTopics($this->con, $this->formatter, $this->configFilter);
        }

        // Основной поиск раздач.
        if (
            // Заданный раздел.
            $forumId > 0
            // Все хранимые подразделы.
            || $listingType === ListingType::AllKept
            // Высокий приоритет.
            || $listingType === ListingType::HighPriority
            // Все хранимые подразделы по спискам.
            || $listingType === ListingType::SelfKeep
        ) {
            return new DefaultTopics(
                con         : $this->con,
                configFilter: $this->configFilter,
                formatter   : $this->formatter,
                listingType : $listingType ?? $forumId,
            );
        }

        throw new RuntimeException("Некорректный идентификатор подраздела: $forumId");
    }
}
