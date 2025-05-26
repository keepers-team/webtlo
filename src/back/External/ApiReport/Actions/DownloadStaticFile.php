<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\External\ApiReport\Actions;

use KeepersTeam\Webtlo\Helper;
use KeepersTeam\Webtlo\Timers;
use League\Csv\Reader;
use PharData;
use Throwable;

/**
 * Попытка скачать статичный файл с данными из API отчётов.
 */
trait DownloadStaticFile
{
    /**
     * Скачать и распаковать архив с данными.
     *
     * @param string $filename  имя статичного файла, который нужно скачать
     * @param ?int[] $subforums опциональный набор ид искомых подразделов
     *
     * @return ?string путь к каталогу с распакованными файлами, если удалось распаковать
     */
    protected function downloadStaticFile(string $filename, ?array $subforums): ?string
    {
        // Проверяем наличие PharData
        if (!class_exists('PharData')) {
            $this->logger->notice('Для загрузки статических архивов требуется расширение PHP "php-phar".');

            return null;
        }

        // Название временного каталога для распаковки файлов.
        $tmpDirName = str_replace('-', '_', pathinfo($filename)['filename'] ?: 'tmp');

        // Абсолютные пути хранения.
        $tempDirPath = Helper::getStorageSubFolderPath(subFolder: $tmpDirName);
        $filePath    = Helper::getPathWithFile(path: $tempDirPath, file: $filename);

        try {
            Timers::start('download_static_file');
            $response = $this->client->get(uri: 'get_stats_file', options: [
                'query'   => ['file_name' => $filename],
                'headers' => ['Accept' => 'application/gzip'],
                'sink'    => $filePath,
            ]);

            $this->logger->debug('Скачан статичный архив за {sec}. Начинаем распаковку...', [
                'gzip' => $filePath,
                'sec'  => Timers::getExecTime('download_static_file'),
                'size' => Helper::convertBytes(size: $response->getBody()->getSize() ?? 0),
            ]);

            Timers::start('unpack');

            $files = null;
            // Если заданы подразделы, собираем ожидаемый список файлов.
            if ($subforums !== null) {
                // Файлы внутри архива имеют путь вида "./313.csv.gz" и без полного пути - не распаковываются.
                $files = array_map(static fn($el) => sprintf('./%d.csv.gz', $el), $subforums);
            }

            $phar = new PharData(filename: $filePath);

            try {
                // Пробуем распаковать только реально нужные файлы.
                $phar->extractTo(directory: $tempDirPath, files: $files, overwrite: true);
            } catch (Throwable) {
                // Если не получилось - распакуем все.
                $phar->extractTo(directory: $tempDirPath, overwrite: true);
            }

            $this->logger->debug(
                'Распаковка архива завершена за {sec}',
                ['sec' => Timers::getExecTime('unpack')]
            );

            return $tempDirPath;
        } catch (Throwable $e) {
            $this->logger->error('Failed to download reports archive: ' . $e->getMessage());
        } finally {
            // Удаляем файл архива.
            Helper::removeDirRecursive(path: $filePath);
        }

        return null;
    }

    /**
     * Обработать статичный csv-файл с отчётом по подразделу.
     *
     * @return ?Reader<array<string, string>>
     */
    protected function getCsvReader(string $folderPath, int $forumId): ?Reader
    {
        $csvPath = sprintf('%s/%d.csv.gz', $folderPath, $forumId);
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
}
