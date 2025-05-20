<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\Storage\Clone;

use KeepersTeam\Webtlo\External\Api\V1\AverageSeeds;
use KeepersTeam\Webtlo\Storage\CloneTable;

/**
 * Временная таблица с данными о новых раздачах, по данным API форума.
 */
final class SeedersInsert
{
    // Параметры таблицы.
    public const TABLE   = 'Seeders';
    public const PRIMARY = 'id';

    /** @var list<int[]> */
    private array $topics = [];

    public function __construct(
        private readonly CloneTable $clone,
    ) {}

    /**
     * @return string[]
     */
    public static function makeKeysList(): array
    {
        $dFields = $qFields = [];
        for ($i = 0; $i <= 29; ++$i) {
            $dFields[] = "d$i"; // Количество замеров в заданный день.
            $qFields[] = "q$i"; // Сумма замеров в заданный день.
        }

        return [
            self::PRIMARY,
            ...$dFields,
            ...$qFields,
        ];
    }

    public function addTopic(int $topicId, AverageSeeds $seeds): void
    {
        $this->topics[] = [$topicId, ...$seeds->sumHistory, ...$seeds->countHistory];
    }

    /**
     * Записать часть раздач во временную таблицу.
     */
    public function cloneFill(): void
    {
        if (!count($this->topics)) {
            return;
        }

        $tab = $this->clone;

        $rows = array_map(static fn($el) => array_combine($tab->getTableKeys(), $el), $this->topics);
        $tab->cloneFill($rows);

        $this->topics = [];
    }

    public function writeTable(): int
    {
        return $this->clone->writeTable();
    }
}
