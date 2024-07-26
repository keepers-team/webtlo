<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\Forum\Report;

use DateTimeImmutable;
use Exception;
use KeepersTeam\Webtlo\Config\ReportSend;
use KeepersTeam\Webtlo\DB;
use KeepersTeam\Webtlo\DTO\ForumObject;
use KeepersTeam\Webtlo\DTO\KeysObject;
use KeepersTeam\Webtlo\Enum\UpdateMark;
use KeepersTeam\Webtlo\Enum\UpdateStatus;
use KeepersTeam\Webtlo\External\ApiReport\V1\ReportForumResponse;
use KeepersTeam\Webtlo\Helper;
use KeepersTeam\Webtlo\Module\Forums;
use KeepersTeam\Webtlo\Settings;
use KeepersTeam\Webtlo\Tables\UpdateTime;
use KeepersTeam\Webtlo\WebTLO;
use PDO;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Объект для создания новых отчётов.
 */
final class Creator
{
    /** @var int[] */
    public ?array $forums = null;

    private ?DateTimeImmutable $updateTime = null;

    private CreationMode $mode = CreationMode::CRON;

    /** @var ?string[] Сводный отчёт. */
    private ?array $summary = null;

    /** @var ?array<string, mixed> */
    private ?array $telemetry = null;

    private string $implodeGlue = '[br]';
    private string $topicGlue   = '';

    /**
     * Суммарные значения по каждому подразделу. Для заголовков.
     *
     * @var ?array<string, mixed>[]
     */
    private ?array $stored = null;

    /** @var array<int, mixed> Хранимые раздачи по каждому подразделу. */
    private array $cache = [];

    private ?ReportForumResponse $reportTopics = null;

    public function __construct(
        private readonly DB              $db,
        private readonly Settings        $settings,
        private readonly ReportSend      $reportSend,
        private readonly UpdateTime      $updateTest,
        private readonly WebTLO          $webtlo,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Сводный отчёт.
     *
     * @throws Exception
     */
    public function getSummaryReport(bool $withTelemetry = false): string
    {
        $summary = $this->collectSummaryInfo();

        // Проверяем возможность добавить в сводный отчёт данные о настройках.
        if ($withTelemetry) {
            $shared = $this->getConfigTelemetry();
            if (!empty($shared)) {
                $summary[] = '[hr]';
                $summary[] = '[spoiler="Настройки Web-TLO"][pre]';
                $summary[] = json_encode($shared, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
                $summary[] = '[/pre][/spoiler]';
            }
        }

        return implode($this->implodeGlue, $summary);
    }

    public function getForumCount(): int
    {
        if (null === $this->forums) {
            return 0;
        }

        return count($this->forums) - count($this->reportSend->excludedSubForums);
    }

    /**
     * @return int[]
     */
    public function getForums(): array
    {
        if (null === $this->forums) {
            throw new RuntimeException('No forums found');
        }

        return $this->forums;
    }

    public function isForumExcluded(int $forumId): bool
    {
        return in_array($forumId, $this->reportSend->excludedSubForums, true);
    }

    /**
     * Собрать отчёт по заданному разделу.
     *
     * @param ForumObject $forum
     * @return string[]
     */
    public function getForumReport(ForumObject $forum): array
    {
        // исключаем подразделы
        if ($this->isForumExcluded($forum->id)) {
            throw new RuntimeException("Из отчёта исключен подраздел № $forum->id");
        }

        // Вытаскиваем из базы хранимое.
        $userStored = $this->getStoredForumValues($forum->id);

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
            throw new RuntimeException("Не удалось сформировать список хранимого для подраздела № $forum->id");
        }

        // В первое сообщение дописываем заголовок.
        $topicMessages[0] = $messageHeader . $this->implodeGlue . $topicMessages[0];

        return $topicMessages;
    }

    /**
     * @param ?CreationMode $mode
     */
    public function initConfig(?CreationMode $mode = null): void
    {
        if (null !== $mode) {
            $this->mode = $mode;

            $this->implodeGlue = '<br />';
            $this->topicGlue   = '<br />';
        }

        // Проверяем настройки.
        $this->setForums();
        $this->checkExcluded();
        $this->getLastUpdateTime();
    }

    /**
     * @return array{}|array<string, mixed>
     */
    public function getConfigTelemetry(): array
    {
        if (null !== $this->telemetry) {
            return $this->telemetry;
        }

        $config = $this->settings->populate();

        // Если отправка отключена в настройках, то ничего не собираем.
        if (!$this->reportSend->sendTelemetry) {
            return $this->telemetry = [];
        }

        $shared = [
            'software' => $this->webtlo->getSoftwareInfo(),
            'proxy'    => [
                'activate_forum'  => (bool)$config['proxy_activate_forum'],
                'activate_api'    => (bool)$config['proxy_activate_api'],
                'activate_report' => (bool)$config['proxy_activate_report'],
            ],
        ];

        // Количество и тип используемых торрент клиентов.
        $clients = array_filter($config['clients'], fn($el) => !$el['exclude']);

        // Тип и количество используемых торрент-клиентов.
        $distribution = array_count_values(array_map(fn($el) => $el['cl'], $clients));

        // Количество раздач в используемых торрент-клиентах.
        $clientTopics = [];
        foreach ($this->getClientsTopics() as $clientId => $topics) {
            if (!empty($clients[$clientId])) {
                $clientName = sprintf('%s-%d', $clients[$clientId]['cl'], $clientId);
                $clientTopics[$clientName] = array_filter($topics);
            }
        }

        // Данные о торрент-клиентах.
        $shared['clients'] = [
            'distribution' => $distribution,
            'topics'       => $clientTopics,
        ];

        // Регулировка по подразделам.
        $subsections = array_filter($config['subsections'], fn($el) => !empty($el['control_peers']));
        $subsections = array_map(fn($el) => (int)$el['control_peers'], $subsections);

        ksort($subsections);

        // Параметры регулировки.
        $shared['control'] = [
            'enabled'     => (bool)$config['automation']['control'],
            'peers'       => (int)$config['topics_control']['peers'],
            'keepers'     => (int)$config['topics_control']['keepers'],
            'subsections' => $subsections,
        ];

        // Параметры отправки отчётов.
        $shared['reports'] = [
            'enabled'             => (bool)$config['automation']['reports'],
            'send_report_api'     => (bool)$config['reports']['send_report_api'],
            'send_summary_report' => (bool)$config['reports']['send_summary_report'],
            'unset_other_forums'  => (bool)$config['reports']['unset_other_forums'],
            'unset_other_topics'  => (bool)$config['reports']['unset_other_topics'],
        ];

        // Локальные даты обновления сведений.
        $shared['markers'] = $this->updateTest->getMainMarkers()->getFormattedMarkers();

        return $this->telemetry = $shared;
    }

    /**
     * @return string[]
     * @throws Exception
     */
    private function collectSummaryInfo(): array
    {
        // Если данные уже собраны - возвращаем готовый набор.
        if (null !== $this->summary) {
            return $this->summary;
        }

        // Собираем данные для сводного отчёта.
        if (null === $this->stored) {
            $this->fillStoredValues();
        }
        if (null === $this->stored) {
            throw new RuntimeException('Нет данных для построения сводного отчёта.');
        }

        // Вытаскиваем из базы хранимое
        $total = $this->calcSummary($this->stored);

        $urlPattern = '[url=viewtopic.php?%s=%s][u]%s[/u][/url]';

        // Разбираем хранимое
        $savedSubsections = [];
        foreach ($this->forums as $forumId) {
            // Исключаем подразделы, согласно конфига.
            if ($this->isForumExcluded($forumId)) {
                continue;
            }

            $forumValues = $this->stored[$forumId] ?? [];
            if (!count($forumValues)) {
                continue;
            }

            $forum = Forums::getForum($forumId);

            $topicId = $this->getReportTopicId($forum);

            // Ссылка на тему с отчётами подраздела.
            $leftPart = $topicId !== null ?
                sprintf($urlPattern, 't', $topicId, $forum->name) :
                sprintf('[b]%s[/b]', $forum->name);

            // Ссылка на свой пост(отчёт) и количество + объём раздач.
            $rightPart = sprintf('%s шт. (%s)', $forumValues['keep_count'], $this->bytes($forumValues['keep_size']));

            // Записываем данные о подразделе в сводный отчёт.
            $savedSubsections[] = "[*]$leftPart - $rightPart";

            unset($forumId, $forumValues, $topicId, $leftPart, $rightPart);
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

        return $this->summary = [...$summary, '[list=1]', ...$savedSubsections, '[/list]'];
    }

    /**
     * Собрать заголовок сообщения с версией ПО.
     *
     * @param array<string, mixed> $userStored
     * @return string
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
     *
     * @param string[]             $rows
     * @param array<string, mixed> $val
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
     *
     * @param array<string, mixed> $topic
     * @return string
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
     * @return array<int, array<string, int>>
     */
    private function getClientsTopics(): array
    {
        $query = '
            SELECT client_id,
                   COUNT(1) AS topics,
                   SUM(CASE WHEN done = 1 THEN 1 ELSE 0 END) AS done,
                   SUM(CASE WHEN done < 1 THEN 1 ELSE 0 END) AS downloading,
                   SUM(paused) AS paused, SUM(error) AS error
            FROM Torrents t
            GROUP BY client_id
            ORDER BY topics DESC
        ';

        return $this->db->query($query, [], PDO::FETCH_ASSOC | PDO::FETCH_UNIQUE);
    }

    /**
     * Собираем сообщения для UI.
     *
     * @param string[] $messages
     * @return string
     */
    public function prepareReportsMessages(array $messages): string
    {
        array_walk($messages, function(&$a, $b) {
            $b++;
            $a = sprintf('<h3>Сообщение %d</h3><div>%s</div>', $b, $a);
        });

        return sprintf('<div class="report_message">%s</div>', implode('', $messages));
    }

    /**
     * Посчитать сумму хранимого.
     *
     * @param array<string, mixed> $stored
     * @return array<string, mixed>
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
            if (in_array((int)$forum_id, $this->reportSend->excludedSubForums, true)) {
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
     * Найти в БД хранимое пользователем в указанных подразделах.
     */
    public function fillStoredValues(?int $forumId = null): void
    {
        if (null !== $forumId) {
            $forumIds = [$forumId];
        } else {
            $forumIds = $this->forums;
            sort($forumIds);
        }

        $includeForums  = KeysObject::create($forumIds);
        $excludeClients = KeysObject::create($this->reportSend->excludedClients);

        // Вытаскиваем из базы хранимое.
        $values = $this->db->query(
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
                    WHERE error = 0 AND client_id NOT IN ($excludeClients->keys)
                    GROUP BY info_hash
                ) tr ON tp.info_hash = tr.info_hash
                WHERE tp.forum_id IN ($includeForums->keys)
            )
            GROUP BY forum_id",
            array_merge($excludeClients->values, $includeForums->values),
            PDO::FETCH_ASSOC | PDO::FETCH_UNIQUE
        );

        if (empty($values)) {
            $this->logger->warning(
                'В БД отсутствуют данные о раздачах хранимых подразделов {forums}. Возможно, нужно выполнить обновление сведений.',
                ['forums' => implode(', ', $forumIds)]
            );

            return;
        }

        $this->stored = $values;
    }

    /**
     * Найти хранимое пользователем в указанном подразделе.
     *
     * @param int $forumId
     * @return array<string, mixed>
     */
    private function getStoredForumValues(int $forumId): array
    {
        $values = $this->stored[$forumId] ?? [];

        if (empty($values)) {
            throw new RuntimeException(
                "В БД отсутствуют данные о раздачах хранимого подраздела $forumId. Возможно, нужно выполнить обновление сведений."
            );
        }

        return $values;
    }

    /**
     * Найти в БД список хранимых раздач подраздела.
     *
     * @param int $forum_id
     * @return array<string, mixed>[]
     */
    public function getStoredForumTopics(int $forum_id): array
    {
        if (!empty($this->cache[$forum_id])) {
            return $this->cache[$forum_id];
        }

        // Получение данных о раздачах подраздела.
        $excludeClients = KeysObject::create($this->reportSend->excludedClients);

        $topics = $this->db->query(
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
                WHERE tp.forum_id = ? AND tr.error = 0 AND tr.client_id NOT IN ($excludeClients->keys)
                GROUP BY tp.id, tp.info_hash, tp.forum_id, tp.name, tp.size, tp.status
                ORDER BY tp.id
            ",
            array_merge([$forum_id], $excludeClients->values),
        );

        if (empty($topics)) {
            throw new RuntimeException("Не получены данные о хранимых раздачах для подраздела № $forum_id");
        }

        $this->cache[$forum_id] = $topics;

        return $topics;
    }

    public function setForumTopics(ReportForumResponse $reportTopics): void
    {
        $this->reportTopics = $reportTopics;
    }

    private function getReportTopicId(ForumObject $forum): ?int
    {
        return $this->reportTopics?->getReportTopicId($forum->id);
    }

    public function clearCache(int $forumId): void
    {
        $this->cache[$forumId] = null;
    }

    private function getLastUpdateTime(): void
    {
        $lastTimestamp = $this->updateTest->getMarkerTimestamp(UpdateMark::FULL_UPDATE->value);

        if ($lastTimestamp === 0) {
            $update = $this->updateTest->checkFullUpdate(
                markers         : $this->forums,
                daysUpdateExpire: $this->reportSend->daysUpdateExpire,
                checkForum      : CreationMode::UI !== $this->mode
            );

            if ($update->getLastCheckStatus() === UpdateStatus::MISSED) {
                $update->addLogRecord($this->logger);

                throw new RuntimeException(
                    'Сформировать отчёт невозможно. ' .
                    'Данные в локальной БД неполные. ' .
                    'Выполните полное обновление данных и попробуйте снова.'
                );
            }

            $this->updateTime = $update->getMinUpdate();
        } else {
            $this->updateTime = Helper::makeDateTime($lastTimestamp);
        }
    }

    public function setFullUpdateTime(DateTimeImmutable $updateTime): void
    {
        $this->updateTime = $updateTime;
    }

    private function getFormattedUpdateTime(): string
    {
        $dateString = $this->updateTime === null ? 'никогда' : $this->updateTime->format('d.m.Y');

        return sprintf('Актуально на: [color=darkblue][b]%s[/b][/color]', $dateString);
    }

    /**
     * Найти данные хранимых подразделов.
     */
    private function setForums(): void
    {
        $config = $this->settings->populate();

        if (empty($config['subsections'])) {
            throw new RuntimeException('Отсутствуют хранимые подразделы. Проверьте настройки.');
        }

        // Идентификаторы хранимых подразделов.
        $forums = array_keys($config['subsections']);

        $this->forums = array_map('intval', $forums);
    }

    /**
     * Исключаемые подразделы и торрент-клиенты.
     */
    private function checkExcluded(): void
    {
        $excludedClients = $this->reportSend->excludedClients;
        if (!empty($excludedClients)) {
            $config = $this->settings->populate();

            $names = [];
            foreach ($config['clients'] as $id => $client) {
                if (in_array((int)$id, $excludedClients, true)) {
                    $names[] = sprintf('%s[%d](%s)', $client['cm'], (int)$id, $client['cl']);
                }

                unset($id, $client);
            }

            $this->logger->notice(
                'Из отчётов исключены торрент клиенты: {excluded}.',
                ['excluded' => implode(', ', $names)]
            );
        }

        if (!empty($this->reportSend->excludedSubForums)) {
            $this->logger->notice(
                'Из отчётов исключены хранимые подразделы: {excluded}.',
                ['excluded' => implode(', ', $this->reportSend->excludedSubForums)]
            );
        }
    }

    private function bytes(int $bytes): string
    {
        return Helper::convertBytes($bytes);
    }

    private function boldBytes(int $bytes): string
    {
        $formatted = $this->bytes($bytes);

        return vsprintf('[b]%s[/b] %s', explode(' ', $formatted));
    }
}
