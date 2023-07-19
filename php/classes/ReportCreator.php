<?php

/**
 * Объект для создания новый отчётов.
 */
class ReportCreator
{
    /** Ид подраздела полного обновления сведений */
    private const FULL_UPDATE = 7777;

    private array $config;
    private $webtlo;
    private $reports;

    /** Исключённые из отчётов подразделы */
    private array $excludeForumsIDs = [];
    /** Исключённые из отчётов торрент-клиенты */
    private array $excludeClientsIDs = [];

    private ?int $updateTime = null;

    private int $userId = 0;
    private string $userName = '';

    private string $mode = 'cron';

    private string $implodeGlue = '[br]';
    private string $topicGlue = '';

    private array $topicParams = [];

    private array $keeperKeys = [
        'keep_count', // Общее кол-во хранимых раздач
        'keep_size',  // Общий вес хранимых раздач
        'dl_count',   // Кол-во скачиваемых раздач
        'dl_size',    // Вес скачиваемых раздач
    ];

    public function __construct(
        array $config,
        $webtlo,
        $reports
    ) {
        $this->config  = $config;
        $this->webtlo  = $webtlo;
        $this->reports = $reports;


        $this->setUser();
        $this->setExcluded();
        $this->getLastUpdateTime();
    }

    /**
     * Сводный отчёт.
     *
     * @throws Exception
     */
    public function getSummaryReport(): string
    {
        $subsections = $this->config['subsections'];

        // идентификаторы хранимых подразделов
        $forums_ids = array_keys($subsections);

        // вытаскиваем из базы хранимое
        $stored = $this->getStoredForumsValues($forums_ids);
        $total  = $this->calcSummary($stored);

        $subsectionPattern = '[url=viewtopic.php?t=%s][u]%s[/u][/url] — %s шт. (%s)';
        // разбираем хранимое
        $savedSubsections = [];
        foreach ($subsections as $forum_id => $subsection) {
            if (!isset($stored[$forum_id])) {
                continue;
            }
            // исключаем подразделы
            if (in_array($forum_id, $this->excludeForumsIDs)) {
                Log::append("Notice: Из сводного отчёта исключен подраздел № $forum_id");
                continue;
            }

            // ищем тему со списками
            $topic_id = $this->getForumTopicId($forum_id, $subsection['na']);
            $topic_id = empty($topic_id) ? 'NaN' : $topic_id;
            // инфа о подразделе в сводный
            $savedSubsections[] = sprintf(
                $subsectionPattern,
                $topic_id,
                $subsection['na'],
                $stored[$forum_id]['keep_count'],
                $this->bytes($stored[$forum_id]['keep_size'])
            );

            unset($forum_id, $subsection, $topic_id);
        }
        unset($stored);

        // формируем сводный отчёт
        $summary = [];
        $summary[] = $this->getFormattedUpdateTime();
        $summary[] = '';
        $this->prepareSummaryHeader($summary, $total);
        $summary[] = '';
        $summary[] = $this->webtlo->version_line_url;
        $summary[] = '[hr]';

        return implode($this->implodeGlue, array_merge($summary, $savedSubsections));
    }

    /**
     * Собрать отчёт по заданному разделу.
     *
     * @throws Exception
     */
    public function getForumReport($forum_id): array
    {
        // исключаем подразделы
        if (in_array($forum_id, $this->excludeForumsIDs)) {
            throw new Exception("Notice: Из отчёта исключен подраздел № $forum_id");
        }

        $forum = $this->getForumParams($forum_id);
        // Вытаскиваем из базы хранимое.
        $stored = $this->getStoredForumsValues([$forum_id]);
        $userStored = $stored[$forum_id];

        $topicHeader = $this->createTopicFirstMessage($forum, $userStored);

        // Создаём заголовок отчёта по подразделу.
        $header = [];
        $header[] = $this->getFormattedUpdateTime();
        $this->prepareSummaryHeader($header, $userStored);
        $header[] = $this->webtlo->version_line;
        $messageHeader = implode($this->implodeGlue, $header);
        unset($header);

        // const & pattern.
        $message_length_max = 119000;
        $pattern_spoiler = '[spoiler="№№ %s — %s"][list=1]<br />[font=mono2][*=%s]%s<br />[/font][/list][/spoiler]';
        $spoiler_length  = mb_strlen($pattern_spoiler, 'UTF-8');

        // Найти раздачи в БД.
        $topics = $this->getStoredForumTopics($forum_id);
        // Количество раздач.
        $topics_count = count($topics);

        $topicMessages = [];
        // формируем списки
        foreach ($topics as $topic) {
            if (empty($tmp)) {
                $tmp['firstTopic'] = 1;
                $tmp['messageLength'] = 0;
                $tmp['topicCounter'] = 0;
            }
            $topicUrl = $this->prepareTopicUrl($topic);

            $tmp['topicCounter']++;
            $tmp['topicLines'][] = $topicUrl;

            $topicLineLength = mb_strlen($topicUrl, 'UTF-8');
            $tmp['messageLength'] += $topicLineLength;

            // Режем раздачи на сообщения.
            $fullLength = $tmp['messageLength'] + $topicLineLength;
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
                $tmp['firstTopic'] = $tmp['topicCounter'] + 1;
                $tmp['messageLength'] = 0;
                unset($tmp['topicLines']);
            }
        }
        unset($topics, $topics_count);

        if (empty($topicMessages)) {
            throw new Exception("Error: Не удалось сформировать список хранимого для подраздела № $forum_id");
        }

        // В первое сообщение дописываем заголовок.
        $topicMessages[0] = $messageHeader . $this->implodeGlue . $topicMessages[0];

        return [
            'header'   => $topicHeader,
            'messages' => $topicMessages,
        ];
    }

    /**
     * Собрать шаблон заголовка сообщения с суммарными значениями.
     */
    private function prepareSummaryHeader(array &$rows, array $val): void
    {
        $rows[] = sprintf('Всего хранимых раздач: [b]%s[/b] шт. (%s)', $val['keep_count'], $this->boldBytes($val['keep_size']));
        if ($val['more10_count'] > 0) {
            $split_pattern = '- из них раздач %s10 сидов: [b]%d[/b] шт. (%s)';
            $rows[] = sprintf($split_pattern, '&#8804;', $val['less10_count'], $this->boldBytes($val['less10_size']));
            $rows[] = sprintf($split_pattern, '>',       $val['more10_count'], $this->boldBytes($val['more10_size']));
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
        if ($this->mode === 'UI') {
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
        if ($this->mode === 'cron') {
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
    private function createTopicFirstMessage($forum, $userStored): string
    {
        $stored = $this->getKeepersPostedReports($forum->id, $forum->name);

        // Добавим себя в список хранителей.
        $userStored['keeper_name'] = $this->userName;
        $stored[$this->userId] = $userStored;

        // Значения общего хранимого.
        $total = [];
        foreach ($this->keeperKeys as $key) {
            $total[$key] = array_sum(array_column($stored, $key));
            unset($key);
        }

        // Создаём список хранителей.
        $count_keepers = 0;
        $header = $keepers = [];
        $keepers[] = '[list=1]';
        if (count($stored)) {
            uasort($stored, fn ($a, $b) => $b['keep_count'] <=> $a['keep_count']);

            $pattern_keeper = '[*][url=profile.php?mode=viewprofile&u=%d][u][color=#006699]%s[/u][/color][/url] [color=gray]~>[/color] %s шт. [color=gray]~>[/color] %s';
            foreach ($stored as $keeper_id => $values) {
                if (!$values['keep_count']) continue;

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
        unset($stored);
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

        // вставляем общее хранимое в шапку
        return implode($this->implodeGlue, array_merge($header, $keepers));
    }

    /**
     * Найти списки других хранителей указанного подраздела.
     */
    private function getKeepersPostedReports(int $forum_id, string $forum_name): array
    {
        // ищем тему со списками
        $topic_id = $this->getForumTopicId($forum_id, $forum_name);

        if (empty($topic_id)) {
            Log::append("Error: Не удалось найти тему со списком для подраздела №$forum_id");
            return [];
        }

        // сканируем имеющиеся списки
        $keepers = $this->reports->scanning_viewtopic($topic_id);
        if ($keepers === false) {
            Log::append("Error: Не удалось получить данные из темы со списками для подраздела №$forum_id, тема $topic_id");
            return [];
        }

        $topicParams = [
            'topicId'        => $topic_id,
            'authorPostId'   => 0,
            'authorNickName' => '',
            'postList'       => [],
        ];

        $stored = [];
        // разбираем инфу, полученную из списков
        foreach ($keepers as $index => $keeper) {
            $keeper_id   = $keeper['user_id'];
            $keeper_name = $keeper['nickname'];

            if ($index === 0) {
                $topicParams['authorPostId']   = $keeper['post_id'];
                $topicParams['authorId']       = $keeper_id;
                $topicParams['authorNickName'] = $keeper_name;
                continue;
            }

            // array( 'post_id' => 4444444, 'nickname' => 'user', 'topics_ids' => array( 0,1,2 ) )
            if (strcasecmp($this->userId, $keeper_id) === 0) {
                $topicParams['postList'][] = $keeper['post_id'];
                continue;
            }
            // Skip post from user StatsBot
            if ($keeper_name === 'StatsBot') {
                continue;
            }
            if (empty($keeper['topics_ids'])) {
                continue;
            }

            $stored[$keeper_id] = $stored[$keeper_id] ?? array_fill_keys($this->keeperKeys, 0);
            $stored[$keeper_id]['keeper_name'] = $keeper_name;

            // считаем сообщения других хранителей в подразделе
            // index[0,1] => topics_ids[1,2,3,4], где 0 - раздача скачивается, 1 - скачана.
            foreach ($keeper['topics_ids'] as $done => $keeperTopicsIDs) {
                $topicsIdChunks = array_chunk($keeperTopicsIDs, 500);
                foreach ($topicsIdChunks as $topicIds) {
                    $in = str_repeat('?,', count($topicIds) - 1) . '?';
                    $values = Db::query_database_row(
                        "SELECT COUNT(), SUM(si)
                        FROM Topics
                        WHERE id IN ($in) AND ss = ? AND rg < CAST(? as INTEGER)",
                        array_merge($topicIds, [$forum_id, $keeper['posted']]),
                        true,
                        PDO::FETCH_NUM
                    );
                    if ($done == 1) {
                        $stored[$keeper_id]['keep_count'] += $values[0];
                        $stored[$keeper_id]['keep_size']  += $values[1];
                    } else {
                        $stored[$keeper_id]['dl_count'] += $values[0];
                        $stored[$keeper_id]['dl_size']  += $values[1];
                    }
                    unset($in, $values);
                }
                unset($done, $keeperTopicsIDs, $topicIds);
            }
            unset($keeper, $keeper_id, $keeper_name);
        }

        // Сохраним данные о теме со списками.
        $this->saveTopicParams($forum_id, $topicParams);

        return $stored;
    }

    /**
     * Сохраним данные о теме со списками заданного подраздела.
     */
    private function saveTopicParams(int $forum_id, array $topicParams): void
    {
        $this->topicParams[$forum_id] = $topicParams;
    }

    public function getTopicSavedParams(int $forum_id): array
    {
        return $this->topicParams[$forum_id] ?? [];
    }

    /**
     * Найти в БД суммарные данные о хранимых раздачах подразделов.
     *
     * @throws Exception
     */
    private function getStoredForumsValues(array $forums_ids): array
    {
        sort($forums_ids);

        $client_exclude = str_repeat('?,', count($this->excludeClientsIDs) - 1) . '?';
        $in_forums      = str_repeat('?,', count($forums_ids) - 1) . '?';

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
                    tp.ss forum_id,
                    (tp.se * 1.0 / tp.qt) av,
                    tp.si AS topic_size,
                    tr.done
                FROM Topics tp
                INNER JOIN (
                    SELECT info_hash, MAX(done) done
                    FROM Torrents
                    WHERE error = 0 AND client_id NOT IN ($client_exclude)
                    GROUP BY info_hash
                ) tr ON tp.hs = tr.info_hash
                WHERE tp.ss IN ($in_forums)
            )
            GROUP BY forum_id",
            array_merge($this->excludeClientsIDs, $forums_ids),
            true,
            PDO::FETCH_ASSOC | PDO::FETCH_UNIQUE
        );

        if (empty($values)) {
            throw new Exception(sprintf(
                "Error: Не получены данные о хранимых раздачах разделов %s",
                implode(',', $forums_ids)
            ));
        }

        return $values;
    }

    /**
     * Найти в БД список хранимых раздач подраздела.
     *
     * @throws Exception
     */
    private function getStoredForumTopics(int $forum_id): array
    {
        // Получение данных о раздачах подраздела.
        $client_exclude = str_repeat('?,', count($this->excludeClientsIDs) - 1) . '?';

        $topics = Db::query_database(
            "SELECT
                tp.id,
                tp.hs topic_hash,
                tp.ss forum_id,
                tp.na topic_name,
                tp.si topic_size,
                tp.st topic_status,
                tr.done
            FROM Topics tp
            INNER JOIN (
                SELECT
                    info_hash,
                    MAX(done) done
                FROM Torrents tr
                WHERE error = 0 AND client_id NOT IN ($client_exclude)
                GROUP BY info_hash
            ) tr ON tp.hs = tr.info_hash
            WHERE tp.ss = ?",
            array_merge($this->excludeClientsIDs, [$forum_id]),
            true
        );

        if (empty($topics)) {
            throw new Exception("Error: Не получены данные о хранимых раздачах для подраздела № $forum_id");
        }

        // сортировка раздач
        return natsort_field($topics, 'id');
    }

    /**
     * @throws Exception
     */
    private function getLastUpdateTime(): void
    {
        $updateTime = get_last_update_time(self::FULL_UPDATE);
        if ($updateTime === 0) {
            throw new Exception('Сформировать отчёт невозможно. ' .
                'Выполните полное обновление данных и попробуйте снова.');
        }

        $this->updateTime = $updateTime;
    }

    /**
     * Получение параметров подраздела.
     *
     * @throws Exception
     */
    private function getForumParams(int $forum_id): object
    {
        $result = Db::query_database_row(
            "SELECT id, na name, qt quantity, si size FROM Forums WHERE id = ?",
            [$forum_id],
            true
        );

        if (empty($result)) {
            throw new Exception("Error: Не получены данные о хранимом подразделе № $forum_id");
        }
        return (object)$result;
    }

    /**
     * Поиск ид темы с отчётами, по ид хранимого подраздела.
     */
    private function getForumTopicId(int $forumId, string $forumName): ?int
    {
        $topicId = $this->reports->search_topic_id($forumName);

        return $topicId ?? null;
    }

    private function getFormattedUpdateTime(): string
    {
        return sprintf('Актуально на: [color=darkblue][b]%s[/b][/color]', date('d.m.Y', $this->updateTime));
    }

    private function bytes(int $bytes): string
    {
        return convert_bytes($bytes);
    }

    private function boldBytes(int $bytes): string
    {
        $formatted = $this->bytes($bytes);
        return vsprintf("[b]%s[/b] %s", explode(" ", $formatted));
    }

    private function setUser(): void
    {
        $this->userId   = $this->config['user_id'];
        $this->userName = $this->config['tracker_login'];
    }

    /**
     * Исключаемые подразделы и торрент-клиенты.
     */
    private function setExcluded(): void
    {
        $cfg_reports = $this->config['reports'];

        if (!empty($cfg_reports['exclude_clients_ids'])) {
            Log::append("Notice: Из отчёта исключены торрент клиенты: {$cfg_reports['exclude_clients_ids']}");
        }

        $this->excludeForumsIDs  = explode(',', $cfg_reports['exclude_forums_ids']);
        $this->excludeClientsIDs = explode(',', $cfg_reports['exclude_clients_ids']);
    }

    public function setMode(string $mode): void
    {
        if ($mode === 'UI') {
            $this->mode = $mode;
            $this->implodeGlue = '<br />';
            $this->topicGlue   = '<br />';
        }
    }
}
