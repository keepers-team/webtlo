<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\TopicList;

use DateTimeImmutable;

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
    ) {
    }

    public static function fromTopicData(array $topicData, ?State $state = null): self
    {
        return new self(
            $topicData['topic_id'],
            $topicData['info_hash'],
            $topicData['name'],
            $topicData['size'],
            Helper::setTimestamp((int)$topicData['reg_time']),
            $topicData['forum_id'] ?? null,
            round($topicData['seed'] ?? -1, 2),
            $topicData['priority'] ?? null,
            $state,
            $topicData['client_id'] ?? null,
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
        if (null === $this->state) {
            return '';
        }

        return $this->state->getIconElem();
    }

    public function getDate(): string
    {
        return sprintf('<span>%s</span>', $this->regDate->format('d.m.Y'));
    }

    public function getUrl(string $forum_address): string
    {
        $url  = sprintf('%s/forum/viewtopic.php?t=%d', $forum_address, $this->id);
        $size = convert_bytes($this->size);

        return "<a href='$url' target='_blank'>$this->name</a> ($size)";
    }

    public function getAverageSeed(): string
    {
        if (null === $this->averageSeed || $this->averageSeed < 0) {
            return '';
        }


        return sprintf(
            "<span class='text-danger %s'>%s</span>",
            $this->getSeedClassName((int)floor($this->averageSeed)),
            $this->averageSeed,
        );
    }

    private function getSeedClassName(int $seeds): string
    {
        return sprintf('seed-has-%s', $seeds <= 10 ? $seeds : min(30, floor($seeds / 10) * 10));
    }
}