<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\External\ApiReport\Actions;

use DateTimeImmutable;
use KeepersTeam\Webtlo\External\ApiReport\KeepingStatuses;
use KeepersTeam\Webtlo\External\ApiReport\V1\KeepersResponse;
use KeepersTeam\Webtlo\External\ApiReport\V1\KeeperTopics;
use KeepersTeam\Webtlo\External\ApiReport\V1\KeptTopic;
use RuntimeException;
use Throwable;

trait KeepersReports
{
    public function getKeepersReports(int $forumId): KeepersResponse
    {
        $reports = $this->getForumReports(
            forumId: $forumId,
            columns: ['status', 'last_update_time', 'last_seeded_time']
        );
        if ($reports === null) {
            throw new RuntimeException("API. Не удалось получить данные для раздела $forumId.");
        }

        $isTopicSeeding = self::getStaticIsSeedingProcessor();

        // Используем генератор для ленивой обработки хранителей.
        $keepersGenerator = static function() use ($reports, $isTopicSeeding) {
            foreach ($reports as $keeper) {
                if ($keeper['total_count'] <= 0) {
                    continue;
                }

                $columns  = $keeper['columns'];
                $keeperId = (int) $keeper['keeper_id'];
                $topics   = [];

                foreach ($keeper['kept_releases'] as $release) {
                    $release = array_combine($columns, $release);

                    try {
                        // Вычисляем дату отчёта как максимальную из двух дат.
                        $posted = max($release['last_update_time'], $release['last_seeded_time']);

                        // Пропускаем раздачи без дат.
                        if (empty($posted) || !is_string($posted)) {
                            continue;
                        }

                        $postedDate = new DateTimeImmutable($posted);
                    } catch (Throwable) {
                        continue;
                    }

                    // Вычисляем $seeding только если есть last_seeded_time.
                    $seeding = false;
                    if (isset($release['last_seeded_time']) && is_string($release['last_seeded_time'])) {
                        $seeding = $isTopicSeeding($release['last_seeded_time']);
                    }

                    $topics[] = new KeptTopic(
                        id      : (int) $release['topic_id'],
                        posted  : $postedDate,
                        complete: !($release['status'] & KeepingStatuses::Downloading->value),
                        seeding : $seeding
                    );
                }

                if (count($topics)) {
                    yield new KeeperTopics(
                        keeperId   : $keeperId,
                        topicsCount: count($topics),
                        topics     : $topics,
                    );
                }
            }
        };

        return new KeepersResponse(
            forumId: $forumId,
            keepers: $keepersGenerator(),
        );
    }

    /**
     * Создает и возвращает callable-функцию для проверки, является ли раздача "сидируемой".
     *
     * @return callable(string): bool функция-предикат для проверки актуальности сидирования
     */
    private static function getStaticIsSeedingProcessor(): callable
    {
        // Вычисляем временные границы для $seeding.
        $currentTime = new DateTimeImmutable('now');
        $twoHoursAgo = $currentTime->modify('-2 hours');

        return static function(string $lastSeededTime) use ($currentTime, $twoHoursAgo): bool {
            try {
                $seededDate = new DateTimeImmutable($lastSeededTime);

                return ($seededDate >= $twoHoursAgo) && ($seededDate <= $currentTime);
            } catch (Throwable) {
                // Игнорируем ошибку вычисления даты.
            }

            return false;
        };
    }
}
