<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\External\Construct;

use GuzzleHttp\Client;
use KeepersTeam\Webtlo\Config\ApiCredentials;
use KeepersTeam\Webtlo\Config\Defaults;
use KeepersTeam\Webtlo\Config\ForumConnect;
use KeepersTeam\Webtlo\Config\Proxy;
use KeepersTeam\Webtlo\External\ForumClient;
use KeepersTeam\Webtlo\External\Shared\RetryMiddleware;
use KeepersTeam\Webtlo\WebTLO;
use Psr\Log\LoggerInterface;

final class ForumConstructor
{
    use RetryMiddleware;

    public function __construct(
        private readonly ApiCredentials  $auth,
        private readonly ForumConnect    $connect,
        private readonly LoggerInterface $logger,
        private readonly Proxy           $proxy,
        private readonly WebTLO          $webtlo,
    ) {}

    public function createRequestClient(): ForumClient
    {
        $client = $this->createGuzzleClient();

        return new ForumClient(
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
            'User-Agent' => Defaults::userAgent,
            'X-WebTLO'   => $this->webtlo->getSemanticVersion(),
        ];

        $baseUrl = $this->connect->url;

        $proxyConfig = $this->connect->useProxy ? $this->proxy->getOptions() : [];

        $timeout = $this->connect->timeout;

        $clientProperties = [
            'base_uri'        => $baseUrl,
            'headers'         => $clientHeaders,
            'timeout'         => $timeout->request,
            'connect_timeout' => $timeout->connection,
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
        $this->logger->info('Подключение к Форуму (ForumClient)', $log);

        return $client;
    }
}
