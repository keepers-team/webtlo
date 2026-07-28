<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\External;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use KeepersTeam\Webtlo\Config\ForumConnect;
use KeepersTeam\Webtlo\External\Shared\Validation;
use Psr\Log\LoggerInterface;

/**
 * Класс ForumClient для взаимодействия с форумом.
 */
final class ForumClient
{
    use Forum\DomHelper;
    use Forum\TorrentDownload;
    use Forum\UnregisteredTopic;
    use Validation;

    /** @var string URL просмотра темы */
    protected const topicURL = '/forum/viewtopic.php';

    /** @var string URL загрузки торрент-файла */
    protected const torrentUrl = '/forum/dl_keeper.php';

    /**
     * @param Client          $client HTTP-клиент для запросов
     * @param LoggerInterface $logger интерфейс для записи журнала
     */
    public function __construct(
        private readonly Client           $client,
        private readonly ForumConnect     $connect,
        private readonly LoggerInterface  $logger,
    ) {}

    /**
     * Получить используемый домен трекера.
     */
    public function getForumDomain(): string
    {
        return $this->connect->baseUrl;
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
            $this->logException($e->getCode(), $e->getMessage(), $params);

            return null;
        }

        if ($validate && !self::isValidMime(logger: $this->logger, response: $response, expectedMime: self::$webMime)) {
            $this->logger->error('Broken page');

            return null;
        }

        return $response->getBody()->getContents();
    }

    /**
     * Записать ошибку в лог.
     *
     * @param int                  $code    Код ошибки
     * @param string               $message Сообщение об ошибке
     * @param array<string, mixed> $params  Параметры запроса
     */
    private function logException(int $code, string $message, array $params = []): void
    {
        $this->logger->error(
            'Ошибка выполнения запроса',
            ['code' => $code, 'error' => $message]
        );

        if (!empty($params)) {
            $this->logger->debug('Failed params', $params);
        }
    }
}
