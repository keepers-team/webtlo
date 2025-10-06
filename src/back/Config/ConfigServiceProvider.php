<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\Config;

use KeepersTeam\Webtlo\Clients\ClientType;
use KeepersTeam\Webtlo\Enum\ControlPeerLimitPriority as PeerPriority;
use KeepersTeam\Webtlo\Helper;
use KeepersTeam\Webtlo\Module\TelemetryConstruct;
use KeepersTeam\Webtlo\TIniFileEx;
use League\Container\ServiceProvider\AbstractServiceProvider;
use Psr\Container\ContainerExceptionInterface;

/**
 * Предоставляет настроенные DTO классы конфига.
 */
final class ConfigServiceProvider extends AbstractServiceProvider
{
    public function provides(string $id): bool
    {
        $services = [
            ForumConnect::class,
            ForumCredentials::class,
            ApiForumConnect::class,
            ApiReportConnect::class,
            ApiCredentials::class,
            Proxy::class,
            Automation::class,
            Other::class,
            AverageSeeds::class,
            ReportSend::class,
            TopicControl::class,
            TopicSearch::class,
            SubForums::class,
            TorrentClients::class,
            TorrentDownload::class,
            Telemetry::class,
            UserInfo::class,
        ];

        return in_array($id, $services, true);
    }

    public function register(): void
    {
        $container = $this->getContainer();

        // Данные текущего пользователя.
        $container->addShared(UserInfo::class, function() {
            $ini = $this->getIni();

            return new UserInfo(
                userId     : (int) $ini->read('torrent-tracker', 'user_id'),
                userName   : (string) $ini->read('torrent-tracker', 'login'),
                excludeSelf: (bool) $ini->read('sections', 'exclude_self_keep', 1),
            );
        });

        // Параметры подключения к форуму.
        $container->addShared(ForumConnect::class, function() {
            $ini = $this->getIni();

            $url       = basename((string) $ini->read('torrent-tracker', 'forum_url', Defaults::forumUrl));
            $urlCustom = basename((string) $ini->read('torrent-tracker', 'forum_url_custom'));

            $url = $url === 'custom' ? $urlCustom : $url;

            $ssl      = (bool) $ini->read('torrent-tracker', 'forum_ssl', 1);
            $useProxy = (bool) $ini->read('proxy', 'activate_forum', 1);

            $timeout = new Timeout(
                request   : (int) $ini->read('curl_setopt', 'forum_timeout', Defaults::timeout),
                connection: (int) $ini->read('curl_setopt', 'forum_connecttimeout', Defaults::timeout),
            );

            return new ForumConnect(
                baseUrl : $url,
                ssl     : $ssl,
                useProxy: $useProxy,
                timeout : $timeout,
            );
        });

        // Авторизация на форуме.
        $container->addShared(ForumCredentials::class, function() {
            $ini = $this->getIni();

            $tracker_login = (string) $ini->read('torrent-tracker', 'login');
            $tracker_paswd = (string) $ini->read('torrent-tracker', 'password');
            $user_session  = (string) $ini->read('torrent-tracker', 'user_session');

            return new ForumCredentials(
                auth   : new BasicAuth(username: $tracker_login, password: $tracker_paswd),
                session: $user_session ?: null,
            );
        });

        // Параметры подключения к API форума.
        $container->addShared(ApiForumConnect::class, function() {
            $ini = $this->getIni();

            $section = 'torrent-tracker';

            $url       = basename((string) $ini->read($section, 'api_url', Defaults::apiForumUrl));
            $urlCustom = basename((string) $ini->read($section, 'api_url_custom'));

            $url = $url === 'custom' ? $urlCustom : $url;

            $ssl      = (bool) $ini->read($section, 'api_ssl', 1);
            $useProxy = (bool) $ini->read('proxy', 'activate_api', 0);

            $timeout = new Timeout(
                request   : (int) $ini->read('curl_setopt', 'api_timeout', Defaults::timeout),
                connection: (int) $ini->read('curl_setopt', 'api_connecttimeout', Defaults::timeout),
            );

            $concurrency = (int) $ini->read($section, 'api_concurrency', ApiForumConnect::concurrency);
            $rateSize    = (int) $ini->read($section, 'api_rate_frame_size', ApiForumConnect::rateFrameSize);
            $rateLimit   = (int) $ini->read($section, 'api_rate_request_limit', ApiForumConnect::rateRequestLimit);

            return new ApiForumConnect(
                baseUrl         : $url,
                ssl             : $ssl,
                useProxy        : $useProxy,
                timeout         : $timeout,
                concurrency     : $concurrency,
                rateFrameSize   : $rateSize,
                rateRequestLimit: $rateLimit,
            );
        });

        // Параметры подключения к API отчётов.
        $container->addShared(ApiReportConnect::class, function() {
            $ini = $this->getIni();

            $url       = basename((string) $ini->read('torrent-tracker', 'report_url', Defaults::apiReportUrl));
            $urlCustom = basename((string) $ini->read('torrent-tracker', 'report_url_custom'));

            $url = $url === 'custom' ? $urlCustom : $url;

            $ssl      = (bool) $ini->read('torrent-tracker', 'report_ssl', 1);
            $useProxy = (bool) $ini->read('proxy', 'activate_report', 0);

            $timeout = new Timeout(
                request   : (int) $ini->read('curl_setopt', 'report_timeout', Defaults::timeout),
                connection: (int) $ini->read('curl_setopt', 'report_connecttimeout', Defaults::timeout),
            );

            return new ApiReportConnect(
                baseUrl : $url,
                ssl     : $ssl,
                useProxy: $useProxy,
                timeout : $timeout,
            );
        });

        // Авторизация в API.
        $container->addShared(ApiCredentials::class, function() {
            $ini = $this->getIni();

            return new ApiCredentials(
                userId: (int) $ini->read('torrent-tracker', 'user_id'),
                btKey : (string) $ini->read('torrent-tracker', 'bt_key'),
                apiKey: (string) $ini->read('torrent-tracker', 'api_key'),
            );
        });

        // Настройки прокси.
        $container->addShared(Proxy::class, function() {
            $ini = $this->getIni();

            $proxy = [
                'proxy_type'     => (string) $ini->read('proxy', 'type', Defaults::proxyType->name),
                'proxy_hostname' => (string) $ini->read('proxy', 'hostname', Defaults::proxyUrl),
                'proxy_port'     => (int) $ini->read('proxy', 'port', Defaults::proxyPort),
                'proxy_login'    => (string) $ini->read('proxy', 'login'),
                'proxy_paswd'    => (string) $ini->read('proxy', 'password'),
            ];

            return Proxy::fromLegacy($proxy);
        });

        // Опции набора истории средних сидов.
        $container->addShared(AverageSeeds::class, function() {
            $ini = $this->getIni();

            return new AverageSeeds(
                enableHistory    : (bool) $ini->read('sections', 'avg_seeders', 1),
                historyDays      : (int) $ini->read('sections', 'avg_seeders_period', 14),
                historyExpiryDays: (int) $ini->read('sections', 'avg_seeders_period_outdated', 7),
            );
        });

        // Опции получения и отправки отчётов.
        $container->addShared(ReportSend::class, function() {
            $ini = $this->getIni();

            return new ReportSend(
                sendReports        : (bool) $ini->read('reports', 'send_report_api', 1),
                sendSummary        : (bool) $ini->read('reports', 'send_summary_report', 1),
                sendTelemetry      : (bool) $ini->read('reports', 'send_report_settings', 1),
                excludeAuthored    : (bool) $ini->read('reports', 'exclude_authored', 0),
                unsetOtherTopics   : (bool) $ini->read('reports', 'unset_other_topics', 1),
                unsetOtherSubForums: (bool) $ini->read('reports', 'unset_other_forums', 1),
                daysUpdateExpire   : (int) $ini->read('reports', 'days_update_expire', 5),
                excludedSubForums  : Helper::explodeInt((string) $ini->read('reports', 'exclude_forums_ids')),
                excludedClients    : Helper::explodeInt((string) $ini->read('reports', 'exclude_clients_ids')),
                excludedKeepers    : Helper::explodeInt((string) $ini->read('reports', 'exclude_keepers_ids')),
            );
        });

        // Опции регулировки раздач.
        $container->addShared(TopicControl::class, function() {
            $ini = $this->getIni();

            return new TopicControl(
                peersLimit            : (int) $ini->read('topics_control', 'peers', 10),
                peersLimitIntervals   : (string) $ini->read('topics_control', 'intervals'),
                excludedKeepersCount  : (int) $ini->read('topics_control', 'keepers', 3),
                randomApplyCount      : (int) $ini->read('topics_control', 'random', 1),
                peerLimitPriority     : PeerPriority::from((int) $ini->read('topics_control', 'priority', 1)),
                countLeechersAsPeers  : (bool) $ini->read('topics_control', 'leechers', 0),
                seedingWithoutLeechers: (bool) $ini->read('topics_control', 'no_leechers', 1),
                manageOtherSubsections: (bool) $ini->read('topics_control', 'unadded_subsections', 0),
                daysUntilUnseeded     : (int) $ini->read('topics_control', 'days_until_unseeded', 21),
                maxUnseededCount      : (int) $ini->read('topics_control', 'max_unseeded_count', 100),
            );
        });

        // Опции расширенного поиска раздач.
        $container->addShared(TopicSearch::class, function() {
            $ini = $this->getIni();

            return new TopicSearch(
                untracked   : (bool) $ini->read('update', 'untracked', 1),
                unregistered: (bool) $ini->read('update', 'unregistered', 1),
            );
        });

        // Хранимые подразделы.
        $container->add(SubForums::class, function() {
            $ini = $this->getIni();

            $subsections = $ini->read('sections', 'subsections');

            $list = [];
            if (!empty($subsections)) {
                $subsections = array_filter(explode(',', $subsections));
                sort($subsections);

                foreach ($subsections as $section) {
                    $subForumId = (int) $section;

                    $list[$subForumId] = new SubForum(
                        id           : $subForumId,
                        name         : (string) $ini->read($section, 'title', $section),
                        clientId     : (int) $ini->read($section, 'client', 0),
                        label        : (string) $ini->read($section, 'label'),
                        dataFolder   : (string) $ini->read($section, 'data-folder'),
                        subFolderType: SubFolderType::tryFrom((int) $ini->read($section, 'data-sub-folder')),
                        hideTopics   : (bool) $ini->read($section, 'hide-topics'),
                        reportExclude: (bool) $ini->read($section, 'exclude'),
                        controlPeers : (int) ($ini->read($section, 'control-peers') ?: -2)
                    );
                }
            }

            return new SubForums(ids: array_keys($list), params: $list);
        });

        // Используемые торрент-клиенты и их параметры подключения.
        $container->addShared(TorrentClients::class, function() {
            $ini = $this->getIni();

            $clients = [];

            $clientMaxId = (int) $ini->read('other', 'qt', 0);
            for ($i = 1; $i <= $clientMaxId; ++$i) {
                $section = "torrent-client-$i";

                // Ид торрент-клиента.
                $clientId   = (int) $ini->read($section, 'id', $i);
                $clientType = ClientType::from(
                    (string) $ini->read($section, 'client', 'utorrent')
                );

                $clientHost = (string) $ini->read($section, 'hostname');
                $clientPort = (int) $ini->read($section, 'port');
                $ssl        = (bool) $ini->read($section, 'ssl', 0);

                // Если не указан порт подключения к клиенту, добавляем порт по умолчанию.
                if (empty($clientPort)) {
                    $clientPort = $ssl ? 443 : 80;
                }

                // Авторизация в торрент-клиенте.
                $login    = (string) $ini->read($section, 'login');
                $password = (string) $ini->read($section, 'password');

                $credentials = null;
                if ($login !== '' && $password !== '') {
                    $credentials = new BasicAuth(username: $login, password: $password);
                }

                $timeout = new Timeout(
                    request   : (int) $ini->read($section, 'request_timeout', Defaults::timeout),
                    connection: (int) $ini->read($section, 'connect_timeout', Defaults::timeout),
                );

                $comment = (string) $ini->read($section, 'comment');

                /**
                 * Регулировка раздач в торрент-клиенте.
                 *
                 * - -1 - регулировка подраздела отключена;
                 * - -2 == пустая строка - пустое значение ~= не учитывать значение.
                 */
                $controlPeers = (int) ($ini->read($section, 'control_peers') ?: -2);

                // Исключение раздач торрент-клиента при отправке отчётов.
                $exclude = (bool) $ini->read($section, 'exclude', 0);

                $clients[$clientId] = new TorrentClientOptions(
                    id          : $clientId,
                    type        : $clientType,
                    name        : $comment,
                    host        : $clientHost,
                    port        : $clientPort,
                    secure      : $ssl,
                    credentials : $credentials,
                    timeout     : $timeout,
                    exclude     : $exclude,
                    controlPeers: $controlPeers,
                    extra       : [
                        'id'      => $clientId,
                        'comment' => $comment,
                    ],
                );
            }

            return new TorrentClients(clients: $clients);
        });

        // Параметры автоматического запуска задач по-расписанию.
        $container->addShared(Automation::class, function() {
            $ini = $this->getIni();

            return new Automation(
                update : (bool) $ini->read('automation', 'update', 1),
                control: (bool) $ini->read('automation', 'control', 0),
                reports: (bool) $ini->read('automation', 'reports', 0),
            );
        });

        // Прочие параметры.
        $container->addShared(Other::class, function() {
            $ini = $this->getIni();

            return new Other(
                logLevel: (string) $ini->read('other', 'log_level', 'Info'),
            );
        });

        // Параметры загрузки торрент-файлов.
        $container->addShared(TorrentDownload::class, function() {
            $ini = $this->getIni();

            return new TorrentDownload(
                folder        : (string) $ini->read('download', 'savedir', Defaults::downloadPath),
                subFolder     : (bool) $ini->read('download', 'savesubdir', 0),
                addRetracker  : (bool) $ini->read('download', 'retracker', 0),
                folderReplace : (string) $ini->read('curators', 'dir_torrents', Defaults::downloadPath),
                replacePassKey: (string) $ini->read('curators', 'user_passkey'),
                forRegularUser: (bool) $ini->read('curators', 'tor_for_user', 0)
            );
        });

        // Телеметрия - публичные данные об используемом ПО.
        $container->addShared(Telemetry::class, function() use ($container) {
            /** @var ReportSend $report */
            $report = $container->get(ReportSend::class);

            $info = [];
            if ($report->sendTelemetry) {
                /** @var TelemetryConstruct $constructor */
                $constructor = $container->get(TelemetryConstruct::class);

                $info = $constructor->getInfo();
            }

            return new Telemetry(info: $info);
        });
    }

    /**
     * @throws ContainerExceptionInterface
     */
    private function getIni(): TIniFileEx
    {
        return $this->getContainer()->get(TIniFileEx::class);
    }
}
