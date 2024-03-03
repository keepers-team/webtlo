<?php

namespace KeepersTeam\Webtlo\Forum\Report;

use Exception;
use KeepersTeam\Webtlo\Config\Credentials;
use KeepersTeam\Webtlo\DTO\ForumObject;
use KeepersTeam\Webtlo\Enum\UpdateMark;
use KeepersTeam\Webtlo\Enum\UpdateStatus;
use KeepersTeam\Webtlo\Helper;
use KeepersTeam\Webtlo\Legacy\Db;
use KeepersTeam\Webtlo\Legacy\Log;
use KeepersTeam\Webtlo\Module\Forums;
use KeepersTeam\Webtlo\Module\LastUpdate;
use KeepersTeam\Webtlo\WebTLO;
use PDO;

/**
 * Объект для создания новых отчётов.
 */
final class Creator
{
    /** Ид темы для публикации сводных отчётов */
    public const SUMMARY_FORUM = 4275633;

    /** @var int[] */
    public ?array $forums = null;

    private array       $config;
    private Credentials $user;
    private WebTLO      $webtlo;

    /** @var int[] Исключённые из отчётов подразделы */
    private array $excludeForumsIDs = [];

    /** @var int[] Исключённые из отчётов торрент-клиенты */
    private array $excludeClientsIDs = [];

    private ?int $updateTime = null;

    private CreationMode $mode = CreationMode::CRON;

    private string $implodeGlue = '[br]';
    private string $topicGlue   = '';

    private array $keeperKeys = [
        'keep_count', // Общее кол-во хранимых раздач
        'keep_size',  // Общий вес хранимых раздач
        'dl_count',   // Кол-во скачиваемых раздач
        'dl_size',    // Вес скачиваемых раздач
    ];

    private ?array $stored = null;

    public function __construct(
        array       $config,
        Credentials $user
    ) {
        $this->config = $config;
        $this->user   = $user;
    }

    /**
     * Сводный отчёт.
     *
     * @throws Exception
     */
    public function getSummaryReport(): string
    {
        if (null === $this->stored) {
            $this->fillStoredValues();
        }
        // Вытаскиваем из базы хранимое
        $total = $this->calcSummary($this->stored);

        // Строка хранимого подраздела.
        // Первая ссылка в тему со списками, вторая на своё сообщение.
        $subsectionPattern = '[*][url=viewtopic.php?t=%s][u]%s[/u][/url] — [url=viewtopic.php?p=%s][u]%s шт. (%s)[/u][/url]';
        // Разбираем хранимое
        $savedSubsections = [];
        foreach ($this->forums as $forumId) {
            // Исключаем подразделы, согласно конфига.
            if (in_array($forumId, $this->excludeForumsIDs)) {
                continue;
            }

            $forumValues = $this->stored[$forumId] ?? [];
            if (!count($forumValues)) {
                continue;
            }

            $forum = Forums::getForum($forumId);

            $topic_id = $forum->topic_id ?: 'NaN';
            $post_id  = $forum->post_ids[0] ?? 'NaN';

            // инфа о подразделе в сводный
            $savedSubsections[] = sprintf(
                $subsectionPattern,
                $topic_id,
                $forum->name,
                $post_id,
                $forumValues['keep_count'],
                $this->bytes($forumValues['keep_size'])
            );

            unset($forumId, $forumValues, $topic_id);
        }

        // формируем сводный отчёт
        $summary   = [];
        $summary[] = $this->getFormattedUpdateTime();
        $summary[] = '';
        $summary[] = sprintf('Всего хранимых подразделов: [b]%s[/b] шт.', count($savedSubsections));
        $this->prepareSummaryHeader($summary, $total);
        $summary[] = '';
        $summary[] = $this->webtlo->versionLineUrl();
        $summary[] = '[hr]';

        return implode($this->implodeGlue, [...$summary, '[list=1]', ...$savedSubsections, '[/list]']);
    }

    /**
     * Собрать отчёт по заданному разделу.
     *
     * @throws Exception
     */
    public function getForumReport(ForumObject $forum): array
    {
        // исключаем подразделы
        if (in_array($forum->id, $this->excludeForumsIDs)) {
            throw new Exception("Из отчёта исключен подраздел № $forum->id");
        }

        // Вытаскиваем из базы хранимое.
        $userStored = $this->getStoredForumValues($forum->id);

        $topicHeader = '';
        // Если отчёт для UI или текущий пользователь - автор шапки темы, собираем обновлённую шапку.
        if (CreationMode::UI === $this->mode || $this->user->userId === $forum->author_id) {
            $topicHeader = $this->createTopicFirstMessage($forum, $userStored);
        }

        // Создаём заголовок отчёта по подразделу.
        $messageHeader = $this->prepareMessageHeader($userStored);

        // const & pattern.
        $message_length_max = 119000;

        $pattern_spoiler = '[spoiler="№№ %s — %s"][font=mono2][list=1]<br />[*=%s]%s<br />[/list][/font][/spoiler]';
        $spoiler_length  = mb_strlen($pattern_spoiler, 'UTF-8');

        // Найти раздачи в БД.
        $topics = $this->getStoredForumTopics($forum->id);
        // Количество раздач.
        $topics_count = count($topics);

        $topicMessages = [];
        // формируем списки
        foreach ($topics as $topic) {
            if (empty($tmp)) {
                $tmp['firstTopic']    = 1;
                $tmp['messageLength'] = 0;
                $tmp['topicCounter']  = 0;
            }
            $topicUrl = $this->prepareTopicUrl($topic);

            $tmp['topicCounter']++;
            $tmp['topicLines'][] = $topicUrl;

            $topicLineLength      = mb_strlen($topicUrl, 'UTF-8');
            $tmp['messageLength'] += $topicLineLength;

            // Режем раздачи на сообщения.
            $fullLength      = $tmp['messageLength'] + $topicLineLength;
            $availableLength = $message_length_max - $spoiler_length - ($tmp['topicCounter'] - $tmp['firstTopic'] + 1) * 4;
            if (
                $fullLength > $availableLength
                || $tmp['topicCounter'] == $topics_count
            ) {
                $topicMessages[] = sprintf(
                    $pattern_spoiler,
                    $tmp['firstTopic'],
                    $tmp['topicCounter'],
                    $tmp['firstTopic'],
                    implode($this->topicGlue . '[*]', $tmp['topicLines'])
                );
                // Обнуляем длину сообщения, запоминаем кол-во раздач.
                $tmp['firstTopic']    = $tmp['topicCounter'] + 1;
                $tmp['messageLength'] = 0;
                unset($tmp['topicLines']);
            }
        }
        unset($topics, $topics_count);

        if (empty($topicMessages)) {
            throw new Exception("Error: Не удалось сформировать список хранимого для подраздела № $forum->id");
        }

        // В первое сообщение дописываем заголовок.
        $topicMessages[0] = $messageHeader . $this->implodeGlue . $topicMessages[0];

        return [
            'header'   => $topicHeader,
            'messages' => $topicMessages,
        ];
    }

    /**
     * @throws Exception
     */
    public function initConfig(?CreationMode $mode = null): void
    {
        if (null !== $mode) {
            $this->mode = $mode;

            $this->implodeGlue = '<br />';
            $this->topicGlue   = '<br />';
        }

        $this->webtlo = WebTLO::getVersion();

        $this->getLastUpdateTime();
        $this->setExcluded();
        $this->setForums();
    }

    /**
     * Собрать заголовок сообщения с версией ПО.
     */
    private function prepareMessageHeader(array $userStored): string
    {
        $header   = [];
        $header[] = $this->getFormattedUpdateTime();
        $this->prepareSummaryHeader($header, $userStored);
        $header[] = $this->webtlo->versionLine();

        return implode($this->implodeGlue, $header);
    }

    /**
     * Собрать шаблон заголовка сообщения с суммарными значениями.
     */
    private function prepareSummaryHeader(array &$rows, array $val): void
    {
        $split_pattern = '- из них раздач %s10 сидов: [b]%d[/b] шт. (%s)';

        $rows[] = sprintf('Всего хранимых раздач: [b]%s[/b] шт. (%s)', $val['keep_count'], $this->boldBytes($val['keep_size']));
        if ($val['less10_count'] > 0 && $val['more10_count'] > 0) {
            $rows[] = sprintf($split_pattern, '&#8804;', $val['less10_count'], $this->boldBytes($val['less10_size']));
        }
        if ($val['more10_count'] > 0) {
            $rows[] = sprintf($split_pattern, '>', $val['more10_count'], $this->boldBytes($val['more10_size']));
        }
        if ($val['dl_count'] > 0) {
            $rows[] = sprintf('Всего скачиваемых раздач: [b]%s[/b] шт. (%s)', $val['dl_count'], $this->boldBytes($val['dl_size']));
        }
    }

    /**
     * Получить ссылку для отчёта. Разные версии для UI|cron
     */
    private function prepareTopicUrl(array $topic): string
    {
        $topicUrl = '';
        // #dl - скачивание, :!: - смайлик.
        if (CreationMode::UI === $this->mode) {
            // [url=viewtopic.php?t=topic_id#dl]topic_name[/url] 842 GB :!:
            $pattern_topic = '[url=viewtopic.php?t=%s]%s[/url] %s%s';

            $topicUrl = sprintf(
                $pattern_topic,
                $topic['id'] . ($topic['done'] != 1 ? '#dl' : ''),
                $topic['topic_name'],
                $this->bytes($topic['topic_size']),
                ($topic['done'] != 1 ? ' :!: ' : '')
            );
        }
        if (CreationMode::CRON === $this->mode) {
            // [url=viewtopic.php?t=topic_id#dl]topic_hash|topic_id[/url] :!:
            $pattern_topic = '[url=viewtopic.php?t=%s]%s|%d[/url]%s';

            $topicUrl = sprintf(
                $pattern_topic,
                $topic['id'] . ($topic['done'] != 1 ? '#dl' : ''),
                $topic['topic_hash'],
                $topic['id'],
                ($topic['done'] != 1 ? ' :!: ' : '')
            );
        }

        return $topicUrl;
    }

    /**
     * Собираем сообщения для UI.
     */
    public function prepareReportsMessages(array $report): string
    {
        $messages = $report['messages'];
        array_walk($messages, function(&$a, $b) {
            $b++;
            $a = sprintf('<h3>Сообщение %d</h3><div>%s</div>', $b, $a);
        });

        $topicReport = sprintf('<div class="report_message">%s</div>', implode('', $messages));

        return $report['header'] . $this->implodeGlue . $topicReport;
    }

    /**
     * Посчитать сумму хранимого.
     */
    private function calcSummary(array $stored): array
    {
        $sumKeys = [
            'keep_count',   // Общее кол-во хранимых раздач
            'keep_size',    // Общий вес хранимых раздач
            'less10_count', // Кол-во хранимых раздач с менее 10 сидов
            'less10_size',  // Вес хранимых раздач с менее 10 сидов
            'more10_count', // Кол-во хранимых раздач с более 10 сидов
            'more10_size',  // Вес хранимых раздач с более 10 сидов
            'dl_count',     // Кол-во скачиваемых раздач
            'dl_size',      // Вес скачиваемых раздач
        ];

        $total = array_fill_keys($sumKeys, 0);

        foreach ($stored as $forum_id => $forumData) {
            // исключаем подразделы
            if (in_array($forum_id, $this->excludeForumsIDs)) {
                continue;
            }

            // находим общее хранимое
            foreach ($sumKeys as $key) {
                $total[$key] += $forumData[$key];
                unset($key);
            }
            unset($forum_id, $forumData);
        }

        return $total;
    }

    /**
     * Создание шапки темы, с общим списком всех хранителей и их хранимого.
     */
    private function createTopicFirstMessage(ForumObject $forum, array $userStored): string
    {
        $keepersStored = $this->getKeepersStoredReports($forum);

        // Добавим себя в список хранителей.
        $userStored['keeper_name'] = $this->user->userName;

        $keepersStored[$this->user->userId] = $userStored;

        // Значения общего хранимого.
        $total = [];
        foreach ($this->keeperKeys as $key) {
            $total[$key] = array_sum(array_column($keepersStored, $key));
            unset($key);
        }

        // Создаём список хранителей.
        $count_keepers = 0;

        $header = $keepers = [];

        $keepers[] = '[list=1]';
        if (count($keepersStored)) {
            uasort($keepersStored, fn($a, $b) => $b['keep_count'] <=> $a['keep_count']);

            $pattern_keeper = '[*][url=profile.php?mode=viewprofile&u=%d][u][color=#006699]%s[/u][/color][/url] [color=gray]~>[/color] %s шт. [color=gray]~>[/color] %s';
            foreach ($keepersStored as $keeper_id => $values) {
                if (!$values['keep_count']) {
                    continue;
                }

                $count_keepers++;
                $keepers[] = sprintf(
                    $pattern_keeper,
                    $keeper_id,
                    $values['keeper_name'],
                    $values['keep_count'],
                    $this->bytes($values['keep_size'])
                );
            }
        }
        unset($keepersStored);
        $keepers[] = '[/list]';

        $header[] = sprintf(
            '[url=viewforum.php?f=%1$d][u][color=#006699]%2$s[/u][/color][/url]' .
            ' || ID: %1$d || ' .
            '[url=tracker.php?f=%1$d&tm=-1&o=10&s=1&oop=1][color=darkgreen][Проверка сидов][/color][/url]',
            $forum->id,
            preg_replace('/.*» ?(.*)$/', '$1', $forum->name)
        );
        $header[] = $this->getFormattedUpdateTime();
        $header[] = '';
        $header[] = sprintf('Всего раздач в подразделе: [b]%s[/b] шт. (%s)', $forum->quantity, $this->boldBytes($forum->size));
        $header[] = sprintf('Всего хранимых раздач в подразделе: [b]%s[/b] шт. (%s)', $total['keep_count'], $this->boldBytes($total['keep_size']));
        $header[] = sprintf('Всего скачиваемых раздач в подразделе: [b]%s[/b] шт. (%s)', $total['dl_count'], $this->boldBytes($total['dl_size']));
        $header[] = sprintf('Всего хранителей: [b]%d[/b]', $count_keepers);
        $header[] = '[hr]';

        // Вставляем общее хранимое в шапку.
        return implode($this->implodeGlue, array_merge($header, $keepers));
    }


    /**
     * Посчитать хранимое других хранителей указанного подраздела.
     */
    private function getKeepersStoredReports(ForumObject $forum): array
    {
        $values = Db::query_database(
            'SELECT
                    keeper_id, keeper_name,
                    SUM(CASE WHEN done = 1 THEN 1 ELSE 0 END) keep_count,
                    SUM(CASE WHEN done < 1 THEN 1 ELSE 0 END) dl_count,
                    SUM(CASE WHEN done = 1 THEN topic_size ELSE 0 END) keep_size,
                    SUM(CASE WHEN done < 1 THEN topic_size ELSE 0 END) dl_size
                FROM (
                    SELECT
                        kl.keeper_id, kl.keeper_name,
                        kl.complete AS done,
                        tp.size AS topic_size
                    FROM Topics tp
                    INNER JOIN KeepersLists kl ON kl.topic_id = tp.id
                    WHERE tp.forum_id = ?
                )
                GROUP BY keeper_id, keeper_name
                ORDER BY 3 DESC
            ',
            [$forum->id],
            true,
            PDO::FETCH_ASSOC | PDO::FETCH_UNIQUE
        );

        if (empty($values)) {
            $notice_pattern = 'Notice: В БД отсутствуют данные о хранимом другими хранителями в подразделе [(%d) %s]. ' .
                'Возможно, нужно выполнить обновление сведений.';
            Log::append(sprintf($notice_pattern, $forum->id, $forum->name));

            $values = [];
        }

        return $values;
    }

    /**
     * Найти в БД хранимое пользователем в указанных подразделах.
     *
     * @throws Exception
     */
    public function fillStoredValues(?int $forumId = null): void
    {
        if (null !== $forumId) {
            $forumIds = [$forumId];
        } else {
            $forumIds = $this->forums;
            sort($forumIds);
        }

        $client_exclude = str_repeat('?,', count($this->excludeClientsIDs) - 1) . '?';
        $include_forums = str_repeat('?,', count($forumIds) - 1) . '?';

        // Вытаскиваем из базы хранимое.
        $values = Db::query_database(
            "SELECT
                forum_id,
                SUM(CASE WHEN done = 1              THEN 1 ELSE 0 END) keep_count,
                SUM(CASE WHEN done = 1 AND av <= 10 THEN 1 ELSE 0 END) less10_count,
                SUM(CASE WHEN done = 1 AND av >  10 THEN 1 ELSE 0 END) more10_count,
                SUM(CASE WHEN done < 1              THEN 1 ELSE 0 END) dl_count,
                SUM(CASE WHEN done = 1              THEN topic_size ELSE 0 END) keep_size,
                SUM(CASE WHEN done = 1 AND av <= 10 THEN topic_size ELSE 0 END) less10_size,
                SUM(CASE WHEN done = 1 AND av >  10 THEN topic_size ELSE 0 END) more10_size,
                SUM(CASE WHEN done < 1              THEN topic_size ELSE 0 END) dl_size
            FROM (
                SELECT
                    tp.forum_id,
                    (tp.seeders * 1.0 / tp.seeders_updates_today) av,
                    tp.size topic_size,
                    tr.done
                FROM Topics tp
                INNER JOIN (
                    SELECT info_hash, MAX(done) done
                    FROM Torrents
                    WHERE error = 0 AND client_id NOT IN ($client_exclude)
                    GROUP BY info_hash
                ) tr ON tp.info_hash = tr.info_hash
                WHERE tp.forum_id IN ($include_forums)
            )
            GROUP BY forum_id",
            array_merge($this->excludeClientsIDs, $forumIds),
            true,
            PDO::FETCH_ASSOC | PDO::FETCH_UNIQUE
        );

        if (empty($values)) {
            throw new Exception(sprintf(
                'Error: В БД отсутствуют данные о раздачах хранимых подразделов %s. ' .
                'Возможно, нужно выполнить обновление сведений.',
                implode(',', $forumIds)
            ));
        }

        $this->stored = $values;
    }

    /**
     * Найти хранимое пользователем в указанном подразделе.
     *
     * @throws Exception
     */
    private function getStoredForumValues(int $forumId): array
    {
        $values = $this->stored[$forumId] ?? [];

        if (empty($values)) {
            throw new Exception(sprintf(
                'Notice: В БД отсутствуют данные о раздачах хранимого подраздела %s. ' .
                'Возможно, нужно выполнить обновление сведений.',
                $forumId
            ));
        }

        return $values;
    }

    /**
     * Найти в БД список хранимых раздач подраздела.
     *
     * @throws Exception
     */
    public function getStoredForumTopics(int $forum_id): array
    {
        // Получение данных о раздачах подраздела.
        $client_exclude = str_repeat('?,', count($this->excludeClientsIDs) - 1) . '?';

        $topics = Db::query_database(
            "
                SELECT
                    tp.id,
                    tp.info_hash topic_hash,
                    tp.forum_id,
                    tp.name topic_name,
                    tp.size topic_size,
                    tp.status topic_status,
                    MAX(tr.done) AS done
                FROM Topics tp
                INNER JOIN Torrents tr ON tr.info_hash = tp.info_hash
                WHERE tp.forum_id = ? AND tr.error = 0 AND tr.client_id NOT IN ($client_exclude)
                GROUP BY tp.id, tp.info_hash, tp.forum_id, tp.name, tp.size, tp.status
                ORDER BY tp.id
            ",
            array_merge([$forum_id], $this->excludeClientsIDs),
            true
        );

        if (empty($topics)) {
            throw new Exception("Error: Не получены данные о хранимых раздачах для подраздела № $forum_id");
        }

        return $topics;
    }

    /**
     * @throws Exception
     */
    private function getLastUpdateTime(): void
    {
        $updateTime = LastUpdate::getTime(UpdateMark::FULL_UPDATE->value);
        if ($updateTime === 0) {
            $update = LastUpdate::checkFullUpdate(
                $this->config,
                CreationMode::UI !== $this->mode
            );

            if ($update->getLastCheckStatus() === UpdateStatus::MISSED) {
                $update->addLog();
                throw new Exception('Сформировать отчёт невозможно. ' .
                    'Данные в локальной БД неполные. ' .
                    'Выполните полное обновление данных и попробуйте снова.');
            }

            $updateTime = $update->getLastCheckUpdateTime();
        }

        $this->updateTime = $updateTime;
    }

    private function getFormattedUpdateTime(): string
    {
        return sprintf('Актуально на: [color=darkblue][b]%s[/b][/color]', date('d.m.Y', $this->updateTime));
    }

    private function bytes(int $bytes): string
    {
        return Helper::convertBytes($bytes);
    }

    private function boldBytes(int $bytes): string
    {
        $formatted = $this->bytes($bytes);

        return vsprintf("[b]%s[/b] %s", explode(" ", $formatted));
    }

    /**
     * Исключаемые подразделы и торрент-клиенты.
     */
    private function setExcluded(): void
    {
        $cfg_reports = $this->config['reports'];

        if (!empty($cfg_reports['exclude_clients_ids'])) {
            Log::append("Notice: Из отчётов исключены торрент клиенты: {$cfg_reports['exclude_clients_ids']}");
        }
        if (!empty($cfg_reports['exclude_forums_ids'])) {
            Log::append("Notice: Из отчётов исключены подразделы: {$cfg_reports['exclude_forums_ids']}");
        }

        $this->excludeForumsIDs  = explode(',', $cfg_reports['exclude_forums_ids']);
        $this->excludeClientsIDs = explode(',', $cfg_reports['exclude_clients_ids']);
    }

    /**
     * Найти данные хранимых подразделов.
     *
     * @throws Exception
     */
    private function setForums(): void
    {
        // Идентификаторы хранимых подразделов.
        $forums = array_keys($this->config['subsections'] ?? []);
        if (!count($forums)) {
            throw new Exception('Error: Отсутствуют хранимые подразделы. Проверьте настройки.');
        }

        $this->forums = $forums;
    }
}
