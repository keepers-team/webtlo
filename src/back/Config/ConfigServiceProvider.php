<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\Config;

use KeepersTeam\Webtlo\Enum\ControlPeerLimitPriority as PeerPriority;
use KeepersTeam\Webtlo\Helper;
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
            ForumCredentials::class,
            ApiCredentials::class,
            Proxy::class,
            ReportSend::class,
            TopicControl::class,
        ];

        return in_array($id, $services, true);
    }

    public function register(): void
    {
        $container = $this->getContainer();

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
    }

    /**
     * @throws ContainerExceptionInterface
     */
    private function getIni(): TIniFileEx
    {
        return $this->getContainer()->get(TIniFileEx::class);
    }
}
