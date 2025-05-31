<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\TopicList;

use DateTimeImmutable;
use KeepersTeam\Webtlo\Helper;

final class Topic
{
    public function __construct(
        public readonly int               $id,
        public readonly string            $hash,
        public readonly string            $name,
        public readonly int               $size,
        public readonly DateTimeImmutable $regDate,
        public readonly ?int              $forumId = null,
        public readonly ?float            $averageSeed = null,
        public readonly ?int              $priority = null,
        public readonly ?State            $state = null,
        public readonly ?int              $clientId = null,
    ) {}

    /**
     * @param array<string, int|string> $topicData
     */
    public static function fromTopicData(array $topicData, ?State $state = null): self
    {
        return new self(
            id         : (int) $topicData['topic_id'],
            hash       : (string) $topicData['info_hash'],
            name       : (string) $topicData['name'],
            size       : (int) $topicData['size'],
            regDate    : Helper::makeDateTime((int) $topicData['reg_time']),
            forumId    : !empty($topicData['forum_id']) ? (int) $topicData['forum_id'] : null,
            averageSeed: round((float) ($topicData['seed'] ?? -1), 2),
            priority   : !empty($topicData['priority']) ? (int) $topicData['priority'] : null,
            state      : $state,
            clientId   : !empty($topicData['client_id']) ? (int) $topicData['client_id'] : null
        );
    }

    public function getCheckBox(): string
    {
        return sprintf(
            "<input type='checkbox' name='topic_hashes[]' class='topic' value='%s' data-size='%d'>",
            $this->hash,
            $this->size
        );
    }

    public function getIcon(): string
    {
        if ($this->state === null) {
            return '';
        }

        return $this->state->getIconElem();
    }

    public function getDate(): string
    {
        return sprintf('<span>%s</span>', $this->regDate->format('d.m.Y'));
    }

    public function getUrl(string $forumUrl): string
    {
        $url  = sprintf('%s/forum/viewtopic.php?t=%d', $forumUrl, $this->id);
        $size = Helper::convertBytes($this->size);

        $pattern = /** @lang text */
            "<a href='%s' target='_blank'>%s</a> (%s)";

        return sprintf($pattern, $url, $this->name, $size);
    }

    public function getAverageSeed(): string
    {
        if ($this->averageSeed === null || $this->averageSeed < 0) {
            return '';
        }

        return sprintf(
            "<span class='text-danger %s'>%s</span>",
            $this->getSeedClassName((int) floor($this->averageSeed)),
            $this->averageSeed,
        );
    }

    private function getSeedClassName(int $seeds): string
    {
        static $cache = [];

        if (!isset($cache[$seeds])) {
            $cache[$seeds] = 'seed-has-' . ($seeds <= 10 ? $seeds : min(30, floor($seeds / 10) * 10));
        }

        return $cache[$seeds];
    }
}
