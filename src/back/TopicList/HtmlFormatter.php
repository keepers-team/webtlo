<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\TopicList;

final class HtmlFormatter
{
    public const TopicRowTemplate   = '<div class="topic_data"><label>%s</label>%s</div>';

    public const ClientNameTemplate = '<i class="client bold text-success">%s</i>';

    private const UntrackedClickTemplate = "<div class='subsection-title'>%s <a href='#' onclick='%s' title='Нажмите, чтобы добавить подраздел в хранимые'>[%s]</a></div>";

    /**
     * Доступные для отображения опции.
     * TODO Добавить автора и ид раздачи.
     *
     * @var string[]
     */
    private array $columns = [
        'client',
    ];

    /**
     * @param array<int, string> $clients
     */
    public function __construct(
        private readonly array  $clients,
        private readonly string $forumUrl,
        private readonly int    $userId,
    ) {}

    /**
     * @param string[] $columns
     *
     * @return array<string, mixed>
     */
    public function format(Topics $topics, array $columns = []): array
    {
        if ($columns !== []) {
            $this->columns = array_intersect($this->columns, $columns);
        }

        $tmp = [];
        foreach ($topics->groups as $group) {
            $tmp[] = $this->formatGroup(group: $group);
        }

        $topics_count = $topics_size = 0;

        foreach ($topics->groups as $group) {
            $topics_count += count($group->topics);
            $topics_size  += array_sum(array_map(static fn($tp) => $tp->topic->size, $group->topics));
        }

        return [
            'topics'         => implode("\n", $tmp),
            'topics_count'   => $topics_count,
            'topics_size'    => $topics_size,
            'excluded_count' => $topics->excluded->count,
            'excluded_size'  => $topics->excluded->size,
        ];
    }

    private function formatGroup(TopicGroup $group): string
    {
        $tmp = [];

        if ($group->title !== null) {
            $title = $group->title;
            if ($group->metadata['add_unsaved_subsection'] ?? false) {
                $title = self::formatUnsavedSubsectionTitle(group: $group);
            } elseif (is_int($group->key)) {
                $title = sprintf('%s [%d]', $group->title, $group->key);
            }

            $tmp[] = sprintf(
                "<div class='subsection-title'>%s</div>",
                $title
            );
        }

        foreach ($group->topics as $topicResult) {
            $details = $this->formatDetails(result: $topicResult);

            $tmp[] = $this->makeHtmlRow(topic: $topicResult->topic, details: $details);
        }

        return implode("\n", $tmp);
    }

    private function formatDetails(TopicResult $result): ?string
    {
        $details = $result->details;

        // Хранители раздачи.
        if (!empty($details['keepers']) && is_array($details['keepers'])) {
            return $this->formatKeepers(keepers: $details['keepers']);
        }

        // Торрент-клиенты, в которых есть эта раздача.
        if (!empty($details['clients']) && is_array($details['clients'])) {
            return $this->formatClients(clients: $details['clients']);
        }

        // Прочие дополнительные значения.
        $merge = [];
        if (isset($details['updated_hash'])) {
            $merge[] = sprintf(
                '<input type="hidden" class="topic_hash" value="%s"/>',
                $details['updated_hash']
            );
        }
        if (isset($details['previous_name'])) {
            $merge[] = sprintf(
                '<span class="text-disabled">%s</span>',
                $details['previous_name']
            );
        }
        if (count($merge)) {
            return implode('', $merge);
        }

        return null;
    }

    private static function formatUnsavedSubsectionTitle(TopicGroup $group): string
    {
        $click = sprintf('addUnsavedSubsection(%s, "%s");', $group->key, $group->title);

        return sprintf(self::UntrackedClickTemplate, $group->title, $click, $group->key);
    }

    private function makeHtmlRow(Topic $topic, ?string $details = null): string
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

    private function getClientName(int $clientId): string
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
        if (!count($this->columns)) {
            return [];
        }

        $values = [];
        foreach ($this->columns as $option) {
            if ($option === 'client' && $topic->clientId !== null) {
                $values[] = $this->getClientName(clientId: $topic->clientId);
            }
        }

        return array_filter($values);
    }

    /**
     * @param array<string, mixed> $clients
     */
    private function formatClients(array $clients): string
    {
        $parser = self::parseStaticClientsNames();

        return $parser($clients);
    }

    /**
     * Собрать заголовок со списком клиентов, в котором есть раздача.
     *
     * @return callable(array<string, mixed>[] $torrentClients): string
     */
    private function parseStaticClientsNames(): callable
    {
        $clients = $this->clients;

        return static function(array $torrentClients) use ($clients): string {
            $torrentClientsNames = array_map(static function(array $e) use ($clients): string {
                if (empty($clientName = $clients[$e['client_id']] ?? '')) {
                    return '';
                }

                $state = State::clientOnly(topicData: $e);

                return $state->getIconElem() . ' ' . $state->getStringElem(text: $clientName, classes: 'bold');
            }, $torrentClients);

            return implode(', ', array_filter($torrentClientsNames));
        };
    }

    /**
     * Хранители раздачи в виде списка.
     *
     * @param array<string, mixed>[] $keepers
     */
    private function formatKeepers(array $keepers): string
    {
        return self::getFormattedKeepersList(topicKeepers: $keepers, userId: $this->userId);
    }

    /**
     * Хранители раздачи в виде списка.
     *
     * @param array<string, mixed>[] $topicKeepers
     */
    public static function getFormattedKeepersList(array $topicKeepers, int $userId): string
    {
        if (!count($topicKeepers)) {
            return '';
        }

        $format = static function(State $state, string $name): string {
            $tagIcon = $state->getIconElem();
            $tagName = $state->getStringElem(text: $name, classes: 'keeper bold');

            return "$tagIcon $tagName";
        };

        $keepersNames = array_map(static function($e) use ($userId, $format) {
            if ($e['complete']) {
                if (!$e['posted']) {
                    $keeperIcon = StateKeeperIcon::NotListedSeeding;
                } else {
                    $keeperIcon = $e['seeding']
                        ? StateKeeperIcon::Seeding
                        : StateKeeperIcon::Inactive;
                }

                $stateColor = StateColor::Success;
            } else {
                $keeperIcon = StateKeeperIcon::Downloading;
                $stateColor = StateColor::Danger;
            }

            if ($userId === (int) $e['keeper_id']) {
                $stateColor = StateColor::Self;
            }

            // Собрать заголовок для хранителя в зависимости от его связи с раздачей.
            $state = new State($keeperIcon, $stateColor, $keeperIcon->label());

            return $format(state: $state, name: (string) $e['keeper_name']);
        }, $topicKeepers);

        return implode(', ', $keepersNames);
    }
}
