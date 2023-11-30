<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\TopicList;

use DateTimeImmutable;
use KeepersTeam\Webtlo\Helper as TloHelper;

final class Helper
{
    /** Сортировка задач по параметрам фильтра. */
    public static function topicsSortByFilter(array $topics, array $filter): array
    {
        return TloHelper::natsortField(
            $topics,
            $filter['filter_sort'],
            (int)$filter['filter_sort_direction']
        );
    }

    /** Собрать наименование клиента. */
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

    /** Собрать заголовок для хранителя в зависимости от его связи с раздачей. */
    public static function getKeeperTitle(string $state): string
    {
        $keeperBullets = [
            'upload'              => 'Есть в списке и раздаёт',
            'hard-drive'          => 'Есть в списке, не раздаёт',
            'arrow-circle-o-up'   => 'Нет в списке и раздаёт',
            'arrow-circle-o-down' => 'Скачивает',
        ];

        return $keeperBullets[$state] ?? '';
    }

    /** Хранители раздачи в виде списка. */
    public static function getFormattedKeepersList(array $topicKeepers, int $user_id): string
    {
        if (!count($topicKeepers)) {
            return '';
        }

        $format = function(string $icon, string $color, string $name, string $title): string {
            $tagIcon = sprintf('<i class="fa fa-%s text-%s" title="%s"></i>', $icon, $color, $title);
            $tagName = sprintf('<i class="keeper bold text-%s" title="%s">%s</i>', $color, $title, $name);

            return "$tagIcon $tagName";
        };

        $keepersNames = array_map(function($e) use ($user_id, $format) {
            if ($e['complete'] == 1) {
                if ($e['posted'] === 0) {
                    $stateIcon = 'arrow-circle-o-up';
                } else {
                    $stateIcon = $e['seeding'] == 1 ? 'upload' : 'hard-drive';
                }
                $stateColor = 'success';
            } else {
                $stateIcon  = 'arrow-circle-o-down';
                $stateColor = 'danger';
            }
            if ($user_id === (int)$e['keeper_id']) {
                $stateColor = 'self';
            }

            return $format($stateIcon, $stateColor, (string)$e['keeper_name'], self::getKeeperTitle($stateIcon));
        }, $topicKeepers);

        return implode(', ', $keepersNames);
    }


    /** Собрать заголовок со списком клиентов, в котором есть раздача. */
    public static function getFormattedClientsList(array $cfgClients, array $listTorrentClientsIDs): string
    {
        $listTorrentClientsNames = array_map(function($e) use ($cfgClients): string {
            if (isset($cfgClients[$e['client_id']])) {
                $clientState = State::getClientState($e);
                $stateColor  = State::getClientColor($e);
                $clientTitle = State::getClientTitle($clientState);

                $icon = sprintf("<i class='fa %s %s' title='%s'></i>", $clientState, $stateColor, $clientTitle);
                $name = sprintf("<i class='bold %s' title='%s'>%s</i>", $stateColor, $clientTitle, $cfgClients[$e['client_id']]['cm']);

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