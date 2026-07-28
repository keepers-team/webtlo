<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\External;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use KeepersTeam\Webtlo\Config\ApiCredentials;
use KeepersTeam\Webtlo\External\Shared\Validation;
use Psr\Http\Message\StreamInterface;
use Psr\Log\LoggerInterface;

/**
 * Класс ForumClient для взаимодействия с форумом.
 */
final class ForumClient
{
    use Validation;

    /** @var string URL загрузки торрент-файла */
    protected const torrentUrl = '/forum/dl_keeper.php';

    /**
     * @param Client          $client HTTP-клиент для запросов
     * @param ApiCredentials  $auth   ключи доступа к API
     * @param LoggerInterface $logger интерфейс для записи журнала
     */
    public function __construct(
        private readonly Client          $client,
        private readonly ApiCredentials  $auth,
        private readonly LoggerInterface $logger,
    ) {
        $this->auth->validate();
    }

    /**
     * Выполнить GET-запрос.
     *
     * @param string               $url    URL для запроса
     * @param array<string, mixed> $params Параметры запроса
     *
     * @return ?string Результат запроса
     */
    public function get(string $url, array $params = []): ?string
    {
        return $this->request(method: 'GET', url: $url, params: $params);
    }

    /**
     * Выполнить POST-запрос.
     *
     * @param string               $url    URL для запроса
     * @param array<string, mixed> $params Параметры запроса
     *
     * @return ?string Результат запроса
     */
    public function post(string $url, array $params = []): ?string
    {
        return $this->request(method: 'POST', url: $url, params: $params);
    }

    /**
     * Выполнить HTTP-запрос.
     *
     * @param string               $method   Метод запроса (GET или POST)
     * @param string               $url      URL для запроса
     * @param array<string, mixed> $params   Параметры запроса
     * @param bool                 $validate Нужно ли валидировать ответ
     *
     * @return ?string Результат запроса
     */
    private function request(string $method, string $url, array $params = [], bool $validate = true): ?string
    {
        try {
            $response = $this->client->request(method: $method, uri: $url, options: $params);
        } catch (GuzzleException $e) {
            $this->logger->error(
                'Ошибка выполнения запроса',
                ['code' => $e->getCode(), 'error' => $e->getMessage()]
            );

            if (!empty($params)) {
                $this->logger->debug('Failed params', $params);
            }

            return null;
        }

        if ($validate && !self::isValidMime(logger: $this->logger, response: $response, expectedMime: self::$webMime)) {
            $this->logger->error('Broken page');

            return null;
        }

        return $response->getBody()->getContents();
    }

    /**
     * Download torrent file.
     *
     * @param string $infoHash Info hash for torrent
     *
     * @return ?StreamInterface Stream with torrent body
     */
    public function downloadTorrent(string $infoHash, bool $addRetracker = false): ?StreamInterface
    {
        $options = [
            'query' => [
                'keeper_user_id'    => $this->auth->userId,
                'keeper_api_key'    => $this->auth->apiKey,
                'add_retracker_url' => $addRetracker ? 1 : 0,
                'h'                 => $infoHash,
            ],
        ];

        try {
            $this->logger->debug('Downloading torrent', ['hash' => $infoHash]);
            $response = $this->client->get(self::torrentUrl, $options);
        } catch (GuzzleException $e) {
            $this->logger->error('Failed to download torrent', ['hash' => $infoHash, 'error' => $e]);

            return null;
        }

        if (self::isValidMime(logger: $this->logger, response: $response, expectedMime: self::$torrentMime)) {
            return $response->getBody();
        }

        return null;
    }
}
