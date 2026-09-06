<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\TopicList\Rule;

use KeepersTeam\Webtlo\Infrastructure\Database\ConnectionInterface;
use KeepersTeam\Webtlo\Storage\Table\Forums;
use KeepersTeam\Webtlo\TopicList\ConfigFilter;
use KeepersTeam\Webtlo\TopicList\ListingType;
use RuntimeException;

final class Factory
{
    public function __construct(
        private readonly ConnectionInterface $con,
        private readonly Forums              $forums,
        private readonly ConfigFilter        $configFilter,
    ) {}

    /**
     * Получить соответствующий класс для поиска раздач.
     */
    public function getRule(int $listingId): ListInterface
    {
        $listingType = ListingType::tryFallBack($listingId);

        // Хранимые раздачи из других подразделов.
        if ($listingType === ListingType::OtherSubForums) {
            return new UntrackedTopics($this->con, $this->forums);
        }

        // Хранимые раздачи незарегистрированные на форуме.
        if ($listingType === ListingType::Unregistered) {
            return new UnregisteredTopics($this->con);
        }

        // Раздачи из "Черного списка".
        if ($listingType === ListingType::BlackListed) {
            return new BlackListedTopics($this->con, $this->forums);
        }

        // Хранимые дублирующиеся раздачи.
        if ($listingType === ListingType::Duplicated) {
            return new DuplicatedTopics($this->con, $this->configFilter);
        }

        // Основной поиск раздач.
        if (
            // Заданный раздел.
            $listingId > 0
            // Все хранимые подразделы.
            || $listingType === ListingType::AllKeptShowed
            || $listingType === ListingType::AllKeptHidden
            // Высокий приоритет.
            || $listingType === ListingType::HighPriority
            // Все хранимые подразделы по спискам.
            || $listingType === ListingType::SelfKeep
        ) {
            return new DefaultTopics(
                con         : $this->con,
                configFilter: $this->configFilter,
                listingType : $listingType ?? $listingId,
            );
        }

        throw new RuntimeException("Некорректный идентификатор подраздела/разворота: $listingId");
    }
}
