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
    use Actions\AccessCheck;
    use Actions\ForumTopics;
    use Actions\ForumTree;
    use Actions\KeepersList;
    use Actions\Processor;
    use Actions\RequestLimit;
    use Actions\TopicsDetails;
    use Actions\TopicsPeers;

    public function __construct(
        protected readonly Client          $client,
        protected readonly ApiCredentials  $auth,
        protected readonly ApiForumConnect $connect,
        protected readonly LoggerInterface $logger
    ) {
        $this->auth->validate();
    }
}
