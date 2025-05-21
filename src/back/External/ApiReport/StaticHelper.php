<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\External\ApiReport;

use GuzzleHttp\Client;
use KeepersTeam\Webtlo\Config\ApiCredentials;
use KeepersTeam\Webtlo\Config\Defaults;
use KeepersTeam\Webtlo\Config\Proxy;
use KeepersTeam\Webtlo\Config\Timeout;
use KeepersTeam\Webtlo\External\Shared\RetryMiddleware;
use Psr\Log\LoggerInterface;

trait StaticHelper
{
    use RetryMiddleware;

    public static function createApiReportClient(
        LoggerInterface $logger,
        string          $baseUrl,
        bool            $ssl,
        ApiCredentials  $auth,
        ?Proxy          $proxy,
        Timeout         $timeout = new Timeout(),
    ): Client {
        $clientHeaders = [
            'User-Agent' => Defaults::userAgent,
            'X-WebTLO'   => 'experimental',
        ];

        $baseUrl = sprintf(
            '%s://%s/%s/',
            $ssl ? 'https' : 'http',
            $baseUrl,
            'krs/api/v1'
        );

        $proxyConfig = $proxy !== null ? $proxy->getOptions() : [];

        $clientProperties = [
            'base_uri'        => $baseUrl,
            'headers'         => $clientHeaders,
            'timeout'         => $timeout->request,
            'connect_timeout' => $timeout->connection,
            'auth'            => [
                $auth->userId,
                $auth->apiKey,
            ],
            'allow_redirects' => true,
            // RetryMiddleware
            'handler'         => self::getDefaultHandler($logger),
            // Proxy options
            ...$proxyConfig,
        ];

        $client = new Client($clientProperties);

        $log = ['base' => $baseUrl];
        if ($proxy !== null) {
            $log['proxy'] = $proxy->log();
        }
        $logger->info('Подключение к API отчётов (ApiReportClient)', $log);

        return $client;
    }

    /**
     * @param array<string, mixed> $cfg
     */
    public static function apiClientFromLegacy(
        array           $cfg,
        ApiCredentials  $auth,
        LoggerInterface $logger,
        Proxy           $proxy
    ): Client {
        $useProxy = (bool) $cfg['proxy_activate_report'];

        return self::createApiReportClient(
            logger : $logger,
            baseUrl: (string) $cfg['report_base_url'],
            ssl    : (bool) $cfg['report_ssl'],
            auth   : $auth,
            proxy  : $useProxy ? $proxy : null,
            timeout: new Timeout((int) $cfg['api_timeout'], (int) $cfg['api_connect_timeout']),
        );
    }
}
