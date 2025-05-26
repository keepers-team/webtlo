<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\External\ApiReport\Actions;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use GuzzleHttp\Exception\GuzzleException;
use KeepersTeam\Webtlo\External\ApiReport\V1\KeepersResponse;
use KeepersTeam\Webtlo\Helper;
use RuntimeException;
use Throwable;

trait KeepersReports
{
    /** Путь к временному каталогу с распакованными данными. */
    private ?string $gzipReportsFolder = null;

    /**
     * Загрузка и распаковка статичного архива со всеми хранимыми раздачами всех хранителей.
     *
     * @param ?int[] $subforums
     */
    public function downloadReportsArchive(?array $subforums = null): void
    {
        $this->gzipReportsFolder = $this->downloadStaticFile(
            filename : 'public_reports-all.tar',
            subforums: $subforums,
        );
    }

    /**
     * Получить отчёт по подразделу.
     * Из csv (если он есть) или из API.
     */
    public function getKeepersReports(int $forumId): KeepersResponse
    {
        $processor = $this->tryGetCsvProcessor(forumId: $forumId) ?? $this->getApiProcessor(forumId: $forumId);

        return new KeepersResponse(forumId: $forumId, keepers: $processor->process());
    }

    private function tryGetCsvProcessor(int $forumId): ?ReportProcessorInterface
    {
        // Архив не загружен, выходим сразу.
        if ($this->gzipReportsFolder === null) {
            return null;
        }

        try {
            if ($csv = $this->getCsvReader(folderPath: $this->gzipReportsFolder, forumId: $forumId)) {
                return new CsvReportProcessor(csv: $csv, seedingChecker: self::createSeedingChecker());
            }
        } catch (Throwable $e) {
            $this->logger->warning('CSV processing error: ' . $e->getMessage());
        }

        return null;
    }

    private function getApiProcessor(int $forumId): ReportProcessorInterface
    {
        $reports = $this->fetchApiReports(forumId: $forumId);
        if (!count($reports)) {
            throw new RuntimeException("API. Не удалось получить данные для раздела $forumId.");
        }

        return new ApiReportProcessor(reports: $reports, seedingChecker: self::createSeedingChecker());
    }

    /**
     * Загрузить отчёт по подразделу из API отчётов.
     *
     * @return array{}|list<array<string, mixed>>
     */
    private function fetchApiReports(int $forumId): array
    {
        try {
            $response = $this->client->get(uri: "subforum/$forumId/reports", options: [
                'query' => ['columns' => 'status,last_update_time,last_seeded_time'],
            ]);

            return json_decode($response->getBody()->getContents(), true) ?: [];
        } catch (GuzzleException $e) {
            $this->logException($e->getCode(), $e->getMessage());

            return [];
        }
    }

    /**
     * Проверка вхождения даты последнего сидирования в границы [-2, 0].
     *
     * @return Closure(string): bool
     */
    private static function createSeedingChecker(): Closure
    {
        $currentTime = new DateTimeImmutable();
        $twoHoursAgo = $currentTime->modify('-2 hours');

        return static function(string $lastSeeded) use ($currentTime, $twoHoursAgo): bool {
            try {
                $seededTime = new DateTimeImmutable($lastSeeded, new DateTimeZone('UTC'));

                return $seededTime >= $twoHoursAgo && $seededTime <= $currentTime;
            } catch (Throwable) {
                return false;
            }
        };
    }

    /**
     * Удалить статические файлы, если они есть.
     */
    public function cleanupReports(): void
    {
        if ($this->gzipReportsFolder !== null) {
            Helper::removeDirRecursive(path: $this->gzipReportsFolder);

            $this->gzipReportsFolder = null;
        }
    }
}
