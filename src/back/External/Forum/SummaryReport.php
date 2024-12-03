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
        if (!empty($nodes) && 1 === count($nodes)) {
            $postLink = self::getFirstNodeValue(list: $nodes);

            $matches = [];
            preg_match('|viewtopic\.php\?p=(\d+)#.*|si', $postLink, $matches);
            if (2 === count($matches)) {
                return (int) $matches[1];
            }

            $this->logger->debug('parsePostIdFromReportSearch', ['postLink' => $postLink, 'matches' => $matches]);
        }

        $this->logger->debug('parsePostIdFromReportSearch', ['xpathQuery' => $xpathQuery, 'nodes' => $nodes]);

        return null;
    }
}
