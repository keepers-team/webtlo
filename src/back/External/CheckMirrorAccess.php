<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\External;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\GuzzleException;
use KeepersTeam\Webtlo\Config\Defaults;
use KeepersTeam\Webtlo\Config\Proxy;
use KeepersTeam\Webtlo\Config\Timeout;
use KeepersTeam\Webtlo\External\Shared\RetryMiddleware;
use Psr\Log\LoggerInterface;
use RuntimeException;

final class CheckMirrorAccess
{
    use RetryMiddleware;

    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    public function checkAddress(string $type, string $url, bool $ssl, ?Proxy $proxy): bool
    {
        $timeout     = new Timeout(10, 10);
        $proxyConfig = null !== $proxy ? $proxy->getOptions() : [];

        $clientHeaders = [
            'User-Agent' => Defaults::userAgent,
            'X-WebTLO'   => 'experimental',
        ];

        $baseUrl = sprintf(
            '%s://%s/',
            $ssl ? 'https' : 'http',
            basename($url)
        );

        $path = $this->getPath($type);
        $log  = [
            'type'  => $type,
            'proxy' => (bool)$proxy,
        ];

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

        $client = new Client($clientProperties);

        try {
            $this->logger->debug('Проверяем доступность адреса', $log);

            $response = $client->get($path, ['max_retry_attempts' => 2]);
            $result   = $response->getBody()->getContents();

            $this->logger->debug('Ответ получен', $log);

            return !empty($result);
        } catch (ClientException $e) {
            $statusCode = $e->getCode();
            if ($statusCode === 401) {
                $this->logger->debug('Ответ получен', $log);

                return true;
            }
            $this->logger->warning($e->getMessage());

            return false;
        } catch (GuzzleException $e) {
            $this->logger->warning($e->getMessage());

            return false;
        }
    }

    private function getPath(string $type): string
    {
        return match ($type) {
            'forum'  => 'myip',
            'api'    => 'v1/get_client_ip',
            'report' => 'krs/api/v1/info/statuses',
            default => throw new RuntimeException("Unknown type: $type"),
        };
    }
}
