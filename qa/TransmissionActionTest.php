<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use KeepersTeam\Webtlo\Clients\Transmission;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\NullLogger;

require dirname(__DIR__) . '/vendor/autoload.php';

/**
 * @param array<int, ResponseInterface|Throwable> $replies
 * @param array<int, array<string, mixed>> $history
 */
function makeTransmission(array $replies, array &$history): Transmission
{
    $stack = HandlerStack::create(new MockHandler($replies));
    $stack->push(Middleware::history($history));

    $reflection = new ReflectionClass(Transmission::class);
    $transmission = $reflection->newInstanceWithoutConstructor();
    $reflection->getProperty('logger')->setValue($transmission, new NullLogger());
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

$request = new GuzzleHttp\Psr7\Request('POST', 'http://localhost/transmission/rpc');
$cases = [
    'success'            => [new GuzzleHttp\Psr7\Response(200, [], '{"arguments":{},"result":"success"}'), true],
    'rpc error'          => [new GuzzleHttp\Psr7\Response(200, [], '{"arguments":{},"result":"invalid argument"}'), false],
    'invalid json'       => [new GuzzleHttp\Psr7\Response(200, [], '<html>error</html>'), false],
    'missing result'     => [new GuzzleHttp\Psr7\Response(200, [], '{"arguments":{}}'), false],
    'non-string result'  => [new GuzzleHttp\Psr7\Response(200, [], '{"result":true}'), false],
    'http error'         => [new GuzzleHttp\Psr7\Response(500), false],
    'connection failure' => [new ConnectException('Connection failed', $request), false],
];

foreach ($cases as $name => [$reply, $expected]) {
    $history = [];
    $transmission = makeTransmission([$reply], $history);
    check($transmission->startTorrents(['hash']) === $expected, "$name: wrong action result");
    check(count($history) === 1, "$name: unexpected RPC request count");
}

$history = [];
$transmission = makeTransmission([], $history);
check($transmission->startTorrents([]), 'empty action must succeed');
check($history === [], 'empty action must not send an RPC request');

$history = [];
$transmission = makeTransmission([
    new GuzzleHttp\Psr7\Response(200, [], '{"result":"failed"}'),
    new GuzzleHttp\Psr7\Response(200, [], '{"result":"success"}'),
], $history);
$hashes = array_map(static fn(int $index): string => sprintf('%040x', $index), range(0, 500));
check(!$transmission->startTorrents($hashes), 'one failed batch must fail the action');
check(count($history) === 2, '501 hashes must result in exactly two RPC requests');

foreach ($history as $index => $transaction) {
    $body = json_decode((string) $transaction['request']->getBody(), true);
    check(($body['method'] ?? null) === 'torrent-start', 'batch method changed');
    check(count($body['arguments']['ids'] ?? []) === ($index === 0 ? 500 : 1), 'batch size changed');
}

echo "Transmission action tests passed\n";
