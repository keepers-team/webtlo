<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\TopicList\Rule;

use KeepersTeam\Webtlo\DB;
use KeepersTeam\Webtlo\TopicList\Output;
use RuntimeException;

final class Factory
{
    public function __construct(
        private readonly DB     $db,
        /** @var array<string, mixed> */
        private readonly array  $cfg,
        private readonly Output $output
    ) {
    }

    /** Получить соответствующий класс для поиска раздач. */
    public function getRule(int $forumId): ListInterface
    {
        // Хранимые раздачи из других подразделов.
        if ($forumId === 0) {
            return new UntrackedTopics($this->db, $this->output);
        } elseif ($forumId === -1) {
            // Хранимые раздачи незарегистрированные на форуме.
            return new UnregisteredTopics($this->db, $this->output);
        } elseif ($forumId === -2) {
            // Раздачи из "Черного списка".
            return new BlackListedTopics($this->db, $this->output);
        } elseif ($forumId === -4) {
            // Хранимые дублирующиеся раздачи.
            return new DuplicatedTopics($this->db, $this->cfg, $this->output);
        } elseif (
            // Основной поиск раздач.
            $forumId > 0        // Заданный раздел.
            || $forumId === -3  // Все хранимые подразделы.
            || $forumId === -5  // Высокий приоритет.
            || $forumId === -6  // Все хранимые подразделы по спискам.
        ) {
            return new DefaultTopics($this->db, $this->cfg, $this->output, $forumId);
        }

        throw new RuntimeException('Неизвестный ид подраздела.');
    }
}
