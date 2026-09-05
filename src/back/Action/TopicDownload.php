<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\Action;

use KeepersTeam\Webtlo\Config\TorrentDownload;
use KeepersTeam\Webtlo\External\ForumClient;
use KeepersTeam\Webtlo\Helper;
use KeepersTeam\Webtlo\Module\TorrentEditor;
use KeepersTeam\Webtlo\Timers;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

final class TopicDownload
{
    public function __construct(
        private readonly ForumClient     $forumClient,
        private readonly TorrentDownload $downloadOptions,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @param string[] $hashes
     */
    public function process(int $listingId, array $hashes, bool $replacePasskey): string
    {
        Timers::start('download');

        $passkeyValue   = $this->downloadOptions->replacePassKey;
        $forRegularUser = $this->downloadOptions->forRegularUser;

        // Выбор базового каталога для файлов.
        $torrentFilePath = $this->makeTopicContentPath(replacePasskey: $replacePasskey, listingId: $listingId);

        // Шаблон для сохранения.
        $torrentFilePathTemplate = Helper::normalizePathEncoding("$torrentFilePath/[webtlo].h%s.torrent");

        $log_string = sprintf(
            'Выполняется скачивание торрент-файлов (%d шт), трекеры %s. ',
            count($hashes),
            $forRegularUser ? 'пользовательские' : 'хранительские'
        );
        if ($replacePasskey) {
            $log_string .= !empty($passkeyValue) ? "Замена Passkey: [$passkeyValue]" : 'Passkey пуст.';
        }

        $this->logger->info($log_string);

        $filesDownloaded = [];
        foreach ($hashes as $topicHash) {
            $data = $this->forumClient->downloadTorrent(
                infoHash    : $topicHash,
                addRetracker: $this->downloadOptions->addRetracker
            );
            if ($data === null) {
                continue;
            }

            // Меняем ключ для трекера.
            if ($replacePasskey) {
                try {
                    $torrent = TorrentEditor::loadFromStream(logger: $this->logger, stream: $data);
                    $torrent->replaceTrackers(passkey: $passkeyValue, regularUser: $forRegularUser);

                    $data = $torrent->getTorrent()->storeToString();

                    unset($torrent);
                } catch (Throwable $e) {
                    $this->logger->warning('Ошибка редактирования торрента', ['error' => $e->getMessage()]);

                    continue;
                }
            } else {
                $data = $data->getContents();
            }

            if (empty($data)) {
                continue;
            }

            // Записываем содержимое торрент-файла в созданный ранее каталог.
            $fileSaved = file_put_contents(
                sprintf($torrentFilePathTemplate, $topicHash),
                $data
            );
            if ($fileSaved === false) {
                $this->logger->warning("Произошла ошибка при сохранении торрент-файла ($topicHash)");

                continue;
            }

            $filesDownloaded[] = $topicHash;

            unset($topicHash, $data, $fileSaved);
        }

        $result = sprintf(
            'Сохранено в каталоге "%s": %d шт. за %s.',
            $torrentFilePath,
            count($filesDownloaded),
            Timers::getExecTime('download')
        );

        $this->logger->info($result);

        return $result;
    }

    /**
     * @return non-empty-string
     */
    private function makeTopicContentPath(bool $replacePasskey, int $listingId): string
    {
        // Выбор базового каталога для файлов.
        $torrentFilePath = !$replacePasskey
            ? $this->downloadOptions->folder
            : $this->downloadOptions->folderReplace;

        if (empty($torrentFilePath)) {
            throw new RuntimeException('В настройках не указан каталог для скачивания торрент-файлов');
        }

        // Дополнительный слэш в конце каталога.
        if (!in_array(substr($torrentFilePath, -1), ['\\', '/'], true)) {
            $torrentFilePath .= !str_contains($torrentFilePath, '/') ? '\\' : '/';
        }

        if (!$replacePasskey && $this->downloadOptions->subFolder) {
            if ($listingId < 0) {
                $listingId = 'list_' . abs($listingId);
            }

            $torrentFilePath .= 'tfiles_' . $listingId . '_' . time() . substr($torrentFilePath, -1);
        }

        // Создание каталога для записи файлов.
        Helper::makeDirRecursive(path: $torrentFilePath);

        return $torrentFilePath;
    }
}
