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
        $state = $this->state;
        if (null === $this->state) {
            return '';
        }

        return sprintf("<i class='fa fa-size %s %s' title='%s'></i>", $state->icon, $state->color, $state->title);
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
        if (null === $this->averageSeed) {
            return '';
        }

        return sprintf("<span class='text-danger'>%s</span>", $this->averageSeed);
    }
}