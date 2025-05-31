<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\Module;

use KeepersTeam\Webtlo\Config\ApiForumConnect;
use KeepersTeam\Webtlo\Config\ApiReportConnect;
use KeepersTeam\Webtlo\Config\Automation;
use KeepersTeam\Webtlo\Config\ForumConnect;
use KeepersTeam\Webtlo\Config\ReportSend;
use KeepersTeam\Webtlo\Config\SubForums;
use KeepersTeam\Webtlo\Config\TopicControl;
use KeepersTeam\Webtlo\Config\TorrentClients;
use KeepersTeam\Webtlo\Storage\Table\Torrents;
use KeepersTeam\Webtlo\Storage\Table\UpdateTime;
use KeepersTeam\Webtlo\WebTLO;

/**
 * Собрать информацию об используемом ПО из данных конфига.
 */
final class TelemetryConstruct
{
    public function __construct(
        private readonly WebTLO           $webtlo,
        private readonly Automation       $automation,
        private readonly ApiForumConnect  $apiForumConnect,
        private readonly ApiReportConnect $apiReportConnect,
        private readonly ForumConnect     $forumConnect,
        private readonly TopicControl     $topicControl,
        private readonly ReportSend       $reportSend,
        private readonly TorrentClients   $clients,
        private readonly SubForums        $subForums,
        private readonly UpdateTime       $updateTime,
        private readonly Torrents         $torrents,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function getInfo(): array
    {
        $shared = [
            'software' => $this->webtlo->getSoftwareInfo(),
            'proxy'    => [
                'activate_forum'  => $this->forumConnect->useProxy,
                'activate_api'    => $this->apiForumConnect->useProxy,
                'activate_report' => $this->apiReportConnect->useProxy,
            ],
        ];

        // Уберём исключённые торрент-клиенты из выборки.
        $clients = array_filter($this->clients->clients, static fn($el) => !$el->exclude);

        // Тип и количество используемых торрент-клиентов.
        $distribution = array_count_values(array_map(static fn($el) => $el->type->value, $clients));

        // Количество раздач в используемых торрент-клиентах.
        $clientTopics = [];
        foreach ($this->torrents->getClientsTopics() as $clientId => $topics) {
            if ($client = $this->clients->getClientOptions(clientId: (int) $clientId)) {
                if ($client->exclude) {
                    continue;
                }

                $clientName = sprintf('%s-%d', $client->type->value, $client->id);

                $clientTopics[$clientName] = array_filter($topics);
            }
        }

        // Данные о торрент-клиентах.
        $shared['clients'] = [
            'distribution' => $distribution,
            'topics'       => $clientTopics,
        ];

        // Регулировка по подразделам.
        $subsections = array_filter($this->subForums->params, static fn($el) => $el->controlPeers !== -2);
        $subsections = array_map(static fn($el) => $el->controlPeers, $subsections);

        ksort($subsections);

        // Параметры регулировки.
        $shared['control'] = [
            'enabled'     => $this->automation->control,
            'peers'       => $this->topicControl->peersLimit,
            'intervals'   => $this->topicControl->peersLimitIntervals,
            'keepers'     => $this->topicControl->excludedKeepersCount,
            'random'      => $this->topicControl->randomApplyCount,
            'unseeded'    => [
                'days'  => $this->topicControl->daysUntilUnseeded,
                'count' => $this->topicControl->maxUnseededCount,
            ],
            'subsections' => $subsections,
        ];

        // Параметры отправки отчётов.
        $shared['reports'] = [
            'enabled'             => $this->automation->reports,
            'send_report_api'     => $this->reportSend->sendReports,
            'send_summary_report' => $this->reportSend->sendSummary,
            'exclude_authored'    => $this->reportSend->excludeAuthored,
            'unset_other_forums'  => $this->reportSend->unsetOtherSubForums,
            'unset_other_topics'  => $this->reportSend->unsetOtherTopics,
        ];

        // Локальные даты обновления сведений.
        $shared['markers'] = $this->updateTime->getMainMarkers()->getFormattedMarkers();

        return $shared;
    }
}
