<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\External\Api;

use GuzzleHttp\Client;
use KeepersTeam\Webtlo\Config\Defaults;
use KeepersTeam\Webtlo\Config\Proxy;
use KeepersTeam\Webtlo\Config\Timeout;
use KeepersTeam\Webtlo\External\Shared\RetryMiddleware;
use Psr\Log\LoggerInterface;

trait StaticHelper
{
    use RetryMiddleware;

    public static function createApiClient(
        LoggerInterface $logger,
        string          $baseUrl,
        bool            $ssl,
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
            self::$apiVersion
        );

        $proxyConfig = null !== $proxy ? $proxy->getOptions() : [];

        $clientProperties = [
            'base_uri'           => $baseUrl,
            'headers'            => $clientHeaders,
            'timeout'            => $timeout->request,
            'connect_timeout'    => $timeout->connection,
            'allow_redirects'    => true,
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
        $logger->info('Подключение к API форума (ApiClient)', $log);

        return $client;
    }

    public static function apiClientFromLegacy(array $cfg, LoggerInterface $logger, Proxy $proxy): Client
    {
        $useProxy = (bool)$cfg['proxy_activate_api'];

        return self::createApiClient(
            $logger,
            (string)$cfg['api_base_url'],
            (bool)$cfg['api_ssl'],
            $useProxy ? $proxy : null,
            new Timeout((int)$cfg['api_timeout'], (int)$cfg['api_connect_timeout']),
        );
    }

    public static function getDefaultParams(array $cfg): array
    {
        return ['api_key' => $cfg['api_key']];
    }
}
