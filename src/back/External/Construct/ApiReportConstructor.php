<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\External\Construct;

use GuzzleHttp\Client;
use KeepersTeam\Webtlo\Config\ApiCredentials;
use KeepersTeam\Webtlo\Config\ApiReportConnect;
use KeepersTeam\Webtlo\Config\Proxy;
use KeepersTeam\Webtlo\External\ApiReportClient;
use KeepersTeam\Webtlo\External\Shared\RetryMiddleware;
use Psr\Log\LoggerInterface;

final class ApiReportConstructor
{
    use RetryMiddleware;

    public function __construct(
        private readonly ApiCredentials   $auth,
        private readonly ApiReportConnect $connect,
        private readonly LoggerInterface  $logger,
        private readonly Proxy            $proxy,
    ) {}

    public function createRequestClient(): ApiReportClient
    {
        $client = $this->createGuzzleClient();

        return new ApiReportClient(
            client: $client,
            auth  : $this->auth,
            logger: $this->logger,
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

        $clientProperties = [
            'base_uri'        => $baseUrl,
            'headers'         => $clientHeaders,
            'timeout'         => $timeout->request,
            'connect_timeout' => $timeout->connection,
            'auth'            => [
                $this->auth->userId,
                $this->auth->apiKey,
            ],
            'allow_redirects' => true,
            // RetryMiddleware
            'handler'         => self::getDefaultHandler($this->logger),
            // Proxy options
            ...$proxyConfig,
        ];

        $client = new Client(config: $clientProperties);

        $log = ['base' => $baseUrl];
        if ($this->connect->useProxy) {
            $log['proxy'] = $this->proxy->log();
        }
        $this->logger->info('Подключение к API отчётов (ApiReportClient)', $log);

        return $client;
    }
}
