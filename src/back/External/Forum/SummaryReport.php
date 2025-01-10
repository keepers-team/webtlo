<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\External\Forum;

/**
 * Работа со сводным отчётом.
 */
trait SummaryReport
{
    use DomHelper;

    /**
     * Send summary report to forum topic.
     *
     * @param int    $userId  Идентификатор пользователя
     * @param string $message Текст сообщения
     *
     * @return ?int Идентификатор сообщения, если отправка успешна, иначе null
     */
    public function sendSummaryReport(int $userId, string $message): ?int
    {
        $postId = $this->searchSummaryReportMessageId(userId: $userId);

        return $this->sendMessage(topicId: self::reportsTopicId, message: $message, postId: $postId);
    }

    /**
     * Search user's message in topic with summary reports.
     *
     * @param int $userId Идентификатор пользователя
     *
     * @return ?int Идентификатор сообщения, если поиск успешен, иначе null
     */
    private function searchSummaryReportMessageId(int $userId): ?int
    {
        $options  = [
            'query' => [
                'uid' => $userId,
                't'   => self::reportsTopicId,
                'dm'  => 1,
            ],
        ];
        $response = $this->get(url: self::searchUrl, params: $options);
        if (null === $response) {
            return null;
        }

        $postId = self::parsePostIdFromReportSearch(page: $response);
        if (null === $postId) {
            $this->logger->debug('No reports found for given user', ['userId' => $userId]);
        }

        return $postId;
    }

    /**
     * Parse the post ID from the summary report search results.
     *
     * @param string $page HTML содержимое страницы
     *
     * @return ?int Идентификатор сообщения, если найден, иначе null
     */
    private function parsePostIdFromReportSearch(string $page): ?int
    {
        $dom = self::parseDOM(page: $page);

        $xpathQuery = (
            // Main container
            '//*[@id="main_content_wrap"]' .
            // Table with topic post
            '//table[contains(@class, "topic")]' .
            // Container with topic head
            '//div[contains(@class, "post_head")]' .
            // Link with message identifier
            '//a[contains(@class, "small")]/@href'
        );

        $nodes = $dom->query(expression: $xpathQuery);

        /** Количество найденных сообщений в теме со сводными отчётами. */
        $nodesCount = $nodes ? $nodes->length : 0;
        if (1 === $nodesCount) {
            $postLink = self::getFirstNodeValue(list: $nodes);

            $postId = $this->parsePostIdFromPostLink(postLink: $postLink);
            if (null !== $postId) {
                return $postId;
            }
        }

        if ($nodesCount > 1) {
            $this->logger->warning(
                'На форуме найдено {count} сообщений со сводными отчётами, а ожидалось 1.',
                ['count' => $nodesCount]
            );

            $postIds = [];
            for ($i = 0; $i < $nodesCount; ++$i) {
                $postLink = self::getNthNodeValue($nodes, $i);

                $this->logger->warning('Сообщение {index}/{count}: {link}', [
                    'index' => $i + 1,
                    'count' => $nodesCount,
                    'link'  => $postLink,
                ]);

                // Пробуем найти ид сообщения и записать его в список.
                $postId = $this->parsePostIdFromPostLink(postLink: (string) $postLink);
                if (null !== $postId) {
                    $postIds[] = $postId;
                }
            }

            // Если удалось найти несколько сообщений, то используем самое первое сообщение в теме.
            if (count($postIds) > 0) {
                return min($postIds);
            }
        }

        return null;
    }

    private function parsePostIdFromPostLink(string $postLink): ?int
    {
        $matches = [];
        preg_match('|viewtopic\.php\?p=(\d+)#.*|si', $postLink, $matches);
        if (2 === count($matches)) {
            return (int) $matches[1];
        }

        $this->logger->debug('parsePostIdFromPostLink', ['postLink' => $postLink, 'matches' => $matches]);

        return null;
    }
}
