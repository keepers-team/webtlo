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
        $reportColumns = ['status', 'last_update_time', 'last_seeded_time'];

        $reports = $this->getForumReports($forumId, $reportColumns);
        if (null === $reports) {
            throw new RuntimeException("API. Не удалось получить данные для раздела $forumId.");
        }

        // Уберем хранителей, у которых нет раздач.
        $reports = array_filter($reports, fn($el) => $el['total_count'] > 0);

        $keepers = array_map(function($user) {
            $columns = $user['columns'];

            $topics = [];
            foreach ($user['kept_releases'] as $release) {
                $release = array_combine($columns, $release);

                // Пропускаем раздачи, которые попали в отчёты не через api.
                if (!($release['status'] & KeepingStatuses::ReportedByApi->value)) {
                    continue;
                }

                // Пропускаем раздачи, у которых нет даты.
                $posted = max($release['last_update_time'], $release['last_seeded_time']);
                if (null === $posted) {
                    continue;
                }

                try {
                    $posted = new DateTimeImmutable($posted);
                } catch (Throwable) {
                    continue;
                }

                $topics[] = new KeptTopic(
                    $release['topic_id'],
                    $posted,
                    !($release['status'] & KeepingStatuses::Downloading->value),
                );
            }

            return new KeeperTopics((int)$user['keeper_id'], $topics);
        }, $reports);

        return new KeepersResponse($forumId, $keepers);
    }
}
