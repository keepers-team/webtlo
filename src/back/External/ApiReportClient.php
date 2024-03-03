<?php

namespace KeepersTeam\Webtlo\External;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use KeepersTeam\Webtlo\Legacy\Log;

final class ApiReportClient
{
    public $clientProperties = [
        'base_uri'        => "https://DOMAIN/krs/api/v1/",
        'allow_redirects' => true,
    ];

    protected $client;

    public function __construct(
        private readonly array $config,
    ) {
        $this->client = new Client($this->clientProperties);
    }

    public function report_releases(
        int $forum_id,
        array $topic_ids,
        int $status,
        bool $unreport_other_releases_in_subforum,
    ): ?string
    {
        $params = [
            "user_id"                             => $this->config['user_id'],
            "topic_ids"                           => $topic_ids,
            "status"                              => $status,
            "reported_subforum_id"                => $forum_id,
            "unreport_other_releases_in_subforum" => $unreport_other_releases_in_subforum,
        ];

        Log::append("Fetching page {$params}", );
        try {
            $response = $this->client->post('releases/set_status', [
                'json' => $params,
                'auth' => [$this->config['user_id'], $this->config['api_key']],
            ]);
        } catch (GuzzleException $e) {
            Log::append("Failed to fetch page {[...$params, 'error' => $e]}");

            return null;
        }

        $statusCode = $response->getStatusCode();
        if ($statusCode !== 200) {
            Log::append('Unexpected code', [...$params, 'code' => $statusCode]);

            return null;
        }
        Log::append($response->getBody()->getContents());

        return $response->getBody()->getContents();
    }
}
