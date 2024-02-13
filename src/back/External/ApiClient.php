<?php

namespace KeepersTeam\Webtlo\External;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use KeepersTeam\Webtlo\External\Api\StaticHelper;
use KeepersTeam\Webtlo\External\Api\Actions;
use Psr\Log\LoggerInterface;

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

    public function __construct(
        private readonly array           $defaultParams,
        private readonly Client          $client,
        private readonly LoggerInterface $logger
    ) {
    }


    private function request(string $method, string $url, array $options = []): ?string
    {
        $redactedParams = ['url' => $url, ...$options];

        $this->logger->debug('Fetching page', $redactedParams);
        try {
            $response = $this->client->request($method, $url, $options);
        } catch (GuzzleException $e) {
            $this->logger->error('Failed to fetch page', [...$redactedParams, 'error' => $e]);

            return null;
        }

        $statusCode = $response->getStatusCode();
        if ($statusCode !== 200) {
            $this->logger->error('Unexpected code', [...$redactedParams, 'code' => $statusCode]);

            return null;
        }

        return $response->getBody()->getContents();
    }

}
