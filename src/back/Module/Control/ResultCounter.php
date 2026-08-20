<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\Module\Control;

use KeepersTeam\Webtlo\Enum\DesiredStatusChange as TopicStatus;

/**
 * Счётчик изменений статуса раздач в клиенте в процессе регулировки.
 */
final class ResultCounter
{
    /**
     * @param list<array{before: TopicStatus, after: TopicStatus}> $topics
     */
    public function __construct(private array $topics = []) {}

    /**
     * Добавить результат анализа раздачи в буфер.
     */
    public function add(bool $seeding, TopicStatus $targetStatus): void
    {
        $this->topics[] = [
            'before' => $seeding ? TopicStatus::Start : TopicStatus::Stop,
            'after'  => $targetStatus,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function getResults(): array
    {
        if ($this->topics === []) {
            return ['count' => 0];
        }

        // Разбиваем результаты на две группы.
        $seeding = array_filter($this->topics, static fn($el) => $el['before'] === TopicStatus::Start);
        $paused  = array_filter($this->topics, static fn($el) => $el['before'] === TopicStatus::Stop);

        $result = [
            'count'   => count($this->topics),
            'seeding' => count($seeding),
            'paused'  => count($paused),
        ];

        if ($seeding !== []) {
            // Группировка по имени варианта enum.
            $seedingCounts = array_count_values(
                array_map(
                    static fn(TopicStatus $e) => $e->name,
                    array_column($seeding, 'after')
                )
            );

            // Сортируем по убыванию количества.
            arsort($seedingCounts);
            $result['seedingChange'] = $seedingCounts;
        }

        if ($paused !== []) {
            // Группировка по имени варианта enum.
            $pausedCounts = array_count_values(
                array_map(
                    static fn(TopicStatus $e) => $e->name,
                    array_column($paused, 'after')
                )
            );

            // Сортируем по убыванию количества.
            arsort($pausedCounts);
            $result['pausedChange'] = $pausedCounts;
        }

        return $result;
    }

    /**
     * @param self[] $results
     */
    public static function summary(array $results): self
    {
        $topics = array_column($results, 'topics');

        return new self(topics: array_merge(...$topics));
    }
}
