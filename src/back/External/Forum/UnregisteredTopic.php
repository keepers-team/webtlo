<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\External\Forum;

use KeepersTeam\Webtlo\External\Api\V1\TorrentStatus;

/**
 * Работа с темами раздач.
 */
trait UnregisteredTopic
{
    use DomHelper;

    /**
     * Поиск сведений о раздаче в её теме.
     *
     * @param int $topicId ID темы
     *
     * @return ?array<string, string> Ассоциативный массив сведений о раздаче
     */
    public function getUnregisteredTopic(int $topicId): ?array
    {
        $page = $this->fetchTopicPage(topicId: $topicId);
        if ($page === null) {
            return null;
        }

        $dom = self::parseDom(page: $page);

        // Название раздачи.
        $topicName = self::getFirstNodeValue(list: $dom->query('//*[@id="topic-title"]'));
        $topicName = $topicName ?: 'неизвестно';

        $topicStatuses = [];
        // Раздача в мусорке, не найдена.
        $topicStatuses[] = self::getFirstNodeValue(list: $dom->query('//*[@id="main_content_wrap"]//div[@class="mrg_16"]'));
        // Повтор, закрыто, не оформлена и т.п.
        $topicStatuses[] = self::getFirstNodeValue(list: $dom->query('//*[@id="tor-status-resp"]/a/b'));
        // Поглощено и прочие открытые статусы.
        $topicStatuses[] = self::getFirstNodeValue(list: $dom->query('//div[@class="attach_link"]/i[@class="normal"]/b'));

        // Статус раздачи.
        $topicStatus = trim(implode('', array_filter($topicStatuses)));
        $topicStatus = mb_strtolower($topicStatus ?: 'неизвестно');
        if (TorrentStatus::isValidStatusLabel(label: $topicStatus)) {
            $topicStatus = sprintf('обновлено (%s)', $topicStatus);
        }

        // Приоритет.
        $topicPriority = self::getFirstNodeValue(list: $dom->query('//div[@class="attach_link"]/b[last()]'));
        if (empty($topicPriority)) {
            $topicPriority = self::getFirstNodeValue(list: $dom->query('//table[contains(@class, "attach")]//td/b'));
        }
        $topicPriority = mb_strtolower($topicPriority ?: 'обычный');

        // Текущее место расположения раздачи.
        $currentForumQuery = $dom->query(expression: '//td[contains(@class, "t-breadcrumb-top")]//a');

        $currentForumName = '';
        if (!empty($currentForumQuery)) {
            $currentForumName = implode(' » ', array_map(function($node) {
                return $node->textContent;
            }, iterator_to_array($currentForumQuery)));
        }

        // Кто и откуда перенёс тему.
        $transferredByWhom = $transferredFrom = '';

        $isTopicInArchive = mb_strpos($currentForumName, 'Архив') !== false;
        if (!empty($currentForumName) && $isTopicInArchive) {
            // Переходим на последнюю страницу темы, если она есть.
            $list = $dom->query(expression: '//table[@id="pagination"]//a[@class="pg"]');
            if (!empty($list) && $list->count() > 1) {
                $lastPage = (int) $list->item($list->length - 2)?->textContent;

                $topicPage = $this->fetchTopicPage(topicId: $topicId, offset: ($lastPage - 1) * 30);
                if ($topicPage !== null) {
                    $dom = self::parseDom(page: $topicPage);
                }
                unset($lastPage, $topicPage);
            }

            $node = $dom->query(expression: '//table[@id="topic_main"]/tbody[last()]');

            // Ищем последнее сообщение в теме.
            $lastMessage = !empty($node) ? $node->item(0) : null;
            if ($lastMessage !== null) {
                $avatarList = $dom->query(expression: '*//p[@class="avatar"]/img/@src', contextNode: $lastMessage);
                $avatarLink = self::getFirstNodeValue(list: $avatarList);

                // Если последнее сообщение принадлежит боту, ищем того, кто это сделал.
                if (preg_match('/17561.gif$/i', $avatarLink)) {
                    $list = $dom->query(expression: '*//a[@class="postLink"]', contextNode: $lastMessage);
                    if (!empty($list) && $list->count() === 3) {
                        // Откуда перенесена раздача.
                        $transferredFrom = $list->item(0)?->nodeValue;

                        $user = $list->item(2);
                        $href = self::getFirstNodeValue(list: $dom->query(expression: '@href', contextNode: $user));
                        if (preg_match('/^profile.php\?mode=viewprofile&u=[0-9]+$/', $href)) {
                            // Кто перенёс раздачу.
                            $transferredByWhom = $user?->nodeValue;
                        }

                        unset($user, $href);
                    }
                }

                unset($avatarList, $avatarLink);
            }
        }

        if (
            !$isTopicInArchive
            && empty($transferredFrom)
            && !empty($currentForumName)
        ) {
            $transferredFrom = preg_replace('/.*» /', '', $currentForumName);
        }

        return [
            'name'                => $topicName,
            'status'              => $topicStatus,
            'priority'            => $topicPriority,
            'transferred_from'    => (string) $transferredFrom,
            'transferred_to'      => $currentForumName,
            'transferred_by_whom' => (string) $transferredByWhom,
        ];
    }

    /**
     * Получить страницу темы раздачи.
     *
     * @param int  $topicId ID темы
     * @param ?int $offset  Смещение для получения последней страницы темы
     *
     * @return ?string Страница темы в виде HTML
     */
    private function fetchTopicPage(int $topicId, ?int $offset = null): ?string
    {
        $query = ['t' => $topicId];
        if ($offset !== null) {
            $query['start'] = $offset;
        }

        return $this->post(url: self::topicURL, params: ['query' => $query]);
    }
}
