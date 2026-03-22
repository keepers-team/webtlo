<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\External\Forum;

use KeepersTeam\Webtlo\Helper;
use RuntimeException;

/**
 * Отправка/редактирование сообщения в теме на форуме.
 */
trait SendMessage
{
    use DomHelper;

    /** @var int Версия приложения при отправке сообщений. */
    private static int $appSendVersion = 4;

    /** @var int Идентификатор темы с описанием приложения. */
    private static int $appTopicId = 4546540;

    /** @var ?string Токен для отправки/редактирования сообщения. */
    private static ?string $formToken = null;

    /** @var ?bool Блокировка отправки сообщений. */
    private ?bool $blockingSend = null;

    /** @var ?string Причина блокировки отправки сообщений. */
    private ?string $blockingReason = null;

    /**
     * Отправка сообщения в заданную тему.
     *
     * @param int      $topicId Идентификатор темы
     * @param string   $message Текст сообщения
     * @param null|int $postId  Идентификатор сообщения для редактирования (null для нового сообщения)
     *
     * @return ?int Идентификатор отправленного сообщения, если отправка успешна, иначе null
     */
    public function sendMessage(int $topicId, string $message, ?int $postId = null): ?int
    {
        // блокировка отправки сообщений
        if (!isset($this->blockingSend)) {
            $this->blockingSend = false;

            if ($unavailable = $this->checkAccess()) {
                $this->blockingSend   = true;
                $this->blockingReason = $unavailable->value;
            }
        }
        if ($this->blockingSend && $this->blockingReason !== null) {
            throw new RuntimeException($this->blockingReason);
        }

        // Замена спецсимволов в отправляемом сообщении.
        $message = str_replace('<br />', '', $message);
        $message = str_replace('[br]', "\n", $message);

        // Публикуем новое сообщение, по умолчанию.
        $form = [
            't'           => $topicId,
            'mode'        => self::replyAction,
            'submit_mode' => 'submit',
            'form_token'  => self::$formToken,
            'message'     => Helper::encodeCyrillicString($message),
        ];

        // Если есть ид сообщения, то редактируем старое.
        if ($postId !== null) {
            $form['mode'] = self::editAction;
            $form['p']    = $postId;
        }

        $response = $this->post(url: self::postUrl, params: ['form_params' => $form]);
        if ($response === null) {
            return null;
        }

        // Пробуем найти ид сообщения, если его раньше не было.
        $postId = self::parseTopicIdFromPostResponse(page: $response, postId: $postId);
        if ($postId === null) {
            $this->logger->warning(
                'Ошибка отправки сообщения на форум: {error}',
                ['error' => self::parseTopicEditErrorFromPostResponse(page: $response)]
            );
        }

        return $postId;
    }

    /**
     * Проверка доступа к отправке сообщений.
     *
     * @return ?AccessCheck Результат проверки доступа (null если доступ есть)
     */
    private function checkAccess(): ?AccessCheck
    {
        $response = $this->post(url: self::topicURL, params: ['query' => ['t' => self::$appTopicId]]);
        if ($response === null) {
            return AccessCheck::NOT_AUTHORIZED;
        }

        $dom = self::parseDom(page: $response);

        $list = $dom->query(expression: '//h1[contains(@class, "pagetitle")]/text()');

        if (self::getFirstNodeValue(list: $list) === 'Вход') {
            return AccessCheck::NOT_AUTHORIZED;
        }

        $list = $dom->query(expression: '//div[contains(@class, "mrg_16")]/text()');
        if (self::getFirstNodeValue(list: $list) === 'Тема не найдена') {
            return AccessCheck::USER_CANDIDATE;
        }

        $list = $dom->query(expression: '//a[@id="topic-title"]');
        if (!empty($list) && $list->count() > 0) {
            $topicTitle = $list->item(0)->textContent ?? '';

            $matches = [];
            if (preg_match('/#(\d+)$/', $topicTitle, $matches)) {
                $allowed = (int) $matches[1];

                if (!($allowed <= self::$appSendVersion)) {
                    return AccessCheck::VERSION_OUTDATED;
                }
            }
        }

        return null;
    }

    public function makePostLink(string $path, int $postId): string
    {
        return sprintf('%s%s?p=%d', $this->connect->url, $path, $postId);
    }

    /**
     * Извлечение идентификатора сообщения из ответа сервера.
     *
     * @param string $page HTML содержимое страницы
     *
     * @return ?int Идентификатор сообщения, если найден, иначе null
     */
    private static function parseTopicIdFromPostResponse(string $page, ?int $postId): ?int
    {
        if ($postId !== null) {
            return $postId;
        }

        $dom = self::parseDOM(page: $page);

        $xpathQuery = (
            // Main container
            '//div[@id="main_content_wrap"]' .
            // Table with message response
            '//table[contains(@class, "message")]' .
            // Link to post identifier
            '//div[@class="mrg_16"]/a/@href'
        );

        $nodes = $dom->query(expression: $xpathQuery);
        if (!empty($nodes) && $nodes->count() === 1) {
            $postLink = (string) $nodes->item(0)?->nodeValue;

            $matches = [];
            preg_match('|.*viewtopic\.php\?p=(\d+)|si', $postLink, $matches);
            if (count($matches) === 2) {
                return (int) $matches[1];
            }
        }

        return null;
    }

    /**
     * Извлечение ошибки из ответа сервера при неудачной попытке отправки сообщения.
     *
     * @param string $page HTML содержимое страницы
     *
     * @return string Текст ошибки
     */
    private static function parseTopicEditErrorFromPostResponse(string $page): string
    {
        $dom = self::parseDOM(page: $page);

        $xpathQuery = (
            // Main container
            '//div[@id="main_content_wrap"]' .
            // Table with message response
            '//table[contains(@class, "message")]' .
            // Link to post identifier
            '//div[@class="mrg_16"]'
        );

        $nodes = $dom->query(expression: $xpathQuery);
        if (!empty($nodes) && $nodes->count() === 1) {
            $result = $nodes->item(0)->textContent ?? null;
        }

        return trim($result ?? 'Неизвестная ошибка');
    }

    protected static function parseFormToken(string $page): ?string
    {
        $dom = self::parseDOM($page);

        $nodes = $dom->query(expression: '/html/head/script[1]');
        if (!empty($nodes) && $nodes->count() === 1) {
            $script = self::getFirstNodeValue(list: $nodes);

            $matches = [];
            preg_match("|.*form_token[^']*'([^,]*)',.*|si", $script, $matches);
            if (count($matches) === 2 && !empty($matches[1])) {
                return self::$formToken = (string) $matches[1];
            }
        }

        return null;
    }
}
