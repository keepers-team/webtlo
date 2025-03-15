<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\TopicList;

use DateTimeImmutable;

final class Helper
{
    /**
     * Собрать наименование клиента.
     *
     * @param array<string, mixed> $cfg
     */
    public static function getClientName(array $cfg, ?int $clientID): string
    {
        if (!$clientID || !isset($cfg['clients'][$clientID])) {
            return '';
        }

        return sprintf(
            '<i class="client bold text-success">%s</i>',
            $cfg['clients'][$clientID]['cm']
        );
    }

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

        $format = function(State $state, string $name): string {
            $tagIcon = $state->getIconElem();
            $tagName = $state->getStringElem($name, 'keeper bold');

            return "$tagIcon $tagName";
        };

        $keepersNames = array_map(function($e) use ($user_id, $format) {
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

            return $format($state, (string) $e['keeper_name']);
        }, $topicKeepers);

        return implode(', ', $keepersNames);
    }

    /**
     * Собрать заголовок со списком клиентов, в котором есть раздача.
     *
     * @param array<string, mixed>[] $cfgClients
     * @param array<string, mixed>[] $listTorrentClientsIDs
     */
    public static function getFormattedClientsList(array $cfgClients, array $listTorrentClientsIDs): string
    {
        $listTorrentClientsNames = array_map(function($e) use ($cfgClients): string {
            if (isset($cfgClients[$e['client_id']])) {
                $state = State::clientOnly($e);

                $icon = $state->getIconElem();
                $name = $state->getStringElem($cfgClients[$e['client_id']]['cm'], 'bold');

                return "$icon $name";
            }

            return '';
        }, $listTorrentClientsIDs);

        return implode(', ', array_filter($listTorrentClientsNames));
    }

    /** Дата из timestamp */
    public static function setTimestamp(int $timestamp): DateTimeImmutable
    {
        return (new DateTimeImmutable())->setTimestamp($timestamp);
    }
}
