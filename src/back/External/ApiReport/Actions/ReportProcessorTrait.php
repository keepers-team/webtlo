<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\External\ApiReport\Actions;

use DateTimeImmutable;
use DateTimeZone;
use KeepersTeam\Webtlo\External\ApiReport\KeepingStatuses;
use KeepersTeam\Webtlo\External\ApiReport\V1\KeptTopic;
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

            $posted = self::parseDateTime(max($lastUpdate, $lastSeeded));
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

    /**
     * Попытка обработать дату из API, всегда в UTC зоне.
     */
    private static function parseDateTime(string $time): ?DateTimeImmutable
    {
        try {
            return new DateTimeImmutable($time, new DateTimeZone('UTC'));
        } catch (Throwable) {
            return null;
        }
    }
}
