<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\TopicList\Rule;

use KeepersTeam\Webtlo\TopicList\State;
use KeepersTeam\Webtlo\TopicList\StateColor;
use KeepersTeam\Webtlo\TopicList\StateKeeperIcon;

trait FormatKeepersTrait
{
    /**
     * Хранители раздачи в виде списка.
     *
     * @param array<string, mixed>[] $topicKeepers
     */
    public static function getFormattedKeepersList(array $topicKeepers, int $user_id): string
    {
        if (!count($topicKeepers)) {
            return '';
        }

        $format = static function(State $state, string $name): string {
            $tagIcon = $state->getIconElem();
            $tagName = $state->getStringElem(text: $name, classes: 'keeper bold');

            return "$tagIcon $tagName";
        };

        $keepersNames = array_map(static function($e) use ($user_id, $format) {
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

            if ($user_id === (int) $e['keeper_id']) {
                $stateColor = StateColor::Self;
            }

            // Собрать заголовок для хранителя в зависимости от его связи с раздачей.
            $state = new State($keeperIcon, $stateColor, $keeperIcon->label());

            return $format(state: $state, name: (string) $e['keeper_name']);
        }, $topicKeepers);

        return implode(', ', $keepersNames);
    }
}
