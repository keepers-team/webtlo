<?php

namespace KeepersTeam\Webtlo\Forum;

use GuzzleHttp\Cookie\SetCookie;
use GuzzleHttp\Exception\GuzzleException;
use KeepersTeam\Webtlo\Config\Defaults;
use KeepersTeam\Webtlo\Config\Proxy;
use KeepersTeam\Webtlo\Config\Timeout;
use Psr\Log\LoggerInterface;

class ForumClient extends WebClient
{
    private const loginAction = 'вход';
    private const authCookieName = 'bb_session';
    private const loginURL = '/forum/login.php';

    public function __construct(
        private readonly string $username,
        private readonly string $password,
        LoggerInterface $logger,
        ?Proxy $proxy = null,
        string $forumURL = Defaults::forumUrl,
        Timeout $timeout = new Timeout(),
    ) {
        parent::__construct(
            logger: $logger,
            proxy: $proxy,
            forumURL: $forumURL,
            timeout: $timeout
        );
    }

    private function parseUserId(SetCookie $cookie): ?int
    {
        $rawID = $cookie->getValue();
        if (null === $rawID) {
            return null;
        }
        $matches = [];
        preg_match("|[^-]*-([0-9]*)-.*|", $rawID, $matches);
        if (count($matches) !== 2 || false === filter_var($matches[1], FILTER_SANITIZE_NUMBER_INT)) {
            return null;
        }
        return (int)$matches[1];
    }

    /**
     * Log in user to forum
     *
     * @return int|null User ID
     */
    public function login(): ?int
    {
        $options = [
            'form_params' => [
                'login_username' => mb_convert_encoding($this->username, 'Windows-1251', 'UTF-8'),
                'login_password' => mb_convert_encoding($this->password, 'Windows-1251', 'UTF-8'),
                'login' => mb_convert_encoding(self::loginAction, 'Windows-1251', 'UTF-8'),
            ]
        ];
        try {
            $this->logger->info('Logging in', ['username' => $this->username]);
            $response = $this->client->post(self::loginURL, $options);
        } catch (GuzzleException $e) {
            $this->logger->error('Failed to make login request', ['username' => $this->username, 'error' => $e]);
            return null;
        }

        if ($this->isValidMime($response, self::webMime)) {
            $userCookie = $this->cookieJar->getCookieByName(self::authCookieName);
            if (null === $userCookie) {
                $this->logger->error('Authentication error', ['username' => $this->username]);
                return null;
            }
            $userId = $this->parseUserId($userCookie);
            if (null === $userId) {
                $this->logger->error('Unable to extract user identifier from cookie');
                return null;
            }
            $this->logger->info('Successfully logged in', ['id' => $userId, 'username' => $this->username]);
            return $userId;
        } else {
            return null;
        }
    }
}
