<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\TopicList;

final class Formatter
{
    public const TopicRowTemplate   = '<div class="topic_data"><label>%s</label>%s</div>';
    public const ClientNameTemplate = '<i class="client bold text-success">%s</i>';

    /**
     * Доступные для отображения опции.
     * TODO Добавить автора и ид раздачи.
     *
     * @var string[]
     */
    private array $options = [
        'client',
    ];

    /**
     * @param array<int, string> $clients
     */
    public function __construct(
        public readonly array  $clients,
        public readonly string $forumUrl,
    ) {}

    /**
     * @param string[] $filter
     */
    public function setFilter(array $filter): void
    {
        $this->options = array_intersect($this->options, $filter);
    }

    public function formatTopic(Topic $topic, ?string $details = null): string
    {
        $box = $this->getBoxString(topic: $topic);

        $date = $topic->getDate();

        $selected = $this->getSelectedStrings(topic: $topic);

        $link = $this->getLinkString(topic: $topic);

        $details = !empty($details) ? ' | ' . $details : '';

        // input icon | date | selected | href - seed
        $topicParams = array_filter([$box, $date, ...$selected, $link]);

        // div> label> checkbox icon | date | selected | url - seed <label | details <div
        return sprintf(
            self::TopicRowTemplate,
            implode(' | ', $topicParams),
            $details
        );
    }

    public function getClientName(int $clientId): string
    {
        if (!isset($this->clients[$clientId])) {
            return '';
        }

        return sprintf(self::ClientNameTemplate, $this->clients[$clientId]);
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
            $topic->getUrl(forumUrl: $this->forumUrl),
            $topic->getAverageSeed(),
        ];

        return implode(' - ', array_filter($link));
    }

    /**
     * Блок выбираемых к отображению полей.
     *
     * @return string[]
     */
    private function getSelectedStrings(Topic $topic): array
    {
        if (!count($this->options)) {
            return [];
        }

        $values = [];
        foreach ($this->options as $option) {
            if ($option === 'client' && $topic->clientId !== null) {
                $values[] = $this->getClientName(clientId: $topic->clientId);
            }
        }

        return array_filter($values);
    }
}
