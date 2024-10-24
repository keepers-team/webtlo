<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\External\Forum;

use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Cookie\SetCookie;
use KeepersTeam\Webtlo\Config\Defaults;
use KeepersTeam\Webtlo\Config\ForumCredentials;
use KeepersTeam\Webtlo\Config\Proxy;
use KeepersTeam\Webtlo\Config\Timeout;
use KeepersTeam\Webtlo\External\ForumClient;
use KeepersTeam\Webtlo\External\Shared\RetryMiddleware;
use KeepersTeam\Webtlo\Settings;
use Psr\Log\LoggerInterface;

/**
 * Создание подключения из массива с настройками.
 */
trait LegacyHelper
{
    use RetryMiddleware;

    /**
     * @param Settings         $settings  Настройки
     * @param ForumCredentials $forumAuth Учетные данные форума
     * @param LoggerInterface  $logger    Интерфейс для записи журнала
     * @param Proxy            $proxy     Прокси
     */
    public static function createFromLegacy(
        Settings         $settings,
        ForumCredentials $forumAuth,
        LoggerInterface  $logger,
        Proxy            $proxy
    ): ForumClient {
        $cfg = $settings->populate();

        $useProxy = (bool) $cfg['proxy_activate_forum'];

        $cookieJar = new CookieJar();

        $client = self::createClient(
            logger     : $logger,
            forumDomain: (string) $cfg['forum_base_url'],
            ssl        : (bool) $cfg['forum_ssl'],
            forumAuth  : $forumAuth,
            cookieJar  : $cookieJar,
            proxy      : $useProxy ? $proxy : null,
            timeout    : new Timeout((int) $cfg['forum_timeout'], (int) $cfg['forum_connect_timeout']),
        );

        return new ForumClient(
            client  : $client,
            cred    : $forumAuth,
            cookie  : $cookieJar,
            logger  : $logger,
            settings: $settings,
        );
    }

    /**
     * Создание HTTP-клиента.
     *
     * @param LoggerInterface  $logger      Интерфейс для записи журнала.
     * @param string           $forumDomain Домен форума
     * @param bool             $ssl         Использовать SSL
     * @param ForumCredentials $forumAuth   Учетные данные форума
     * @param CookieJar        $cookieJar   CookieJar для управления cookies
     * @param ?Proxy           $proxy       Прокси
     * @param Timeout          $timeout     Таймауты
     */
    private static function createClient(
        LoggerInterface  $logger,
        string           $forumDomain,
        bool             $ssl,
        ForumCredentials $forumAuth,
        CookieJar        $cookieJar,
        ?Proxy           $proxy,
        Timeout          $timeout,
    ): Client {
        $clientHeaders = [
            'User-Agent' => Defaults::userAgent,
            'X-WebTLO'   => 'experimental',
        ];

        // Если есть сохраненный токен авторизации, пробуем использовать его.
        if (null !== $forumAuth->session) {
            $cookie = SetCookie::fromString($forumAuth->session);
            if (empty($cookie->getDomain())) {
                $cookie->setDomain($forumDomain);
            }

            $cookieJar->setCookie($cookie);
        }

        $baseUrl = sprintf(
            '%s://%s',
            $ssl ? 'https' : 'http',
            $forumDomain,
        );

        $proxyConfig = null !== $proxy ? $proxy->getOptions() : [];

        $clientProperties = [
            'base_uri'        => $baseUrl,
            'headers'         => $clientHeaders,
            'timeout'         => $timeout->request,
            'connect_timeout' => $timeout->connection,
            'cookies'         => $cookieJar,
            'allow_redirects' => true,
            // RetryMiddleware
            'handler'         => self::getDefaultHandler($logger),
            // Proxy options
            ...$proxyConfig,
        ];

        $client = new Client($clientProperties);

        $log = ['base' => $forumDomain];
        if (null !== $proxy) {
            $log['proxy'] = $proxy->log();
        }
        $logger->info('Подключение к Форуму (ForumClient)', $log);

        return $client;
    }
}
