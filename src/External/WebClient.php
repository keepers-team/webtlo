<?php

namespace KeepersTeam\Webtlo\External;

use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\HandlerStack;
use GuzzleRetry\GuzzleRetryMiddleware;
use KeepersTeam\Webtlo\Config\Defaults;
use KeepersTeam\Webtlo\Config\Proxy;
use KeepersTeam\Webtlo\Config\Timeout;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;

trait WebClient
{
    private static function getProxyConfig(LoggerInterface $logger, ?Proxy $proxy): array
    {
        $options = [];
        if (null !== $proxy && $proxy->enabled) {
            $needsAuth = null !== $proxy->credentials;
            $curlOptions = [CURLOPT_PROXYTYPE => $proxy->type->value];
            $logger->info(
                'Used proxy',
                [
                    'hostname' => $proxy->hostname,
                    'port' => $proxy->port,
                    'type' => $proxy->type->name,
                    'authenticated' => $needsAuth,
                ]
            );
            if ($needsAuth) {
                $curlOptions[CURLOPT_PROXYUSERPWD] = sprintf(
                    "%s:%s",
                    $proxy->credentials->username,
                    $proxy->credentials->password
                );
            }

            $options['proxy'] = sprintf("%s:%d", $proxy->hostname, $proxy->port);
            $options['curl'] = $curlOptions;
        }
        return $options;
    }

    protected static function getClient(
        LoggerInterface $logger,
        string $baseURL,
        ?Proxy $proxy,
        Timeout $timeout,
        CookieJar $cookieJar,
    ): Client {
        $retryCallback = function (int $attemptNumber, float $delay, RequestInterface &$request, array &$options, ?ResponseInterface $response) use ($logger): void {
            $logger->warning(
                'Retrying request',
                [
                    'url' => $request->getUri()->__toString(),
                    'delay' => number_format($delay, 2),
                    'attempt' => $attemptNumber
                ]
            );
        };
        $retryOptions = [
            'max_retry_attempts' => 3,
            'retry_on_timeout' => true,
            'on_retry_callback' => $retryCallback,
        ];
        $clientHeaders = [
            'User-Agent' => Defaults::userAgent,
            'X-WebTLO' => 'experimental'
        ];

        $stack = HandlerStack::create();
        $stack->push(GuzzleRetryMiddleware::factory($retryOptions));
        $baseUrl = sprintf("https://%s", $baseURL);
        $proxyConfig = self::getProxyConfig($logger, $proxy);
        $client = new Client([
            ...$proxyConfig,
            'base_uri' => $baseUrl,
            'timeout' => $timeout->request,
            'connect_timeout' => $timeout->connection,
            'allow_redirects' => true,
            'headers' => $clientHeaders,
            'handler' => $stack,
            'cookies' => $cookieJar,
        ]);
        $logger->info('Created client', ['base' => $baseUrl]);
        return $client;
    }
}
