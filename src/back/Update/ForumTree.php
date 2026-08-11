<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\Update;

use KeepersTeam\Webtlo\Enum\UpdateMark;
use KeepersTeam\Webtlo\External\ApiReportClient;
use KeepersTeam\Webtlo\External\Data\ApiError;
use KeepersTeam\Webtlo\Storage\CloneFactory;
use KeepersTeam\Webtlo\Storage\Table\Forums;
use KeepersTeam\Webtlo\Storage\Table\UpdateTime;
use KeepersTeam\Webtlo\Timers;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Обновить список подразделов форума.
 */
final class ForumTree
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly ApiReportClient $apiClient,
        private readonly CloneFactory    $cloneFactory,
        private readonly UpdateTime      $updateTime,
    ) {}

    public function update(): void
    {
        Timers::start('forum_tree');

        $this->logger->info('Начато обновление дерева подразделов...');
        // Проверяем время последнего обновления.
        if (!$this->updateTime->checkUpdateAvailable(marker: UpdateMark::FORUM_TREE)) {
            $this->logger->notice('Обновление дерева подразделов не требуется.');

            return;
        }

        // Получение дерева подразделов.
        $response = $this->apiClient->getForums();
        if ($response instanceof ApiError) {
            throw new RuntimeException($response->text, $response->code);
        }

        // Параметры таблиц.
        $tabForums = $this->cloneFactory->makeClone(table: Forums::TABLE, keys: Forums::KEYS);

        // Преобразуем объекты в простой массив.
        $forums = array_map(static fn($el) => $el->jsonSerialize(), $response->forums);

        // Записываем в базу данных.
        $tabForums->cloneFillChunk(dataSet: $forums);

        // Переносим данные из временной таблицы в основную.
        $tabForums->moveToOrigin();

        // Удаляем неактуальные записи.
        $tabForums->clearUnusedRows();

        // Записываем время обновления.
        $this->updateTime->setMarkerTime(marker: UpdateMark::FORUM_TREE, updateTime: $response->updateTime);

        $this->logger->info('Обновление дерева подразделов завершено за {sec}, обработано подразделов: {count} шт.', [
            'sec'   => Timers::getExecTime('forum_tree'),
            'count' => count($forums),
        ]);
    }
}
