<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\External\Construct;

use GuzzleHttp\Client;
use KeepersTeam\Webtlo\Config\ApiCredentials;
use KeepersTeam\Webtlo\Config\ApiForumConnect;
use KeepersTeam\Webtlo\Config\Proxy;
use KeepersTeam\Webtlo\External\ApiForumClient;
use KeepersTeam\Webtlo\External\Shared\RateLimiterMiddleware;
use KeepersTeam\Webtlo\External\Shared\RetryMiddleware;
use Psr\Log\LoggerInterface;

final class ApiForumConstructor
{
    use RetryMiddleware;

    public function __construct(
        private readonly ApiCredentials  $auth,
        private readonly ApiForumConnect $connect,
        private readonly LoggerInterface $logger,
        private readonly Proxy           $proxy,
    ) {}

    public function createRequestClient(): ApiForumClient
    {
        $client = $this->createGuzzleClient();

        return new ApiForumClient(
            client : $client,
            auth   : $this->auth,
            connect: $this->connect,
            logger : $this->logger,
        );
    }

    /**
     * Создание HTTP-клиента.
     */
    private function createGuzzleClient(): Client
    {
        $clientHeaders = [
            'User-Agent' => $this->connect->userAgent,
            'X-WebTLO'   => 'experimental',
        ];

        $baseUrl     = $this->connect->getApiUrl();
        $proxyConfig = $this->connect->useProxy ? $this->proxy->getOptions() : [];
        $timeout     = $this->connect->timeout;

        // RetryMiddleware
        $handlerStack = self::getDefaultHandler($this->logger);
        // RateLimiterMiddleware
        $handlerStack->push(
            middleware: new RateLimiterMiddleware(
                frameSize   : $this->connect->rateFrameSize,
                requestLimit: $this->connect->rateRequestLimit,
                logger      : $this->logger
            )
        );

        $clientProperties = [
            'base_uri'        => $baseUrl,
            'headers'         => $clientHeaders,
            'timeout'         => $timeout->request,
            'connect_timeout' => $timeout->connection,
            'allow_redirects' => true,
            'handler'         => $handlerStack,
            // Proxy options
            ...$proxyConfig,
        ];

        $client = new Client(config: $clientProperties);

        $log = ['base' => $baseUrl];
        if ($this->connect->useProxy) {
            $log['proxy'] = $this->proxy->log();
        }
        $this->logger->info('Подключение к API форума (ApiClient)', $log);

        return $client;
    }
}
