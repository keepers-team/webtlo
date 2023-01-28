<?php

namespace KeepersTeam\Webtlo\External\Forum;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Exception\GuzzleException;
use KeepersTeam\Webtlo\Config\ApiCredentials;
use KeepersTeam\Webtlo\Config\Credentials;
use KeepersTeam\Webtlo\Config\Defaults;
use KeepersTeam\Webtlo\Config\Proxy;
use KeepersTeam\Webtlo\Config\Timeout;
use KeepersTeam\Webtlo\External\Validation;
use KeepersTeam\Webtlo\External\WebClient;
use Psr\Http\Message\StreamInterface;
use Psr\Log\LoggerInterface;

final class ForumClient
{
    use Validation;
    use Authentication;
    use WebClient;

    private const loginAction = 'вход';
    private const profileAction = 'viewprofile';
    private const inboxURL = '/forum/privmsg.php';
    private const loginURL = '/forum/login.php';
    private const profileURL = '/forum/profile.php';
    private const torrentUrl = '/forum/dl.php';
    private const sensitiveParams = [
        'login_username',
        'login_password',
        'login',
        'keeper_api_key',
        'form_token',
    ];

    private function __construct(
        private readonly LoggerInterface $logger,
        private readonly Client $client,
        private readonly CookieJar $cookieJar,
        private readonly string $formToken,  /** @phpstan-ignore-line */
        private readonly ApiCredentials $apiCredentials,
    ) {
    }

    public static function create(
        LoggerInterface $logger,
        Credentials $credentials,
        CookieJar $cookieJar = new CookieJar(),
        ?Proxy $proxy = null,
        string $forumURL = Defaults::forumUrl,
        Timeout $timeout = new Timeout(),
    ): ForumClient|false {
        $client = self::getClient($logger, $forumURL, $proxy, $timeout, $cookieJar);

        $login = fn () => null !== self::request(
            client: $client,
            logger: $logger,
            method: 'POST',
            url: self::loginURL,
            options: [
                'form_params' => [
                    'login_username' => mb_convert_encoding($credentials->username, 'Windows-1251', 'UTF-8'),
                    'login_password' => mb_convert_encoding($credentials->password, 'Windows-1251', 'UTF-8'),
                    'login' => mb_convert_encoding(self::loginAction, 'Windows-1251', 'UTF-8'),
                ]
            ]
        );

        $loggedIn = false;

        if ($cookieJar->count() > 0) {
            $logger->info('Detected non-empty cookie jar, trying stored session first');
            $messagesPage = self::request(
                client: $client,
                logger: $logger,
                method: 'GET',
                url: self::inboxURL,
                options: [
                    'allow_redirects' => false, // 302 only in case of logged out user
                    'query' => ['folder' => 'inbox']
                ]
            );
            if (null === $messagesPage) {
                $logger->info('Looks like cookies are rotten, logging in');
                $loggedIn = $login();
            } else {
                $logger->info('Cookies are still fresh');
                $loggedIn = true;
            }
        } else {
            $logger->info('No cookies found, proceeding with fresh login');
            $loggedIn = $login();
        }

        if (!$loggedIn) {
            $logger->error('Authentication error');
            return false;
        }

        $userId = self::parseUserId($cookieJar, $logger);
        if (null === $userId) {
            $logger->error('Unable to extract user identifier from cookies');
            return false;
        }
        $logger->info('Extracted user identifier', ['id' => $userId]);

        $profilePage = self::request(
            client: $client,
            logger: $logger,
            method: 'GET',
            url: self::profileURL,
            options: ['query' => ['u' => $userId, 'mode' => self::profileAction]]
        );
        if (null === $profilePage) {
            return false;
        }

        $apiCredentials = self::parseApiCredentials($profilePage);
        if (null === $apiCredentials) {
            $logger->error('Unable to extract API credentials from page');
            return false;
        }
        $logger->info('Successfully obtained API credentials');

        $formToken = self::parseFormToken($profilePage);
        if (null === $formToken) {
            $logger->error('Unable to extract form token from page');
            return false;
        }
        $logger->info('Successfully obtained form token');

        return new ForumClient(
            logger: $logger,
            client: $client,
            cookieJar: $cookieJar,
            formToken: $formToken,
            apiCredentials: $apiCredentials
        );
    }

    private static function request(
        ClientInterface $client,
        LoggerInterface $logger,
        string $method,
        string $url,
        array $options
    ): ?string {
        $redactedParams = ['url' => $url, ...$options];
        array_walk_recursive(
            array: $redactedParams,
            callback: fn (&$v, $k) => in_array($k, self::sensitiveParams) ? $v = '[SENSITIVE]' : null
        );
        $logger->info('Fetching page', $redactedParams);
        try {
            $response = $client->request($method, $url, $options);
        } catch (GuzzleException $e) {
            $logger->error('Failed to fetch page', [...$redactedParams, 'error' => $e]);
            return null;
        }

        if (!self::isValidMime($logger, $response, self::$webMime)) {
            $logger->error('Broken page', $redactedParams);
            return null;
        }
        $statusCode = $response->getStatusCode();
        if ($statusCode !== 200) {
            $logger->error('Unexpected code', [...$redactedParams, 'code' => $statusCode]);
            return null;
        }

        return $response->getBody()->getContents();
    }

    public function getCookieJar(): CookieJar
    {
        return $this->cookieJar;
    }

    /**
     * Download torrent file
     *
     * @param string $infoHash Info hash for torrent
     * @return StreamInterface|false Stream with torrent body
     */
    public function downloadTorrent(string $infoHash, bool $addRetracker): StreamInterface|false
    {
        $options = [
            'form_params' => [
                'keeper_user_id' => $this->apiCredentials->userId,
                'keeper_api_key' => $this->apiCredentials->apiKey,
                'add_retracker_url' => $addRetracker ? 1 : 0,
                'h' => $infoHash
            ]
        ];
        try {
            $this->logger->info('Downloading torrent', ['hash' => $infoHash]);
            $response = $this->client->post(self::torrentUrl, $options);
        } catch (GuzzleException $e) {
            $this->logger->error('Failed to download torrent', ['hash' => $infoHash, 'error' => $e]);
            return false;
        }

        if (self::isValidMime($this->logger, $response, self::$torrentMime)) {
            return $response->getBody();
        } else {
            return false;
        }
    }
}
