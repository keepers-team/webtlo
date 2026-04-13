<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\Front;

use KeepersTeam\Webtlo\Config\ApiCredentials;
use KeepersTeam\Webtlo\Config\ApiForumConnect;
use KeepersTeam\Webtlo\Config\ApiReportConnect;
use KeepersTeam\Webtlo\Config\Automation;
use KeepersTeam\Webtlo\Config\AverageSeeds;
use KeepersTeam\Webtlo\Config\FilterRules;
use KeepersTeam\Webtlo\Config\ForumConnect;
use KeepersTeam\Webtlo\Config\ForumCredentials;
use KeepersTeam\Webtlo\Config\Other;
use KeepersTeam\Webtlo\Config\Proxy;
use KeepersTeam\Webtlo\Config\ProxyType;
use KeepersTeam\Webtlo\Config\ReportSend;
use KeepersTeam\Webtlo\Config\SubForums;
use KeepersTeam\Webtlo\Config\TopicControl;
use KeepersTeam\Webtlo\Config\TopicSearch;
use KeepersTeam\Webtlo\Config\TorrentClients;
use KeepersTeam\Webtlo\Config\TorrentDownload;
use KeepersTeam\Webtlo\Logger\LoggerConstructor;
use KeepersTeam\Webtlo\WebTLO;

final class Render
{
    /** @noinspection HtmlUnknownAttribute */
    final public const optionTemplate = '<option value="%s" %s>%s</option>';

    /** @noinspection HtmlUnknownAttribute */
    final public const itemTemplate = '<li class="ui-widget-content" value="%s" %s>%s</li>';

    public function __construct(
        private readonly Automation       $automation,
        private readonly AverageSeeds     $averageSeeds,
        private readonly ApiCredentials   $apiAuth,
        private readonly ApiForumConnect  $apiForumConnect,
        private readonly ApiReportConnect $apiReportConnect,
        private readonly ForumConnect     $forumConnect,
        private readonly ForumCredentials $forumAuth,
        private readonly TorrentClients   $torrentClients,
        private readonly TorrentDownload  $torrentDownload,
        private readonly Proxy            $proxy,
        private readonly FilterRules      $filterRules,
        private readonly ReportSend       $reportSend,
        private readonly SubForums        $subForums,
        private readonly TopicControl     $topicControl,
        private readonly TopicSearch      $topicSearch,
        private readonly Other            $other,
        private readonly WebTLO           $webtlo,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function getConfig(): array
    {
        return [
            'webtlo' => [
                'version'  => $this->webtlo->version,
                'wiki'     => $this->webtlo->wiki,
                'wikiLink' => $this->webtlo->getWikiLink(),
                'release'  => $this->webtlo->getReleaseLink(),
                'commit'   => $this->webtlo->getCommitLink(),
                'install'  => $this->webtlo->getInstallation(),
            ],

            'forumAuth' => [
                'username' => self::escape($this->forumAuth->auth->username),
                'password' => self::escape($this->forumAuth->auth->password),
                'session'  => self::escape($this->forumAuth->session),
            ],

            'apiAuth' => Reflection::reflect($this->apiAuth),

            'forumConnect' => [
                'options' => $this->forumConnect->getSelectOptions(),
                'custom'  => $this->forumConnect->getCustomUrl(),
                'ssl'     => $this->checkbox($this->forumConnect->ssl),
                'proxy'   => $this->checkbox($this->forumConnect->useProxy),
            ],

            'apiForumConnect' => [
                'options' => $this->apiForumConnect->getSelectOptions(),
                'custom'  => $this->apiForumConnect->getCustomUrl(),
                'ssl'     => $this->checkbox($this->apiForumConnect->ssl),
                'proxy'   => $this->checkbox($this->apiForumConnect->useProxy),
            ],

            'apiReportConnect' => [
                'options' => $this->apiReportConnect->getSelectOptions(),
                'custom'  => $this->apiReportConnect->getCustomUrl(),
                'ssl'     => $this->checkbox($this->apiReportConnect->ssl),
                'proxy'   => $this->checkbox($this->apiReportConnect->useProxy),
            ],

            'proxy' => $this->makeProxy(),

            'automation'      => Reflection::reflect($this->automation),
            'averageSeeds'    => Reflection::reflect($this->averageSeeds),
            'filterRules'     => Reflection::reflect($this->filterRules),
            'reportSend'      => Reflection::reflect($this->reportSend),
            'topicSearch'     => Reflection::reflect($this->topicSearch),
            'topicControl'    => Reflection::reflect($this->topicControl),
            'torrentDownload' => Reflection::reflect($this->torrentDownload),

            'subForums'      => $this->makeSubForums(),
            'torrentClients' => $this->makeTorrentClients(),

            'other' => $this->makeOther(),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function makeOther(): array
    {
        $params = Reflection::reflect($this->other);

        $params['loggerOptions'] = LoggerConstructor::getSelectOptions(
            optionFormat: Render::optionTemplate,
            level       : $this->other->logLevel
        );

        return $params;
    }

    /**
     * @return array<string, null|int|string>
     */
    private function makeProxy(): array
    {
        $proxy = $this->proxy;

        $options = [];
        foreach (ProxyType::cases() as $case) {
            $selected = $case === $this->proxy->type ? 'selected' : '';

            $options[] = sprintf(
                self::optionTemplate,
                strtolower($case->name),
                $selected,
                $case->name,
            );
        }

        return [
            'hostname' => $proxy->hostname,
            'port'     => $proxy->port,
            'username' => self::escape($proxy->credentials?->username),
            'password' => self::escape($proxy->credentials?->password),
            'options'  => implode('', $options),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function makeSubForums(): array
    {
        /** Параметры подраздела. */
        $datasetFormatForum = self::makeDatasetTemplate([
            'client',
            'label',
            'savepath',
            'subdirectory',
            'hide',
            'peers',
            'exclude',
        ]);

        $optionForums = $optionForumsDataset = '';
        foreach ($this->subForums->getNameSorted() as $subForum) {
            // Список подразделов в селекторе вкладки "Раздачи".
            $optionForums .= sprintf(
                self::optionTemplate,
                $subForum->id,
                '',
                self::escape($subForum->name),
            );

            // Параметры подраздела в настройках.
            $datasetForum = sprintf(
                $datasetFormatForum,
                $subForum->clientId,
                self::escape($subForum->label),
                self::escape($subForum->dataFolder),
                $subForum->subFolderType?->value,
                (int) $subForum->hideTopics,
                TopicControl::renderPeersLimit($subForum->controlPeers),
                (int) $subForum->reportExclude,
            );

            // TODO убрать html_entity_decode в следующей мажорной версии, когда все проскочат обновление БД.
            $optionForumsDataset .= sprintf(
                self::optionTemplate,
                $subForum->id,
                $datasetForum,
                html_entity_decode($subForum->name, ENT_QUOTES, 'UTF-8'),
            );
        }

        return [
            'datasetOptions' => $optionForumsDataset,
            'mainOptions'    => $optionForums,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function makeTorrentClients(): array
    {
        /** Параметры торрент-клиента. */
        $datasetFormatTorrentClient = self::makeDatasetTemplate([
            'comment',
            'type',
            'hostname',
            'port',
            'login',
            'password',
            'ssl',
            'peers',
            'exclude',
        ]);

        $optionDataset     = [];
        $excludeClientsIDs = [];

        $filterOptions   = [];
        $filterOptions[] = sprintf(
            self::optionTemplate,
            '0',
            '',
            'не выбран'
        );

        foreach ($this->torrentClients->getNameSorted() as $client) {
            $comment = self::escape($client->extra['comment'] ?? '');

            // Список клиентов для выбора в фильтрах.
            $filterOptions[] = sprintf(
                self::optionTemplate,
                $client->id,
                '',
                $comment,
            );

            // Список исключённых клиентов.
            if ($client->exclude) {
                $excludeClientsIDs[] = self::escape($client->tag);
            }

            $dataset = sprintf(
                $datasetFormatTorrentClient,
                $comment,
                $client->type->value,
                $client->host,
                $client->port,
                self::escape($client->credentials?->username),
                self::escape($client->credentials?->password),
                (int) $client->secure,
                TopicControl::renderPeersLimit($client->controlPeers),
                (int) $client->exclude,
            );

            $optionDataset[] = sprintf(
                self::itemTemplate,
                $client->id,
                $dataset,
                $comment,
            );
        }

        return [
            'filterOptions'   => implode('', $filterOptions),
            'datasetOptions'  => implode('', $optionDataset),
            'excludedClients' => implode(',', $excludeClientsIDs),
        ];
    }

    private function checkbox(bool $value): string
    {
        return $value ? 'checked' : '';
    }

    /**
     * @param string[] $keys список ключей для JS dataset
     *
     * @return string dataset строка вида "param-1=1 param-2=2 etc"
     */
    private static function makeDatasetTemplate(array $keys): string
    {
        return implode(' ', array_map(static fn($el) => "data-$el=\"%s\"", $keys));
    }

    /**
     * Экранируем значения для html.
     */
    private static function escape(null|int|string $value): string
    {
        if (empty($value)) {
            return '';
        }

        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}
