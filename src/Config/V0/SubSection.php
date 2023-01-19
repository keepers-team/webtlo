<?php

namespace KeepersTeam\Webtlo\Config\V0;

final class SubSection
{
    public function __construct(
        public readonly int $id,
        public readonly string $clientId,
        public readonly string $dataFolder,
        public readonly ?SubFolderType $useSubFolder = null,
        public readonly bool $hideTopics = false,
        public readonly bool $enableControlPeers = false,
        public readonly int $controlPeersCount = 0,
    ) {
    }
}
