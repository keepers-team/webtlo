<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\TopicList\Rule;

use KeepersTeam\Webtlo\DB;
use KeepersTeam\Webtlo\Storage\Table\Forums;
use KeepersTeam\Webtlo\TopicList\Output;
use RuntimeException;

final class Factory
{
    public function __construct(
        private readonly DB     $db,
        /** @var array<string, mixed> */
        private readonly array  $cfg,
        private readonly Forums $forums,
        private readonly Output $output,
    ) {}

    /** Получить соответствующий класс для поиска раздач. */
    public function getRule(int $forumId): ListInterface
    {
        // Хранимые раздачи из других подразделов.
        if (0 === $forumId) {
            return new UntrackedTopics($this->db, $this->forums, $this->output);
        }

        if (-1 === $forumId) {
            // Хранимые раздачи незарегистрированные на форуме.
            return new UnregisteredTopics($this->db, $this->output);
        }

        if (-2 === $forumId) {
            // Раздачи из "Черного списка".
            return new BlackListedTopics($this->db, $this->forums, $this->output);
        }

        if (-4 === $forumId) {
            // Хранимые дублирующиеся раздачи.
            return new DuplicatedTopics($this->db, $this->cfg, $this->output);
        }

        if (
            // Основной поиск раздач.
            $forumId > 0        // Заданный раздел.
            || -3 === $forumId  // Все хранимые подразделы.
            || -5 === $forumId  // Высокий приоритет.
            || -6 === $forumId  // Все хранимые подразделы по спискам.
        ) {
            return new DefaultTopics($this->db, $this->cfg, $this->output, $forumId);
        }

        throw new RuntimeException('Неизвестный ид подраздела.');
    }
}
