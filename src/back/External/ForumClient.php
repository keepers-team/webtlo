<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\External;

use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Exception\GuzzleException;
use KeepersTeam\Webtlo\Config\ForumConnect;
use KeepersTeam\Webtlo\Config\ForumCredentials;
use KeepersTeam\Webtlo\External\Shared\Validation;
use KeepersTeam\Webtlo\Settings;
use Psr\Log\LoggerInterface;

/**
 * Класс ForumClient для взаимодействия с форумом.
 */
final class ForumClient
{
    use Forum\Authentication;
    use Forum\CaptchaHelper;
    use Forum\DomHelper;
    use Forum\GetCredentials;
    use Forum\SendMessage;
    use Forum\SummaryReport;
    use Forum\TorrentDownload;
    use Forum\UnregisteredTopic;
    use Validation;

    /** @var string Куки для авторизации на форуме. */
    protected static string $authCookieName = 'bb_session';

    /** @var int Ид темы для публикации сводных отчётов */
    protected const reportsTopicId = 4275633;

    /** @var string URL для входящих сообщений */
    protected const inboxURL = '/forum/privmsg.php';

    /** @var string URL для авторизации */
    protected const loginURL = '/forum/login.php';

    /** @var string URL для редактирования/публикации сообщения */
    protected const postUrl = '/forum/posting.php';

    /** @var string URL профиля */
    protected const profileURL = '/forum/profile.php';

    /** @var string URL для поиска */
    protected const searchUrl = '/forum/search.php';

    /** @var string URL просмотра темы */
    protected const topicURL = '/forum/viewtopic.php';

    /** @var string URL загрузки торрент-файла */
    protected const torrentUrl = '/forum/dl.php';

    /** @var string Действие редактирования */
    protected const editAction = 'editpost';

    /** @var string Действие входа */
    protected const loginAction = 'вход';

    /** @var string Действие просмотра профиля */
    protected const profileAction = 'viewprofile';

    /** @var string Действие публикации */
    protected const replyAction = 'reply';

    /** @var ?string Обновленный cookie авторизации */
    protected ?string $updatedCookie = null;

    /**
     * @param Client           $client   HTTP-клиент для запросов
     * @param ForumCredentials $cred     учетные данные форума
     * @param CookieJar        $cookie   cookieJar для управления cookies
     * @param LoggerInterface  $logger   интерфейс для записи журнала
     * @param Settings         $settings настройки приложения
     */
    public function __construct(
        private readonly Client           $client,
        private readonly ForumCredentials $cred,
        private readonly ForumConnect     $connect,
        private readonly CookieJar        $cookie,
        private readonly LoggerInterface  $logger,
        private readonly Settings         $settings,
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

    /**
     * Деструктор ForumClient.
     * Сохраняет обновленные cookies при уничтожении объекта.
     */
    public function __destruct()
    {
        if ($this->updatedCookie !== null) {
            $this->logger->debug('call settings set cookie', ['cookie' => $this->updatedCookie]);

            $this->settings->setForumCookie($this->updatedCookie);
        }
    }
}
