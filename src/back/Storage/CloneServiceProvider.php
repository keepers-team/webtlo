<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\Storage;

use KeepersTeam\Webtlo\Storage\Clone\HighPriorityInsert;
use KeepersTeam\Webtlo\Storage\Clone\HighPriorityUpdate;
use KeepersTeam\Webtlo\Storage\Clone\KeepersLists;
use KeepersTeam\Webtlo\Storage\Clone\KeepersSeeders;
use KeepersTeam\Webtlo\Storage\Clone\TopicsInsert;
use KeepersTeam\Webtlo\Storage\Clone\TopicsUnregistered;
use KeepersTeam\Webtlo\Storage\Clone\TopicsUntracked;
use KeepersTeam\Webtlo\Storage\Clone\TopicsUpdate;
use KeepersTeam\Webtlo\Storage\Clone\Torrents;
use KeepersTeam\Webtlo\Storage\Clone\UpdateTime;
use League\Container\ServiceProvider\AbstractServiceProvider;

/**
 * Предоставляет доступ к фабрике таблиц-клонов внутри контейнера.
 */
final class CloneServiceProvider extends AbstractServiceProvider
{
    public function provides(string $id): bool
    {
        $services = [
            HighPriorityInsert::class,
            HighPriorityUpdate::class,
            KeepersLists::class,
            KeepersSeeders::class,
            TopicsInsert::class,
            TopicsUpdate::class,
            TopicsUntracked::class,
            TopicsUnregistered::class,
            Torrents::class,
            UpdateTime::class,
        ];

        return in_array($id, $services, true);
    }

    public function register(): void
    {
        $container = $this->getContainer();

        $container->addShared(HighPriorityInsert::class, function() use ($container) {
            /** @var CloneFactory $factory */
            $factory = $container->get(CloneFactory::class);

            return $factory->cloneHighPriorityInsert();
        });

        $container->addShared(HighPriorityUpdate::class, function() use ($container) {
            /** @var CloneFactory $factory */
            $factory = $container->get(CloneFactory::class);

            return $factory->cloneHighPriorityUpdate();
        });

        $container->addShared(KeepersLists::class, function() use ($container) {
            /** @var CloneFactory $factory */
            $factory = $container->get(CloneFactory::class);

            return $factory->cloneKeepersLists();
        });

        $container->addShared(KeepersSeeders::class, function() use ($container) {
            /** @var CloneFactory $factory */
            $factory = $container->get(CloneFactory::class);

            return $factory->cloneKeepersSeeders();
        });

        $container->addShared(TopicsInsert::class, function() use ($container) {
            /** @var CloneFactory $factory */
            $factory = $container->get(CloneFactory::class);

            return $factory->cloneTopicsInsert();
        });

        $container->addShared(TopicsUpdate::class, function() use ($container) {
            /** @var CloneFactory $factory */
            $factory = $container->get(CloneFactory::class);

            return $factory->cloneTopicsUpdate();
        });

        $container->addShared(TopicsUntracked::class, function() use ($container) {
            /** @var CloneFactory $factory */
            $factory = $container->get(CloneFactory::class);

            return $factory->cloneTopicsUntracked();
        });

        $container->addShared(TopicsUnregistered::class, function() use ($container) {
            /** @var CloneFactory $factory */
            $factory = $container->get(CloneFactory::class);

            return $factory->cloneTopicsUnregistered();
        });

        $container->addShared(Torrents::class, function() use ($container) {
            /** @var CloneFactory $factory */
            $factory = $container->get(CloneFactory::class);

            return $factory->cloneTorrents();
        });

        $container->addShared(UpdateTime::class, function() use ($container) {
            /** @var CloneFactory $factory */
            $factory = $container->get(CloneFactory::class);

            return $factory->cloneUpdateTime();
        });
    }
}
