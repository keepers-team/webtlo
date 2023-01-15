<?php

namespace KeepersTeam\Webtlo\Config\V0;

use KeepersTeam\Webtlo\Config\Proxy;

/**
 * Main webTLO configuration
 */
final class Config
{
    public function __construct(
        public readonly int $version,
        public readonly Forum $forum,
        /** @var TorrentClient[] */
        public readonly array $torrentClients = [],
        /** @var SubSection[] */
        public readonly array $subSections = [],
        public readonly Download $download = new Download(),
        public readonly Filters $filters = new Filters(),
        public readonly Curators $curators = new Curators(),
        public readonly Reports $reports = new Reports(),
        public readonly Vacancies $vacancies = new Vacancies(),
        public readonly TopicsControl $topicsControl = new TopicsControl(),
        public readonly Proxy $proxy = new Proxy()
    ) {
    }
}
