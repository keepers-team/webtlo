<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\External\Forum;

use GuzzleHttp\Cookie\CookieJar;
use KeepersTeam\Webtlo\Config\ApiCredentials;
use Psr\Log\LoggerInterface;

/**
 * Получение ключей для авторизации в API.
 */
trait GetCredentials
{
    use DomHelper;

    /**
     * Получение API ключей.
     *
     * @return ?ApiCredentials объект API ключей или null в случае ошибки
     */
    public function getApiCredentials(): ?ApiCredentials
    {
        $userId = self::parseUserId(cookieJar: $this->cookie, logger: $this->logger);
        if (null === $userId) {
            return null;
        }

        $profilePage = $this->getProfile(userId: $userId);
        if (null === $profilePage) {
            return null;
        }

        return self::parseApiCredentials(profilePage: $profilePage);
    }

    /**
     * Получение идентификатора пользователя из cookie.
     *
     * @param CookieJar       $cookieJar Объект cookie
     * @param LoggerInterface $logger    Интерфейс для записи журнала
     *
     * @return ?int Идентификатор пользователя или null в случае ошибки
     */
    private static function parseUserId(CookieJar $cookieJar, LoggerInterface $logger): ?int
    {
        $userCookie = $cookieJar->getCookieByName(name: self::$authCookieName);
        if (null === $userCookie) {
            $logger->error('No user cookie found');

            return null;
        }

        $rawValue = $userCookie->getValue();
        if (null === $rawValue) {
            $logger->error('Empty user cookie');

            return null;
        }

        $matches = [];
        preg_match('|[^-]*-([0-9]*)-.*|', $rawValue, $matches);
        if (count($matches) !== 2 || false === filter_var($matches[1], FILTER_SANITIZE_NUMBER_INT)) {
            $logger->error('Malformed cookie', $userCookie->toArray());

            return null;
        }

        return (int) $matches[1];
    }

    /**
     * Получение страницы профиля пользователя.
     *
     * @param int $userId идентификатор пользователя
     *
     * @return ?string HTML-страница профиля или null в случае ошибки
     */
    private function getProfile(int $userId): ?string
    {
        $query = ['u' => $userId, 'mode' => self::profileAction];

        return $this->post(url: self::profileURL, params: ['query' => $query]);
    }

    /**
     * Получение API ключей со страницы профиля.
     *
     * @param string $profilePage HTML-страница профиля
     *
     * @return ?ApiCredentials Объект API ключей или null в случае ошибки
     */
    private static function parseApiCredentials(string $profilePage): ?ApiCredentials
    {
        $dom = self::parseDOM(page: $profilePage);

        $xpathQuery = (
            '//table[contains(@class, "user_details")]' .
            '//th[text()="Хранительские ключи:"]' .
            '/following-sibling::td[@class="med"]/b/text()'
        );

        $nodes = $dom->query(expression: $xpathQuery);
        if (!empty($nodes) && count($nodes) === 3) {
            return new ApiCredentials(
                userId: (int) $nodes->item(2)?->nodeValue,
                btKey : (string) $nodes->item(0)?->nodeValue,
                apiKey: (string) $nodes->item(1)?->nodeValue,
            );
        }

        return null;
    }
}
