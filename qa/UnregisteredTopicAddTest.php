<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use KeepersTeam\Webtlo\Config\ApiCredentials;
use KeepersTeam\Webtlo\External\ApiReportClient;
use KeepersTeam\Webtlo\External\Data\TopicSearchMode;
use KeepersTeam\Webtlo\Infrastructure\Database\SQLiteAdapter;
use KeepersTeam\Webtlo\Storage\Table\Torrents;
use Psr\Log\NullLogger;

require dirname(__DIR__) . '/vendor/autoload.php';

function checkUnregistered(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db = new SQLiteAdapter($pdo, new NullLogger());
$db->executeQuery(<<<'SQL'
    CREATE TABLE Torrents (
        info_hash TEXT NOT NULL,
        client_id INT NOT NULL,
        topic_id INT,
        name TEXT,
        total_size INT,
        PRIMARY KEY (info_hash, client_id)
    );
    CREATE TABLE TopicsUnregistered (info_hash TEXT PRIMARY KEY);
    CREATE TABLE Topics (id INT PRIMARY KEY, info_hash TEXT, forum_id INT);
    INSERT INTO Torrents (info_hash, client_id, topic_id) VALUES ('OLD1', 1, 101), ('OLD1', 2, 101), ('OLD2', 1, 102);
    INSERT INTO TopicsUnregistered (info_hash) VALUES ('OLD1'), ('OLD2');
    INSERT INTO Topics (id, info_hash, forum_id) VALUES (101, 'NEW1', 55);
    SQL);

$torrents = new Torrents($db);
$selected = $torrents->getSelectedUnregistered([
    ['hash' => 'OLD1', 'client_id' => 1],
    ['hash' => 'OLD1', 'client_id' => 2],
    ['hash' => 'OLD2', 'client_id' => 1],
]);
checkUnregistered(count($selected) === 3, 'Selection must retain the client of each old torrent');
$byClient = [];
foreach ($selected as $row) {
    $byClient[$row['old_hash'] . ':' . $row['client_id']] = $row['current_hash'];
}
checkUnregistered($byClient === ['OLD1:1' => 'NEW1', 'OLD1:2' => 'NEW1', 'OLD2:1' => null],
    'Only rows without a local current hash should be requested from the API');

$torrents->insertAddedTopic('NEW2', 1, 102, 'Current release', 123);
checkUnregistered($db->queryCount("SELECT COUNT(*) FROM Torrents WHERE info_hash = 'NEW2'") === 1,
    'A successful add must be recorded in Torrents');
checkUnregistered($db->queryCount('SELECT COUNT(*) FROM Topics') === 1,
    'The add must not create a Topics row');

$history = [];
$stack = HandlerStack::create(new MockHandler([
    new Response(200, ['Content-Type' => 'application/json'], '{"columns":[],"releases":[]}'),
]));
$stack->push(Middleware::history($history));
$api = new ApiReportClient(
    new Client(['base_uri' => 'https://api.example.test/', 'handler' => $stack]),
    new ApiCredentials(userId: 1, btKey: 'test', apiKey: 'test'),
    new NullLogger(),
);
$result = $api->getTopicsDetails([102], TopicSearchMode::ID, lookupOldVersions: false);
checkUnregistered($result->missingTopics === [102], 'Missing current release must be reported');
checkUnregistered(count($history) === 1, 'Current-only lookup must not query old releases');
checkUnregistered($history[0]['request']->getUri()->getPath() === '/releases/pvc',
    'Current-only lookup used an unexpected endpoint');

echo "Unregistered topic add tests passed\n";
