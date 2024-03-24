<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\External;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use KeepersTeam\Webtlo\Config\Defaults;
use KeepersTeam\Webtlo\Config\Proxy;
use KeepersTeam\Webtlo\Config\Timeout;
use KeepersTeam\Webtlo\External\Shared\RetryMiddleware;
use Psr\Log\LoggerInterface;

final class CheckMirrorAccess
{
    use RetryMiddleware;

    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    public function checkAddress(string $url, ?Proxy $proxy): bool
    {
        $timeout     = new Timeout(5, 5);
        $proxyConfig = null !== $proxy ? $proxy->getOptions() : [];

        $clientHeaders = [
            'User-Agent' => Defaults::userAgent,
            'X-WebTLO'   => 'experimental',
        ];

        $clientProperties = [
            'headers'         => $clientHeaders,
            'timeout'         => $timeout->request,
            'connect_timeout' => $timeout->connection,
            'allow_redirects' => true,
            // RetryMiddleware
            'handler'         => self::getDefaultHandler($this->logger),
            // Proxy options
            ...$proxyConfig,
        ];

        $client = new Client($clientProperties);

        try {
            $response = $client->post($url);
            $result   = $response->getBody()->getContents();

            return !empty($result);
        } catch (GuzzleException) {
            return false;
        }
    }
}
