<?php

namespace KeepersTeam\Webtlo\Module;

use Db;
use PDO;
use Log;
use Exception;
use KeepersTeam\Webtlo\DTO\ForumObject;
use KeepersTeam\Webtlo\Enum\UpdateMark;
use KeepersTeam\Webtlo\Enum\UpdateStatus;

/**
 * Объект для создания новый отчётов.
 */
final class ReportCreator
{
    /** Ид темы для публикации сводных отчётов */
    public const SUMMARY_FORUM = 4275633;

    private array $config;
    private object $webtlo;

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

    private array $keeperKeys = [
        'keep_count', // Общее кол-во хранимых раздач
        'keep_size',  // Общий вес хранимых раздач
        'dl_count',   // Кол-во скачиваемых раздач
        'dl_size',    // Вес скачиваемых раздач
    ];

    /**
     * @throws Exception
     */
    public function __construct(
        array  $config,
        object $webtlo
    ) {
        $this->config = $config;
        $this->webtlo = $webtlo;

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
        // идентификаторы хранимых подразделов
        $forums_ids = array_keys($this->config['subsections'] ?? []);
        if (!count($forums_ids)) {
            throw new Exception('Error: Отсутствуют хранимые подразделы. Проверьте настройки.');
        }

        // вытаскиваем из базы хранимое
        $stored = $this->getStoredForumsValues($forums_ids);
        $total  = $this->calcSummary($stored);

        // Строка хранимого подраздела.
        // Первая ссылка в тему со списками, вторая на своё сообщение.
        $subsectionPattern = '[*][url=viewtopic.php?t=%s][u]%s[/u][/url] — [url=viewtopic.php?p=%s][u]%s шт. (%s)[/u][/url]';
        // разбираем хранимое
        $savedSubsections = [];
        foreach ($forums_ids as $forum_id) {
            if (!isset($stored[$forum_id])) {
                continue;
            }
            // исключаем подразделы
            if (in_array($forum_id, $this->excludeForumsIDs)) {
                continue;
            }

            $forum = Forums::getForum($forum_id);

            $topic_id = $forum->topic_id ?: 'NaN';
            $post_id  = $forum->post_ids[0] ?? 'NaN';

            // инфа о подразделе в сводный
            $savedSubsections[] = sprintf(
                $subsectionPattern,
                $topic_id,
                $forum->name,
                $post_id,
                $stored[$forum_id]['keep_count'],
                $this->bytes($stored[$forum_id]['keep_size'])
            );

            unset($forum_id, $topic_id);
        }
        unset($stored);

        // формируем сводный отчёт
        $summary   = [];
        $summary[] = $this->getFormattedUpdateTime();
        $summary[] = '';
        $summary[] = sprintf('Всего хранимых подразделов: [b]%s[/b] шт.', count($savedSubsections));
        $this->prepareSummaryHeader($summary, $total);
        $summary[] = '';
        $summary[] = $this->webtlo->version_line_url;
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
        $stored = $this->getStoredForumsValues([$forum->id]);
        $userStored = $stored[$forum->id];

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
        array_walk($messages, function (&$a, $b) {
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
        $stored = $this->getKeepersStoredReports($forum);

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
                        kl.complete as done,
                        tp.si AS topic_size
                    FROM Topics tp
                    INNER JOIN KeepersLists kl ON kl.topic_id = tp.id
                    WHERE tp.ss = ?
                )
                GROUP BY keeper_id, keeper_name
                ORDER BY 3 DESC
            ',
            [$forum->id],
            true,
            PDO::FETCH_ASSOC | PDO::FETCH_UNIQUE
        );

        if (empty($values)) {
            $notice_pattern = 'Notice: В БД отсутствуют данные о хранимом другими хранителями в подразделе [(%d) %s]. '.
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
                'Error: В БД отсутствуют данные о раздачах хранимых подразделов %s. ' .
                    'Возможно, нужно выполнить обновление сведений.',
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
            WHERE tp.ss = ?
            ORDER BY tp.id",
            array_merge($this->excludeClientsIDs, [$forum_id]),
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
            $update = LastUpdate::checkFullUpdate($this->config);
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
            Log::append("Notice: Из отчётов исключены торрент клиенты: {$cfg_reports['exclude_clients_ids']}");
        }
        if (!empty($cfg_reports['exclude_forums_ids'])) {
            Log::append("Notice: Из отчётов исключены подразделы: {$cfg_reports['exclude_forums_ids']}");
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
