<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\External\ApiReport\Actions;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use GuzzleHttp\Exception\GuzzleException;
use KeepersTeam\Webtlo\External\ApiReport\V1\KeepersResponse;
use KeepersTeam\Webtlo\Helper;
use KeepersTeam\Webtlo\Timers;
use League\Csv\Reader;
use PharData;
use RuntimeException;
use Throwable;

trait KeepersReports
{
    /** Путь к временному каталогу с распакованными данными. */
    private ?string $gzipReportsFolder = null;

    /**
     * Загрузка и распаковка статичного архива со всеми хранимыми раздачами всех хранителей.
     */
    public function downloadReportsArchive(): void
    {
        // Проверяем наличие PharData
        if (!class_exists('PharData')) {
            $this->logger->notice('Для загрузки статических архивов требуется расширение PHP "php-phar".');

            return;
        }

        // Определяем пути хранения временных файлов.
        $fileName = 'public_reports-all.tar';
        $tempDir  = Helper::getStorageSubFolderPath(subFolder: 'gzip_keepers_reports');
        $archive  = Helper::getPathWithFile(path: $tempDir, file: $fileName);

        try {
            $response = $this->client->get(uri: 'get_stats_file', options: [
                'query'   => ['file_name' => $fileName],
                'headers' => ['Accept' => 'application/gzip'],
                'sink'    => $archive,
            ]);

            $this->logger->debug(
                'Распаковка статичного архива с отчётами...',
                ['gzip' => $archive, 'size' => Helper::convertBytes(size: $response->getBody()->getSize() ?? 0)]
            );

            Timers::start('unpack');
            (new PharData(filename: $archive))->extractTo(directory: $tempDir, overwrite: true);

            $this->logger->debug(
                'Распаковка статичного архива с отчётами завершена за {sec}',
                ['sec' => Timers::getExecTime('unpack')]
            );

            $this->gzipReportsFolder = $tempDir;
        } catch (Throwable $e) {
            $this->logger->error('Failed to download reports archive: ' . $e->getMessage());
        } finally {
            // Удаляем файл архива.
            unlink($archive);
        }
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
            if ($csv = $this->getCsvReader(forumId: $forumId)) {
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
     * Обработать статичный csv-файл с отчётом по подразделу.
     *
     * @return ?Reader<array<string, string>>
     */
    private function getCsvReader(int $forumId): ?Reader
    {
        $csvPath = sprintf('%s/%d.csv.gz', $this->gzipReportsFolder, $forumId);
        if (!file_exists($csvPath)) {
            return null;
        }

        try {
            /** @var Reader<array<string, string>> $reader */
            $reader = Reader::createFromPath(path: 'compress.zlib://' . $csvPath);

            $reader->setHeaderOffset(offset: 0);

            return $reader;
        } catch (Throwable $e) {
            $this->logger->warning('Failed to read CSV: ' . $e->getMessage());

            return null;
        }
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
        if ($this->gzipReportsFolder) {
            Helper::removeDirRecursive($this->gzipReportsFolder);

            $this->gzipReportsFolder = null;
        }
    }
}
