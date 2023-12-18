<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\TopicList;

final class Output
{
    public function __construct(
        private readonly array  $cfg,
        private readonly string $forum_address,
        private readonly ?array $filter = null
    ) {
    }

    public function formatTopic(Topic $topic, ?string $details = null): string
    {
        $box = $this->getBoxString($topic);

        $date = $topic->getDate();

        $selected = $this->getSelectedStrings($topic, $this->filter);

        $link = $this->getLinkString($topic);

        $details = !empty($details) ? ' | ' . $details : '';

        // input icon | date | selected | href - seed
        $topicParams = array_filter([$box, $date, ...$selected, $link]);

        // div> label> checkbox icon | date | selected | url - seed <label | details <div
        return sprintf(
            '<div class="topic_data"><label>%s</label>%s</div>',
            implode(' | ', $topicParams),
            $details
        );
    }

    /** Первый блок, чекбокс + иконка/статус раздачи. */
    private function getBoxString(Topic $topic): string
    {
        $box = [
            $topic->getCheckBox(),
            $topic->getIcon(),
        ];

        return implode(' ', array_filter($box));
    }

    /** Последний блок, ссылка на раздачу + количество сидов. */
    private function getLinkString(Topic $topic): string
    {
        $link = [
            $topic->getUrl($this->forum_address),
            $topic->getAverageSeed(),
        ];

        return implode(' - ', array_filter($link));
    }

    /** Блок выбираемых к отображению полей. */
    private function getSelectedStrings(Topic $topic, ?array $filter = null): array
    {
        // TODO Добавить автора и ид раздачи.
        $options = [
            'client' => Helper::getClientName($this->cfg, $topic->clientId),
        ];

        if (null !== $filter) {
            $options = array_intersect_key(
                $options,
                array_flip($filter)
            );
        }

        return array_filter($options);
    }
}