<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\TopicList\Rule;

use KeepersTeam\Webtlo\DB;
use KeepersTeam\Webtlo\Storage\Table\Forums;
use KeepersTeam\Webtlo\TopicList\ConfigFilter;
use KeepersTeam\Webtlo\TopicList\Formatter;
use RuntimeException;

final class Factory
{
    public function __construct(
        private readonly DB           $db,
        private readonly Forums       $forums,
        private readonly ConfigFilter $configFilter,
        private readonly Formatter    $formatter,
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

        // Хранимые раздачи из других подразделов.
        if ($forumId === 0) {
            return new UntrackedTopics($this->db, $this->forums, $this->formatter);
        }

        if ($forumId === -1) {
            // Хранимые раздачи незарегистрированные на форуме.
            return new UnregisteredTopics($this->db, $this->formatter);
        }

        if ($forumId === -2) {
            // Раздачи из "Черного списка".
            return new BlackListedTopics($this->db, $this->forums, $this->formatter);
        }

        if ($forumId === -4) {
            // Хранимые дублирующиеся раздачи.
            return new DuplicatedTopics($this->db, $this->formatter, $this->configFilter);
        }

        if (
            // Основной поиск раздач.
            $forumId > 0        // Заданный раздел.
            || $forumId === -3  // Все хранимые подразделы.
            || $forumId === -5  // Высокий приоритет.
            || $forumId === -6  // Все хранимые подразделы по спискам.
        ) {
            return new DefaultTopics(
                db          : $this->db,
                configFilter: $this->configFilter,
                formatter   : $this->formatter,
                forumId     : $forumId
            );
        }

        throw new RuntimeException('Неизвестный ид подраздела.');
    }
}
