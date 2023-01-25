<?php

namespace KeepersTeam\Webtlo\External\Forum;

use DOMDocument;
use GuzzleHttp\Cookie\SetCookie;
use GuzzleHttp\Exception\GuzzleException;
use KeepersTeam\Webtlo\Config\ApiCredentials;
use KeepersTeam\Webtlo\Config\Defaults;
use KeepersTeam\Webtlo\Config\Proxy;
use KeepersTeam\Webtlo\Config\Timeout;
use KeepersTeam\Webtlo\External\Validation;
use KeepersTeam\Webtlo\External\WebClient;
use Psr\Log\LoggerInterface;

final class ForumClient extends WebClient
{
    use Validation;

    private const loginAction = 'вход';
    private const profileAction = 'viewprofile';
    private const authCookieName = 'bb_session';
    private const loginURL = '/forum/login.php';
    private const profileURL = '/forum/profile.php';

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
            baseURL: $forumURL,
            proxy: $proxy,
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

        if (self::isValidMime($this->logger, $response, self::$webMime)) {
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

    private function parseApiCredentials(string $page): ?ApiCredentials
    {
        libxml_use_internal_errors(use_errors: true);
        $result = null;
        $html = new DOMDocument();
        $html->loadHtml(source: $page);
        $dom = simplexml_import_dom($html);
        $nodes = $dom->xpath(expression: "//table[contains(@class, 'user_details')]/tr[9]/td/b/text()");
        if (count($nodes) === 3) {
            $result = new ApiCredentials(
                userId: (string)$nodes[2],
                btKey: (string)$nodes[0],
                apiKey: (string)$nodes[1],
            );
        }
        unset($nodes);
        unset($dom);
        unset($html);

        return $result;
    }

    private function parseFormToken(string $page): ?string
    {
        libxml_use_internal_errors(use_errors: true);
        $result = null;
        $html = new DOMDocument();
        $html->loadHtml(source: $page);
        $dom = simplexml_import_dom($html);
        $nodes = $dom->xpath(expression: "/html/head/script[1]");
        if (count($nodes) === 1) {
            $matches = [];
            preg_match("|.*form_token[^']*'([^,]*)',.*|si", $nodes[0], $matches);
            if (count($matches) === 2) {
                $result = $matches[1];
            }
        }
        unset($nodes);
        unset($dom);
        unset($html);

        return $result;
    }

    private function getProfilePage(int $userId): ?string
    {
        $options = [
            'query' => ['u' => $userId, 'mode' => self::profileAction]
        ];
        try {
            $this->logger->info('Reading profile info', ['id' => $userId]);
            $response = $this->client->get(self::profileURL, $options);
        } catch (GuzzleException $e) {
            $this->logger->error('Failed to fetch profile page', ['id' => $userId, 'error' => $e]);
            return null;
        }

        if (self::isValidMime($this->logger, $response, self::$webMime)) {
            return $response->getBody()->getContents();
        } else {
            $this->logger->error('Broken profile page', ['id' => $userId]);
            return null;
        }
    }

    public function getKeys(int $userId): ?ApiCredentials
    {
        $html = $this->getProfilePage($userId);
        if (null === $html) {
            return null;
        }

        $credentials = $this->parseApiCredentials($html);
        if (null === $credentials) {
            $this->logger->error('Unable to extract API credentials from page');
            return null;
        }
        $this->logger->info('Successfully obtained API credentials', ['id' => $userId]);
        return $credentials;
    }

    public function getFormToken(int $userId): ?string
    {
        $html = $this->getProfilePage($userId);
        if (null === $html) {
            return null;
        }

        $formToken = $this->parseFormToken($html);
        if (null === $formToken) {
            $this->logger->error('Unable to extract form token from page');
            return null;
        }
        $this->logger->info('Successfully obtained form token', ['id' => $userId]);
        return $formToken;
    }
}
