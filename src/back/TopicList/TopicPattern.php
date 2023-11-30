<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\TopicList;

final class TopicPattern
{
    public function __construct(
        private readonly array  $cfg,
        private readonly string $forum_address
    ) {
    }

    public function getFormatted(Topic $topic, ?string $details = null): string
    {
        $box = [
            $topic->getCheckBox(),
            $topic->getIcon(),
        ];
        $box = implode(' ', array_filter($box));

        $date = $topic->getDate();

        $client = Helper::getClientName($this->cfg, $topic->clientId);

        $link = [
            $topic->getUrl($this->forum_address),
            $topic->getAverageSeed(),
        ];
        $link = implode(' - ', array_filter($link));

        $details = !empty($details) ? ' | ' . $details : '';

        // input icon | date | client | href - seed
        $topicParams = array_filter([$box, $date, $client, $link]);

        // div> label> checkbox icon | date | client | url - seed <label | details <div
        return sprintf(
            '<div class="topic_data"><label>%s</label>%s</div>',
            implode(' | ', $topicParams),
            $details
        );
    }
}