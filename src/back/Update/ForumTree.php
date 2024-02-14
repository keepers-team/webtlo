<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\Update;

use KeepersTeam\Webtlo\Enum\UpdateMark;
use KeepersTeam\Webtlo\External\Api\V1\ApiError;
use KeepersTeam\Webtlo\External\ApiClient;
use KeepersTeam\Webtlo\Module\CloneTable;
use KeepersTeam\Webtlo\Tables\UpdateTime;
use KeepersTeam\Webtlo\Timers;
use Psr\Log\LoggerInterface;
use RuntimeException;

/** Обновить список подразделов форума. */
final class ForumTree
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly ApiClient       $apiClient,
        private readonly UpdateTime      $updateTime
    ) {
    }

    public function update(): void
    {
        Timers::start('forum_tree');

        $this->logger->info('Начато обновление дерева подразделов...');
        // Проверяем время последнего обновления. TODO переделать коннект к БД.
        if (!$this->updateTime->checkUpdateAvailable(UpdateMark::FORUM_TREE->value)) {
            $this->logger->notice('Обновление дерева подразделов не требуется.');

            return;
        }

        // Получение дерева подразделов.
        $response = $this->apiClient->getForums();
        if ($response instanceof ApiError) {
            throw new RuntimeException($response->text, $response->code);
        }

        // Параметры таблиц.
        $tabForums = CloneTable::create('Forums', ['id', 'name', 'quantity', 'size']);

        // Преобразуем объекты в простой массий. TODO переделать.
        $forums = array_map(fn($el) => array_combine($tabForums->keys, (array)$el), $response->forums);

        // Записываем в базу данных.
        $tabForums->cloneFillChunk($forums);

        // Переносим данные из временной таблицы в основную.
        $tabForums->moveToOrigin();

        // Удаляем неактуальные записи.
        $tabForums->clearUnusedRows();

        // Записываем время обновления.
        $this->updateTime->setMarkerTime(UpdateMark::FORUM_TREE->value, $response->updateTime);

        $this->logger->info(
            sprintf(
                'Обновление дерева подразделов завершено за %s, обработано подразделов: %d шт.',
                Timers::getExecTime('forum_tree'),
                count($forums)
            )
        );
    }
}
