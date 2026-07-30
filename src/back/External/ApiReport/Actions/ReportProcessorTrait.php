<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\External\ApiReport\Actions;

use KeepersTeam\Webtlo\DateHelper;
use KeepersTeam\Webtlo\External\ApiReport\KeepingStatuses;
use KeepersTeam\Webtlo\External\Data\KeptTopic;
use Throwable;

trait ReportProcessorTrait
{
    /**
     * @param array<string, mixed> $data
     */
    protected function parseTopic(array $data): ?KeptTopic
    {
        try {
            $lastUpdate = $data['last_update_time'] ?? '';
            $lastSeeded = $data['last_seeded_time'] ?? '';

            $posted = DateHelper::parseFromString(
                datetime: max($lastUpdate, $lastSeeded),
                timezone: DateHelper::UTC
            );
            if ($posted === null) {
                return null;
            }

            return new KeptTopic(
                id      : (int) $data['topic_id'],
                posted  : $posted,
                complete: !((int) $data['status'] & KeepingStatuses::Downloading->value),
                seeding : ($this->seedingChecker)($data['last_seeded_time'] ?? '')
            );
        } catch (Throwable) {
            return null;
        }
    }
}
