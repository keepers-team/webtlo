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
    public function searchApiCredentials(): ?ApiCredentials
    {
        $userId = self::parseUserId(cookieJar: $this->cookie, logger: $this->logger);
        if ($userId === null) {
            return null;
        }

        $this->logger->info('Авторизация выполнена успешно, найден userId: {userId}', ['userId' => $userId]);

        $profilePage = $this->requestProfilePage(userId: $userId);
        if ($profilePage === null) {
            return null;
        }

        $parsedKeys = self::parseApiCredentials(profilePage: $profilePage);
        if ($parsedKeys === null) {
            $this->logger->notice('Не удалось получить Хранительские ключи. Вы точно хранитель?');

            return new ApiCredentials(userId: $userId);
        }

        return $parsedKeys;
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
        if ($userCookie === null) {
            $logger->error('No user cookie found');

            return null;
        }

        $rawValue = $userCookie->getValue();
        if ($rawValue === null) {
            $logger->error('Empty user cookie');

            return null;
        }

        $matches = [];
        preg_match('|[^-]*-([0-9]*)-.*|', $rawValue, $matches);
        if (count($matches) !== 2 || filter_var($matches[1], FILTER_SANITIZE_NUMBER_INT) === false) {
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
    private function requestProfilePage(int $userId): ?string
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
