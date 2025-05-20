<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\Storage;

use KeepersTeam\Webtlo\DB;
use KeepersTeam\Webtlo\DTO\TableCloneObject;
use KeepersTeam\Webtlo\Storage\Clone\HighPriorityInsert;
use KeepersTeam\Webtlo\Storage\Clone\HighPriorityUpdate;
use KeepersTeam\Webtlo\Storage\Clone\KeepersLists;
use KeepersTeam\Webtlo\Storage\Clone\KeepersSeeders;
use KeepersTeam\Webtlo\Storage\Clone\SeedersInsert;
use KeepersTeam\Webtlo\Storage\Clone\TopicsInsert;
use KeepersTeam\Webtlo\Storage\Clone\TopicsUnregistered;
use KeepersTeam\Webtlo\Storage\Clone\TopicsUntracked;
use KeepersTeam\Webtlo\Storage\Clone\TopicsUpdate;
use KeepersTeam\Webtlo\Storage\Clone\Torrents;
use KeepersTeam\Webtlo\Storage\Clone\UpdateTime;
use Psr\Log\LoggerInterface;

final class CloneFactory
{
    public function __construct(
        private readonly DB              $db,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Создание временной таблицы, которая является копией существующей таблицы.
     *
     * Можно ограничить список копируемых ключей.
     *
     * @param array{}|string[] $keys
     */
    public function makeClone(
        string $table,
        array  $keys = [],
        string $primary = 'id',
        string $prefix = 'New'
    ): CloneTable {
        $cloneName  = $prefix . $table;
        $cloneTable = "temp.$cloneName";

        $cloneObject = new TableCloneObject(
            origin : $table,
            clone  : $cloneTable,
            keys   : $keys,
            primary: $primary,
        );

        $clone = new CloneTable(db: $this->db, table: $cloneObject);

        $clone->createClone(cloneName: $cloneName);

        return $clone;
    }

    public function cloneHighPriorityInsert(): HighPriorityInsert
    {
        $table = $this->makeClone(
            table  : HighPriorityInsert::TABLE,
            keys   : HighPriorityInsert::KEYS,
            primary: HighPriorityInsert::PRIMARY,
            prefix: 'HighPriorityInsert',
        );

        return new HighPriorityInsert(
            clone: $table,
        );
    }

    public function cloneHighPriorityUpdate(): HighPriorityUpdate
    {
        $table = $this->makeClone(
            table  : HighPriorityUpdate::TABLE,
            keys   : HighPriorityUpdate::KEYS,
            primary: HighPriorityUpdate::PRIMARY,
            prefix: 'HighPriorityUpdate',
        );

        return new HighPriorityUpdate(
            clone: $table,
        );
    }

    public function cloneKeepersLists(): KeepersLists
    {
        $table = $this->makeClone(
            table  : KeepersLists::TABLE,
            keys   : KeepersLists::KEYS,
            primary: KeepersLists::PRIMARY,
        );

        return new KeepersLists(
            db    : $this->db,
            logger: $this->logger,
            clone : $table,
        );
    }

    public function cloneKeepersSeeders(): KeepersSeeders
    {
        $table = $this->makeClone(
            table  : KeepersSeeders::TABLE,
            keys   : KeepersSeeders::KEYS,
            primary: KeepersSeeders::PRIMARY,
        );

        return new KeepersSeeders(
            logger: $this->logger,
            clone : $table,
        );
    }

    public function cloneTopicsInsert(): TopicsInsert
    {
        $table = $this->makeClone(
            table  : TopicsInsert::TABLE,
            keys   : TopicsInsert::KEYS,
            primary: TopicsInsert::PRIMARY,
            prefix : 'Insert',
        );

        return new TopicsInsert(
            clone: $table,
        );
    }

    public function cloneTopicsUpdate(): TopicsUpdate
    {
        $table = $this->makeClone(
            table  : TopicsUpdate::TABLE,
            keys   : TopicsUpdate::KEYS,
            primary: TopicsUpdate::PRIMARY,
            prefix : 'Update'
        );

        return new TopicsUpdate(
            clone: $table,
        );
    }

    public function cloneTopicsUntracked(): TopicsUntracked
    {
        $table = $this->makeClone(
            table  : TopicsUntracked::TABLE,
            keys   : TopicsUntracked::KEYS,
            primary: TopicsUntracked::PRIMARY,
        );

        return new TopicsUntracked(
            logger: $this->logger,
            clone : $table,
        );
    }

    public function cloneTopicsUnregistered(): TopicsUnregistered
    {
        $table = $this->makeClone(
            table  : TopicsUnregistered::TABLE,
            keys   : TopicsUnregistered::KEYS,
            primary: TopicsUnregistered::PRIMARY,
        );

        return new TopicsUnregistered(
            db    : $this->db,
            logger: $this->logger,
            clone : $table,
        );
    }

    public function cloneSeeders(): SeedersInsert
    {
        $table = $this->makeClone(
            table  : SeedersInsert::TABLE,
            keys   : SeedersInsert::makeKeysList(),
            primary: SeedersInsert::PRIMARY,
        );

        return new SeedersInsert(
            clone : $table,
        );
    }

    public function cloneTorrents(): Torrents
    {
        $table = $this->makeClone(
            table  : Torrents::TABLE,
            keys   : Torrents::KEYS,
            primary: Torrents::PRIMARY,
        );

        return new Torrents(
            db   : $this->db,
            clone: $table,
        );
    }

    public function cloneUpdateTime(): UpdateTime
    {
        $table = $this->makeClone(
            table  : UpdateTime::TABLE,
            keys   : UpdateTime::KEYS,
            primary: UpdateTime::PRIMARY,
        );

        return new UpdateTime(
            db   : $this->db,
            clone: $table,
        );
    }
}
