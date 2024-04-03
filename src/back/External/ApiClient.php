<?php

namespace KeepersTeam\Webtlo\External;

use GuzzleHttp\Client;
use KeepersTeam\Webtlo\External\Api\StaticHelper;
use KeepersTeam\Webtlo\External\Api\Actions;
use Psr\Log\LoggerInterface;

/**
 * Подключение к API форума и получение данных.
 */
final class ApiClient
{
    use StaticHelper;
    use Actions\ForumTopics;
    use Actions\ForumTree;
    use Actions\HighPriorityTopics;
    use Actions\KeepersList;
    use Actions\Processor;
    use Actions\TopicsDetails;
    use Actions\TopicsPeers;

    protected static string $apiVersion  = 'v1';
    protected static int    $concurrency = 4;

    /**
     * @param array<string, string> $defaultParams
     * @param Client               $client
     * @param LoggerInterface      $logger
     */
    public function __construct(
        private readonly array           $defaultParams,
        private readonly Client          $client,
        private readonly LoggerInterface $logger
    ) {
    }
}
