<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\External\Api;

use GuzzleHttp\Client;
use KeepersTeam\Webtlo\Config\Defaults;
use KeepersTeam\Webtlo\Config\Proxy;
use KeepersTeam\Webtlo\Config\Timeout;
use Psr\Http\Message\RequestInterface;
use Psr\Log\LoggerInterface;

trait StaticHelper
{
    public static function createApiClient(
        LoggerInterface $logger,
        string          $baseUrl,
        bool            $ssl,
        ?Proxy          $proxy,
        Timeout         $timeout = new Timeout(),
    ): Client {
        $retryCallback = function(
            int              $attemptNumber,
            float            $delay,
            RequestInterface $request,
        ) use ($logger): void {
            $logger->warning(
                'Retrying request',
                [
                    'url'     => $request->getUri()->__toString(),
                    'delay'   => number_format($delay, 2),
                    'attempt' => $attemptNumber,
                ]
            );
        };

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
            // Proxy options
            ...$proxyConfig,
            // Retry options
            'max_retry_attempts' => 3,
            'retry_on_timeout'   => true,
            'on_retry_callback'  => $retryCallback,
        ];

        $client = new Client($clientProperties);

        $logger->info('Created ApiClient', ['base' => $baseUrl]);
        if (null !== $proxy) {
            $logger->info('Used proxy', $proxy->log());
        }

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
            new Timeout($cfg['api_timeout'], $cfg['api_connect_timeout']),
        );
    }

    public static function getDefaultParams(array $cfg): array
    {
        return ['api_key' => $cfg['api_key']];
    }
}
