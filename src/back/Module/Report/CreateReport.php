<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\Module\Report;

use DateTimeImmutable;
use Exception;
use KeepersTeam\Webtlo\Config\ApiCredentials;
use KeepersTeam\Webtlo\Config\ReportSend;
use KeepersTeam\Webtlo\Config\SubForums;
use KeepersTeam\Webtlo\Config\Telemetry;
use KeepersTeam\Webtlo\Config\TorrentClients;
use KeepersTeam\Webtlo\Data\Forum;
use KeepersTeam\Webtlo\DB;
use KeepersTeam\Webtlo\Enum\UpdateMark;
use KeepersTeam\Webtlo\Enum\UpdateStatus;
use KeepersTeam\Webtlo\Helper;
use KeepersTeam\Webtlo\Storage\KeysObject;
use KeepersTeam\Webtlo\Storage\Table\Forums;
use KeepersTeam\Webtlo\Storage\Table\UpdateTime;
use KeepersTeam\Webtlo\WebTLO;
use PDO;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Объект для создания новых отчётов.
 */
final class CreateReport
{
    /** @var int[] */
    public ?array $forums = null;

    private ?DateTimeImmutable $updateTime = null;

    private CreationMode $mode = CreationMode::CRON;

    /** @var ?string[] Сводный отчёт. */
    private ?array $summary = null;

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

    public function __construct(
        private readonly DB              $db,
        private readonly SubForums       $subForums,
        private readonly TorrentClients  $clients,
        private readonly ApiCredentials  $auth,
        private readonly ReportSend      $reportSend,
        private readonly Telemetry       $telemetry,
        private readonly UpdateTime      $tableUpdate,
        private readonly Forums          $tableForums,
        private readonly WebTLO          $webtlo,
        private readonly LoggerInterface $logger,
    ) {
        $this->auth->validate();
    }

    public function initConfig(?CreationMode $mode = null): void
    {
        if ($mode !== null) {
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
        if ($this->forums === null) {
            return 0;
        }

        return count($this->forums) - count($this->reportSend->excludedSubForums);
    }

    /**
     * @return int[]
     */
    public function getForums(): array
    {
        if ($this->forums === null) {
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
     * @return string[]
     */
    public function getForumReport(Forum $forum): array
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

            ++$tmp['topicCounter'];
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
     * @return array{}|array<string, mixed>
     */
    public function getConfigTelemetry(): array
    {
        return $this->telemetry->info;
    }

    /**
     * @return string[]
     *
     * @throws Exception
     */
    private function collectSummaryInfo(): array
    {
        // Если данные уже собраны - возвращаем готовый набор.
        if ($this->summary !== null) {
            return $this->summary;
        }

        // Собираем данные для сводного отчёта.
        if ($this->stored === null) {
            $this->fillStoredValues();
        }
        if ($this->stored === null) {
            throw new RuntimeException('Нет данных для построения сводного отчёта.');
        }

        // Вытаскиваем из базы хранимое
        $total = $this->calcSummary($this->stored);

        static $urlPattern = '[url=viewforum.php?f=%s&keeper_info=&report=%s][u]%s[/u][/url]';

        // Разбираем хранимое
        $savedSubsections = [];
        foreach ($this->getForums() as $forumId) {
            // Исключаем подразделы, согласно конфига.
            if ($this->isForumExcluded($forumId)) {
                continue;
            }

            $forumValues = $this->stored[$forumId] ?? [];
            if (!count($forumValues)) {
                continue;
            }

            $forum = $this->tableForums->getForum(forumId: $forumId);
            if ($forum === null) {
                throw new RuntimeException("Нет данных о хранимом подразделе №$forumId");
            }

            // Ссылка на отчёт подраздела.
            $leftPart = sprintf($urlPattern, $forumId, $this->auth->userId, $forum->name);

            // Ссылка на свой пост(отчёт) и количество + объём раздач.
            $rightPart = sprintf('%s шт. (%s)', $forumValues['keep_count'], $this->bytes($forumValues['keep_size']));

            // Записываем данные о подразделе в сводный отчёт.
            $savedSubsections[] = "[*]$leftPart - $rightPart";

            unset($forumId, $forumValues, $leftPart, $rightPart);
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
        if ($val['authored_count'] > 0) {
            $rows[] = sprintf('- из них авторских раздач: [b]%s[/b] шт. (%s)', $val['authored_count'], $this->boldBytes($val['authored_size']));
        }
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
     * Получить ссылку для отчёта. Разные версии для UI|cron.
     *
     * @param array<string, mixed> $topic
     */
    private function prepareTopicUrl(array $topic): string
    {
        $topicUrl = '';
        // #dl - скачивание, :!: - смайлик.
        $downloadIcon = $topic['done'] != 1 ? ' :!: ' : '';

        if ($this->mode === CreationMode::UI) {
            // [url=viewtopic.php?t=topic_id#dl]topic_name[/url] 842 GB :!:
            $pattern_topic = '[url=viewtopic.php?t=%s]%s[/url] %s%s';

            $topicUrl = sprintf(
                $pattern_topic,
                $topic['id'] . ($topic['done'] != 1 ? '#dl' : ''),
                $topic['topic_name'],
                $this->bytes($topic['topic_size']),
                $downloadIcon
            );
        }
        if ($this->mode === CreationMode::CRON) {
            // [url=viewtopic.php?t=topic_id#dl]topic_hash|topic_id[/url] :!:
            $pattern_topic = '[url=viewtopic.php?t=%s]%s|%d[/url]%s';

            $topicUrl = sprintf(
                $pattern_topic,
                $topic['id'] . ($topic['done'] != 1 ? '#dl' : ''),
                $topic['topic_hash'],
                $topic['id'],
                $downloadIcon
            );
        }

        return $topicUrl;
    }

    /**
     * Собираем сообщения для UI.
     *
     * @param string[] $messages
     */
    public function prepareReportsMessages(array $messages): string
    {
        array_walk($messages, function(&$a, $b) {
            ++$b;
            $a = sprintf('<h3>Сообщение %d</h3><div>%s</div>', $b, $a);
        });

        return sprintf('<div class="report_message">%s</div>', implode('', $messages));
    }

    /**
     * Посчитать сумму хранимого.
     *
     * @param array<string, mixed> $stored
     *
     * @return array<string, mixed>
     */
    private function calcSummary(array $stored): array
    {
        $sumKeys = [
            'authored_count', // Раздачи за авторством пользователя
            'authored_size',  // Раздачи за авторством пользователя
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
            // Не учитываем исключённые подразделы.
            if ($this->isForumExcluded(forumId: (int) $forum_id)) {
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
        if ($forumId !== null) {
            $forumIds = [$forumId];
        } else {
            $forumIds = $this->getForums();
            sort($forumIds);
        }

        $includeForums  = KeysObject::create($forumIds);
        $excludeClients = KeysObject::create($this->reportSend->excludedClients);

        // Вытаскиваем из базы хранимое.
        $values = $this->db->query(
            "SELECT
                forum_id,
                SUM(CASE WHEN authored_by_user      THEN 1 ELSE 0 END) authored_count,
                SUM(CASE WHEN done = 1              THEN 1 ELSE 0 END) keep_count,
                SUM(CASE WHEN done = 1 AND av <= 10 THEN 1 ELSE 0 END) less10_count,
                SUM(CASE WHEN done = 1 AND av >  10 THEN 1 ELSE 0 END) more10_count,
                SUM(CASE WHEN done < 1              THEN 1 ELSE 0 END) dl_count,
                SUM(CASE WHEN authored_by_user      THEN topic_size ELSE 0 END) authored_size,
                SUM(CASE WHEN done = 1              THEN topic_size ELSE 0 END) keep_size,
                SUM(CASE WHEN done = 1 AND av <= 10 THEN topic_size ELSE 0 END) less10_size,
                SUM(CASE WHEN done = 1 AND av >  10 THEN topic_size ELSE 0 END) more10_size,
                SUM(CASE WHEN done < 1              THEN topic_size ELSE 0 END) dl_size
            FROM (
                SELECT
                    tp.forum_id,
                    (tp.seeders * 1.0 / tp.seeders_updates_today) av,
                    tp.size topic_size,
                    tr.done,
                    CASE WHEN tp.poster = ? THEN 1 END AS authored_by_user
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
            [$this->auth->userId, ...$excludeClients->values, ...$includeForums->values],
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
     * @return array<string, mixed>[]
     */
    public function getStoredForumTopics(int $forumId): array
    {
        if (!empty($this->cache[$forumId])) {
            return $this->cache[$forumId];
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
                    tp.poster topic_author,
                    MAX(tr.done) AS done
                FROM Topics tp
                INNER JOIN Torrents tr ON tr.info_hash = tp.info_hash
                WHERE tp.forum_id = ? AND tr.error = 0 AND tr.client_id NOT IN ($excludeClients->keys)
                GROUP BY tp.id, tp.info_hash, tp.forum_id, tp.name, tp.size, tp.status
                ORDER BY tp.id
            ",
            [$forumId, ...$excludeClients->values],
        );

        if (!count($topics)) {
            throw new EmptyFoundTopicsException('В БД не найдены хранимые раздачи подраздела.', $forumId);
        }

        // Если включена опция исключения авторских раздач, фильтруем раздачи.
        if ($this->reportSend->excludeAuthored) {
            $userId = $this->auth->userId;
            $topics = array_filter($topics, static fn($topic): bool => $topic['topic_author'] !== $userId);
        }

        $this->cache[$forumId] = $topics;

        return $topics;
    }

    public function clearCache(int $forumId): void
    {
        $this->cache[$forumId] = null;
    }

    private function getLastUpdateTime(): void
    {
        $lastTimestamp = $this->tableUpdate->getMarkerTimestamp(UpdateMark::FULL_UPDATE->value);

        if ($lastTimestamp === 0) {
            $update = $this->tableUpdate->checkFullUpdate(
                markers         : $this->getForums(),
                daysUpdateExpire: $this->reportSend->daysUpdateExpire
            );

            if ($update->getLastCheckStatus() === UpdateStatus::MISSED) {
                $update->addLogRecord($this->logger);

                throw new RuntimeException(
                    'Сформировать отчёт невозможно. '
                    . 'Данные в локальной БД неполные. '
                    . 'Выполните полное обновление данных и попробуйте снова.'
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
        $subForums = $this->subForums->ids;
        if (!count($subForums)) {
            throw new RuntimeException('Отсутствуют хранимые подразделы. Проверьте настройки.');
        }

        // Записываем идентификаторы хранимых подразделов.
        $this->forums = $subForums;
    }

    /**
     * Исключаемые подразделы и торрент-клиенты.
     */
    private function checkExcluded(): void
    {
        if (count($this->reportSend->excludedClients)) {
            $names = [];
            foreach ($this->clients->clients as $client) {
                if ($client->exclude) {
                    $names[] = $client->name;
                }
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
