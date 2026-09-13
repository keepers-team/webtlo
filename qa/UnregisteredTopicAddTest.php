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
use KeepersTeam\Webtlo\TopicList\Filter\Sort;
use KeepersTeam\Webtlo\TopicList\Filter\SortDirection;
use KeepersTeam\Webtlo\TopicList\Filter\SortRule;
use KeepersTeam\Webtlo\TopicList\HtmlFormatter;
use KeepersTeam\Webtlo\TopicList\Rule\UnregisteredTopics;
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
        time_added INT DEFAULT 1000,
        done REAL DEFAULT 0,
        paused INT DEFAULT 0,
        error INT DEFAULT 0,
        tracker_error TEXT,
        PRIMARY KEY (info_hash, client_id)
    );
    CREATE TABLE TopicsUnregistered (info_hash TEXT PRIMARY KEY, status TEXT);
    CREATE TABLE Topics (id INT PRIMARY KEY, info_hash TEXT, forum_id INT, reg_time INT);
    INSERT INTO Torrents (info_hash, client_id, topic_id) VALUES ('OLD1', 1, 101), ('OLD1', 2, 101), ('OLD2', 1, 102);
    INSERT INTO TopicsUnregistered (info_hash, status) VALUES ('OLD1', 'обновлено (проверено)'), ('OLD2', 'обновлено (проверено)');
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

$torrents->insertAddedTopic('NEW1', 1, 101, 'Current release', 123);
$selected = $torrents->getSelectedUnregistered([
    ['hash' => 'OLD1', 'client_id' => 1],
    ['hash' => 'OLD1', 'client_id' => 2],
]);
$addedByClient = [];
foreach ($selected as $row) {
    $addedByClient[$row['client_id']] = $row['added_hash'];
}
checkUnregistered(count($addedByClient) === 2
    && $addedByClient[1] === 'NEW1'
    && $addedByClient[2] === null,
    'An already added current release must be detected only in the same client');

$listing = new UnregisteredTopics($db);
$sort = new Sort(SortRule::TOPIC_ID, SortDirection::UP);
$groups = $listing->getTopics([], $sort)->groups;
$byGroup = [];
foreach ($groups as $group) {
    foreach ($group->topics as $result) {
        $byGroup[$result->topic->hash . ':' . $result->topic->clientId] = $group->title;
    }
}
checkUnregistered(count($byGroup) === 3
    && $byGroup['OLD1:2'] === 'обновлено (проверено)'
    && $byGroup['OLD1:1'] === 'Старая версия уже обновлена'
    && $byGroup['OLD2:1'] === 'Старая версия уже обновлена',
    'Old releases must remain manageable, but move to a separate group only after a current release is added');

$html = (new HtmlFormatter([1 => 'one', 2 => 'two'], 'https://example.test', 1))
    ->format($listing->getTopics([], $sort))['topics'];
checkUnregistered(str_contains($html, "value='OLD1'") && str_contains($html, "data-current-added='1'"),
    'Resolved old torrents must retain a checkbox for client actions and be marked against repeated add');

$db->executeStatement("DELETE FROM Torrents WHERE info_hash = 'NEW1' AND client_id = 1");
$groups = $listing->getTopics([], $sort)->groups;
$byGroup = [];
foreach ($groups as $group) {
    foreach ($group->topics as $result) {
        $byGroup[$result->topic->hash . ':' . $result->topic->clientId] = $group->title;
    }
}
checkUnregistered($byGroup['OLD1:1'] === 'обновлено (проверено)'
    && $byGroup['OLD1:2'] === 'обновлено (проверено)'
    && $byGroup['OLD2:1'] === 'Старая версия уже обновлена',
    'Removing the current release must return the old release to the update group');

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
