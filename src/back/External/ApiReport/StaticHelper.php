<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\External\ApiReport;

use Exception;
use GuzzleHttp\Client;
use KeepersTeam\Webtlo\Config\Credentials;
use KeepersTeam\Webtlo\Config\Defaults;
use KeepersTeam\Webtlo\Config\Proxy;
use KeepersTeam\Webtlo\Config\Timeout;
use KeepersTeam\Webtlo\Config\Validate;
use KeepersTeam\Webtlo\External\Shared\RetryMiddleware;
use Psr\Log\LoggerInterface;

trait StaticHelper
{
    use RetryMiddleware;

    public static function createApiReportClient(
        LoggerInterface $logger,
        string          $baseUrl,
        bool            $ssl,
        Credentials     $cred,
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

        $proxyConfig = null !== $proxy ? $proxy->getOptions() : [];

        $clientProperties = [
            'base_uri'        => $baseUrl,
            'headers'         => $clientHeaders,
            'timeout'         => $timeout->request,
            'connect_timeout' => $timeout->connection,
            'auth'            => [
                $cred->userId,
                $cred->apiKey,
            ],
            'allow_redirects' => true,
            // RetryMiddleware
            'handler'         => self::getDefaultHandler($logger),
            // Proxy options
            ...$proxyConfig,
        ];

        $client = new Client($clientProperties);

        $log = ['base' => $baseUrl];
        if (null !== $proxy) {
            $log['proxy'] = $proxy->log();
        }
        $logger->info('Подключение к API отчётов (ApiReportClient)', $log);

        return $client;
    }

    /**
     * @throws Exception
     */
    public static function apiClientFromLegacy(
        array           $cfg,
        Credentials     $cred,
        LoggerInterface $logger,
        Proxy           $proxy
    ): Client {
        $useProxy = (bool)$cfg['proxy_activate_report'];

        return self::createApiReportClient(
            $logger,
            (string)$cfg['report_base_url'],
            (bool)$cfg['report_ssl'],
            $cred,
            $useProxy ? $proxy : null,
            new Timeout((int)$cfg['api_timeout'], (int)$cfg['api_connect_timeout']),
        );
    }

    /**
     * @throws Exception
     */
    public static function apiCredentials(array $cfg): Credentials
    {
        return Validate::checkUser($cfg);
    }
}
