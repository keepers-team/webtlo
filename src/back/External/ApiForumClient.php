<?php

namespace KeepersTeam\Webtlo\External;

use GuzzleHttp\Client;
use KeepersTeam\Webtlo\Config\ApiCredentials;
use KeepersTeam\Webtlo\Config\ApiForumConnect;
use KeepersTeam\Webtlo\External\Api\Actions;
use Psr\Log\LoggerInterface;

/**
 * Подключение к API форума и получение данных.
 */
final class ApiForumClient
{
    use Actions\ForumTopics;
    use Actions\ForumTree;
    use Actions\KeepersList;
    use Actions\Processor;
    use Actions\TopicsDetails;
    use Actions\TopicsPeers;

    public function __construct(
        private readonly Client          $client,
        private readonly ApiCredentials  $auth,
        private readonly ApiForumConnect $connect,
        private readonly LoggerInterface $logger
    ) {}
}
