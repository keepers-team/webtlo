<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\External\Forum;

/**
 * Авторизация на форуме и проверка наличия доступа.
 */
trait Authentication
{
    /**
     * Проверка доступности форума.
     *
     * @return bool статус доступности форума
     */
    public function checkConnection(): bool
    {
        if ($this->cookie->count() > 0) {
            $this->logger->debug('Detected non-empty cookie jar, trying stored session first');
            $isLoggedIn = $this->isLoggedIn();

            if (!$isLoggedIn) {
                $this->logger->debug('Looks like cookies are rotten, logging in');
                $loggedIn = $this->autoLogin();
            } else {
                $this->logger->debug('Cookies are still fresh');
                $loggedIn = true;
            }
        } else {
            $this->logger->debug('No cookies found, proceeding with fresh login');
            $loggedIn = $this->autoLogin();
        }

        if (!$loggedIn) {
            $this->logger->error('Не удалось авторизоваться на форуме. Пройдите авторизацию в настройках.');

            return false;
        }

        return true;
    }

    /**
     * Авторизация по кнопке из настроек.
     *
     * @param ?array<string, string> $captcha Заполненные коды CAPTCHA
     *
     * @return ?Captcha Объект CAPTCHA или null в случае успеха авторизации
     */
    public function manualLogin(?array $captcha): ?Captcha
    {
        $page = $this->fetchLoginPage(captcha: $captcha);

        if (!empty($page)) {
            // После авторизации, пробуем получить токен.
            $token = self::parseFormToken(page: $page);

            // Если токена нет, значит что-то не так.
            if (null === $token) {
                return self::parseCaptchaCodes(authPage: $page, logger: $this->logger);
            }

            // Проверяем новые куки.
            $this->parseCookie();
        }

        return null;
    }

    /**
     * Получение нового cookie авторизации.
     *
     * @return string Новый cookie авторизации или пустая строка
     */
    public function getUpdatedCookie(): string
    {
        return $this->updatedCookie ?? '';
    }

    /**
     * Проверка, авторизован ли пользователь на форуме.
     *
     * @return bool Статус авторизации пользователя
     */
    private function isLoggedIn(): bool
    {
        // Проверяем доступность папки входящих сообщений.
        $page = $this->get(
            url: self::inboxURL,
            params: [
                'allow_redirects' => false, // 302 only in case of user logged out.
                'query'           => ['folder' => 'inbox'],
            ]
        );

        // Если пустой ответ, значит нужно авторизоваться.
        if (empty($page)) {
            return false;
        }

        // Если доступ есть, получаем токен.
        self::parseFormToken(page: $page);

        return true;
    }

    /**
     * Автоматическая авторизация на форуме, используя существующие ключи.
     *
     * @return bool статус авторизации
     */
    private function autoLogin(): bool
    {
        $page = $this->fetchLoginPage();
        if (!empty($page)) {
            // После авторизации, пробуем получить токен.
            $token = self::parseFormToken(page: $page);
            if (null === $token) {
                return false;
            }

            // Проверяем новые куки.
            $this->parseCookie();

            return true;
        }

        return false;
    }

    /**
     * Получение страницы авторизации.
     *
     * @param ?array<string, string> $captcha Заполненные коды CAPTCHA
     *
     * @return ?string HTML-страница авторизации или null в случае ошибки
     */
    private function fetchLoginPage(?array $captcha = null): ?string
    {
        // Очищаем куки авторизации, если есть
        $this->cookie->clear(name: self::$authCookieName);

        $form = [
            'login_username' => mb_convert_encoding($this->cred->auth->username, 'Windows-1251', 'UTF-8'),
            'login_password' => mb_convert_encoding($this->cred->auth->password, 'Windows-1251', 'UTF-8'),
            'login'          => mb_convert_encoding(self::loginAction, 'Windows-1251', 'UTF-8'),
        ];
        if (null !== $captcha) {
            $form += $captcha;
        }

        return $this->post(url: self::loginURL, params: ['form_params' => $form]);
    }

    /**
     * Получение cookie после авторизации.
     */
    private function parseCookie(): void
    {
        $cookies = $this->cookie;
        if ($cookies->count() > 0) {
            $cookie = $cookies->getCookieByName(name: self::$authCookieName);

            $this->updatedCookie = (string) $cookie;
        }
    }
}
