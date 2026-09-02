<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\TopicList;

final class JsonFormatter
{
    private const Mandatory = [
        'id',
        'hash',
    ];

    /**
     * Доступные для отображения параметры раздачи.
     *
     * @var string[]
     */
    private const Available = [
        'name',
        'size',
        'priority',
        'average_seed',
        'reg_date',
        'forum_id',
        'client_id',
        'state',
        'details',
    ];

    /**
     * @param string[] $columns
     *
     * @return array<string, mixed>
     */
    public function format(Topics $topics, array $columns = []): array
    {
        if ($columns !== []) {
            $columns = array_intersect(self::Available, $columns);
        }
        $columns = array_flip(array_merge(self::Mandatory, $columns));

        $formatted = array_map(static function(TopicGroup $group) use ($columns) {
            return [
                'group_key'    => $group->key,
                'group_tittle' => $group->title,
                'group_topics' => array_map(static function(TopicResult $topic) use ($columns) {
                    $data = [
                        'id'           => $topic->topic->id,
                        'hash'         => $topic->topic->hash,
                        'name'         => $topic->topic->name,
                        'size'         => $topic->topic->size,
                        'priority'     => $topic->topic->priority,
                        'average_seed' => $topic->topic->averageSeed,
                        'reg_date'     => $topic->topic->regDate->format('Y-m-d H:i:s'),
                        'forum_id'     => $topic->topic->forumId,
                        'client_id'    => $topic->topic->clientId,
                        'state'        => $topic->topic->state,
                        'details'      => $topic->details,
                    ];

                    return array_intersect_key($data, $columns);
                }, $group->topics),
                'metadata'     => $group->metadata,
            ];
        }, $topics->groups);

        $topics_count = $topics_size = 0;

        foreach ($topics->groups as $group) {
            $topics_count += count($group->topics);
            $topics_size  += array_sum(array_map(static fn($tp) => $tp->topic->size, $group->topics));
        }

        return [
            'topics'         => $formatted,
            'topics_count'   => $topics_count,
            'topics_size'    => $topics_size,
            'excluded_count' => $topics->excluded->count,
            'excluded_size'  => $topics->excluded->size,
        ];
    }
}
