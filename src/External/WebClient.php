<?php

namespace KeepersTeam\Webtlo\External;

use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Header;
use GuzzleRetry\GuzzleRetryMiddleware;
use KeepersTeam\Webtlo\Config\Defaults;
use KeepersTeam\Webtlo\Config\Proxy;
use KeepersTeam\Webtlo\Config\Timeout;
use KeepersTeam\Webtlo\External\ProxySupport;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;

abstract class WebClient
{
    use ProxySupport;

    /**
     * @var array<string, string>
     */
    private const clientHeaders = [
        'User-Agent' => Defaults::userAgent,
        'X-WebTLO' => 'experimental'
    ];

    protected readonly Client $client;
    protected readonly CookieJar $cookieJar;

    public function __construct(
        protected readonly LoggerInterface $logger,
        string $baseURL,
        ?Proxy $proxy = null,
        Timeout $timeout = new Timeout(),
    ) {
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

        $stack = HandlerStack::create();
        $stack->push(GuzzleRetryMiddleware::factory($retryOptions));
        $baseUrl = sprintf("https://%s", $baseURL);
        $proxyConfig = static::getProxyConfig($this->logger, $proxy);
        $this->cookieJar = new CookieJar();
        $this->client = new Client([
            ...$proxyConfig,
            'base_uri' => $baseUrl,
            'timeout' => $timeout->request,
            'connect_timeout' => $timeout->connection,
            'allow_redirects' => true,
            'headers' => self::clientHeaders,
            'handler' => $stack,
            'cookies' => $this->cookieJar,
        ]);
        $logger->info('Created client', ['base' => $baseUrl]);
    }
}
