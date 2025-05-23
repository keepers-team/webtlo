<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\External\Construct;

use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Cookie\SetCookie;
use KeepersTeam\Webtlo\Config\Defaults;
use KeepersTeam\Webtlo\Config\ForumConnect;
use KeepersTeam\Webtlo\Config\ForumCredentials;
use KeepersTeam\Webtlo\Config\Proxy;
use KeepersTeam\Webtlo\External\ForumClient;
use KeepersTeam\Webtlo\External\Shared\RetryMiddleware;
use KeepersTeam\Webtlo\Settings;
use Psr\Log\LoggerInterface;

final class ForumConstructor
{
    use RetryMiddleware;

    private readonly CookieJar $cookieJar;

    public function __construct(
        private ForumCredentials         $auth,
        private readonly ForumConnect    $connect,
        private readonly LoggerInterface $logger,
        private readonly Settings        $settings,
        private readonly Proxy           $proxy,
    ) {
        $this->cookieJar = new CookieJar();
    }

    public function setForumCredentials(ForumCredentials $credentials): void
    {
        $this->auth = $credentials;
    }

    public function createRequestClient(): ForumClient
    {
        // Проверяем данные авторизации на форуме.
        $this->auth->validate();

        $client = $this->createGuzzleClient();

        return new ForumClient(
            client  : $client,
            cred    : $this->auth,
            connect : $this->connect,
            cookie  : $this->cookieJar,
            logger  : $this->logger,
            settings: $this->settings,
        );
    }

    /**
     * Создание HTTP-клиента.
     */
    private function createGuzzleClient(): Client
    {
        $clientHeaders = [
            'User-Agent' => Defaults::userAgent,
            'X-WebTLO'   => 'experimental',
        ];

        // Если есть сохраненный токен авторизации, пробуем использовать его.
        if ($this->auth->session !== null) {
            $cookie = SetCookie::fromString(cookie: $this->auth->session);
            if (empty($cookie->getDomain())) {
                $cookie->setDomain(domain: $this->connect->baseUrl);
            }

            $this->cookieJar->setCookie($cookie);
        }

        $baseUrl = sprintf(
            '%s://%s',
            $this->connect->ssl ? 'https' : 'http',
            $this->connect->baseUrl,
        );

        $proxyConfig = $this->connect->useProxy ? $this->proxy->getOptions() : [];

        $timeout = $this->connect->timeout;

        $clientProperties = [
            'base_uri'        => $baseUrl,
            'headers'         => $clientHeaders,
            'timeout'         => $timeout->request,
            'connect_timeout' => $timeout->connection,
            'cookies'         => $this->cookieJar,
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
