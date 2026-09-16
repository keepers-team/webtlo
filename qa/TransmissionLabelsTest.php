<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use KeepersTeam\Webtlo\Clients\Traits\AllowedFunctions;
use KeepersTeam\Webtlo\Clients\Transmission;
use Psr\Log\NullLogger;

require dirname(__DIR__) . '/vendor/autoload.php';

/**
 * @param Response[] $replies
 * @param array<int, array<string, mixed>> $history
 */
function makeTransmission(int $rpcVersion, array $replies, array &$history): Transmission
{
    $stack = HandlerStack::create(new MockHandler($replies));
    $stack->push(Middleware::history($history));

    $reflection = new ReflectionClass(Transmission::class);
    $transmission = $reflection->newInstanceWithoutConstructor();
    $reflection->getProperty('logger')->setValue($transmission, new NullLogger());
    $reflection->getProperty('rpcVersion')->setValue($transmission, $rpcVersion);
    $reflection->getProperty('client')->setValue($transmission, new Client([
        'base_uri' => 'http://localhost/transmission/rpc',
        'handler'  => $stack,
    ]));

    return $transmission;
}

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/** @param array<string, mixed> $transaction */
function requestArguments(array $transaction): array
{
    $request = json_decode((string) $transaction['request']->getBody(), true);

    return $request['arguments'];
}

$success = static fn(): Response => new Response(200, [], '{"result":"success","arguments":{}}');

foreach ([15, 16, 17] as $rpcVersion) {
    $history = [];
    $transmission = makeTransmission($rpcVersion, [$success()], $history);
    check($transmission->addTorrentContent('torrent data', label: 'label'), "RPC $rpcVersion: add failed");
    check(count($history) === 1, "RPC $rpcVersion: unexpected add request count");
    check(
        isset(requestArguments($history[0])['labels']) === ($rpcVersion >= 17),
        "RPC $rpcVersion: wrong torrent-add label support",
    );
    check($transmission->isLabelAddingAllowed() === ($rpcVersion >= 17), "RPC $rpcVersion: wrong capability");
    check(
        $transmission->getPostAddLabelDelay(500) === ($rpcVersion >= 16 ? 0 : null),
        "RPC $rpcVersion: wrong post-add delay",
    );
}

$history = [];
$transmission = makeTransmission(16, [$success(), $success()], $history);
check($transmission->addTorrentContent('torrent data', label: 'label'), 'RPC 16: add failed');
check($transmission->setLabel(['hash'], 'label'), 'RPC 16: post-add label failed');
check(count($history) === 2, 'RPC 16: expected one add and one batched set request');
check(!isset(requestArguments($history[0])['labels']), 'RPC 16: torrent-add must not send labels');
check(requestArguments($history[1])['labels'] === ['label'], 'RPC 16: torrent-set label mismatch');

foreach ([16, 17] as $rpcVersion) {
    $history = [];
    $transmission = makeTransmission($rpcVersion, [$success()], $history);
    check($transmission->setLabel(['hash'], ''), "RPC $rpcVersion: clear failed");
    check(requestArguments($history[0])['labels'] === [], "RPC $rpcVersion: empty label must use []");
}

$history = [];
$transmission = makeTransmission(16, [$success()], $history);
check($transmission->setLabel(['hash'], '  '), 'RPC 16: whitespace label must clear');
check(requestArguments($history[0])['labels'] === [], 'RPC 16: whitespace label must use []');

$history = [];
$transmission = makeTransmission(17, [$success()], $history);
check($transmission->addTorrentContent('torrent data', label: '0'), 'label 0 must be accepted');
check(requestArguments($history[0])['labels'] === ['0'], 'label 0 must not be dropped');

$history = [];
$transmission = makeTransmission(17, [], $history);
check(!$transmission->addTorrentContent('torrent data', label: 'a,b'), 'comma in add label must fail');
check(!$transmission->setLabel(['hash'], 'a,b'), 'comma in set label must fail');
check($history === [], 'invalid labels must not send RPC requests');

$history = [];
$transmission = makeTransmission(15, [$success()], $history);
check($transmission->addTorrentContent('torrent data', label: 'a,b'), 'RPC 15: unsupported label must not block add');
check(!isset(requestArguments($history[0])['labels']), 'RPC 15: unsupported label must not be sent');

$history = [];
$transmission = makeTransmission(16, [$success(), $success()], $history);
$hashes = array_map(static fn(int $index): string => sprintf('%040x', $index), range(0, 500));
check($transmission->setLabel($hashes, 'label'), 'RPC 16: batched set failed');
check(count($history) === 2, 'RPC 16: 501 hashes must use exactly two requests');
check(count(requestArguments($history[0])['ids']) === 500, 'first batch size changed');
check(count(requestArguments($history[1])['ids']) === 1, 'second batch size changed');

$default = new class {
    use AllowedFunctions;
};
check($default->getPostAddLabelDelay(500) === 26, 'default post-add delay changed');

echo "Transmission label tests passed\n";
