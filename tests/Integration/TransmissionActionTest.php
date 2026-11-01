<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\Tests\Integration;

use ArrayObject;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use KeepersTeam\Webtlo\Clients\Transmission;
use KeepersTeam\Webtlo\Timers;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\NullLogger;
use ReflectionClass;
use Throwable;

/**
 * Проверяет массовые действия над раздачами и чтение списка раздач
 * Transmission RPC на подменённом HTTP-клиенте (Guzzle MockHandler).
 *
 * @internal
 *
 * @covers \KeepersTeam\Webtlo\Clients\Transmission
 */
final class TransmissionActionTest extends TestCase
{
    private const RPC_URI = 'http://localhost/transmission/rpc';

    protected function setUp(): void
    {
        // getTorrents() пишет в глобальный Timers.
        Timers::reset();
    }

    protected function tearDown(): void
    {
        Timers::reset();
    }

    /**
     * Действие успешно только при HTTP 200 и ответе RPC с result = success.
     * Любая ошибка уровня HTTP, JSON или RPC обязана возвращать false.
     */
    #[DataProvider('provideSingleHashActionOutcomeCases')]
    public function testSingleHashActionOutcome(ResponseInterface|Throwable $reply, bool $expected): void
    {
        [$transmission, $history] = self::createClient(replies: [$reply]);

        self::assertSame($expected, $transmission->startTorrents(self::makeHashes(1)));

        self::assertCount(1, $history);
    }

    /**
     * @return iterable<string, array{ResponseInterface|Throwable, bool}>
     */
    public static function provideSingleHashActionOutcomeCases(): iterable
    {
        return [
            'success with empty arguments' => [self::successResponse(), true],
            'success without arguments'    => [new Response(200, [], '{"result":"success"}'), true],
            'rpc error at http 200'        => [self::rpcFailureResponse(), false],
            'invalid json'                 => [new Response(200, [], '<html lang="en">Bad Gateway</html>'), false],
            'empty body'                   => [new Response(200), false],
            'scalar json null'             => [new Response(200, [], 'null'), false],
            'scalar json true'             => [new Response(200, [], 'true'), false],
            'scalar json false'            => [new Response(200, [], 'false'), false],
            'scalar json integer'          => [new Response(200, [], '42'), false],
            'scalar json float'            => [new Response(200, [], '4.5'), false],
            'scalar json string'           => [new Response(200, [], '"success"'), false],
            'empty object without result'  => [new Response(200, [], '{}'), false],
            'empty array without result'   => [new Response(200, [], '[]'), false],
            'arguments without result'     => [new Response(200, [], '{"arguments":{}}'), false],
            'result null'                  => [new Response(200, [], '{"result":null}'), false],
            'result boolean'               => [new Response(200, [], '{"result":true}'), false],
            'result integer'               => [new Response(200, [], '{"result":1}'), false],
            'result array'                 => [new Response(200, [], '{"result":["success"]}'), false],
            'http 202 with success body'   => [new Response(202, [], '{"result":"success"}'), false],
            'http 400 with success body'   => [new Response(400, [], '{"result":"success"}'), false],
            'http 500'                     => [new Response(500), false],
            'connection failure'           => [
                new ConnectException('Connection refused', new Request('POST', 'transmission/rpc')),
                false,
            ],
        ];
    }

    public function testEmptyHashListSucceedsWithoutRpcRequests(): void
    {
        [$transmission, $history] = self::createClient(replies: []);

        self::assertTrue($transmission->startTorrents([]));
        self::assertCount(0, $history);
    }

    /**
     * Ошибка любой пачки фейлит всё действие, при этом обработка
     * не прерывается: обе пачки уходят в торрент-клиент в любом сценарии.
     *
     * @param array<int, ResponseInterface> $replies
     */
    #[DataProvider('provideActionResultForTwoChunksCases')]
    public function testActionResultForTwoChunks(array $replies, bool $expected): void
    {
        [$transmission, $history] = self::createClient(replies: $replies);
        $hashes                   = self::makeHashes(501);

        self::assertSame($expected, $transmission->startTorrents($hashes));

        // Обе пачки отправлены, сбой одной не прерывает обработку.
        self::assertCount(2, $history);

        $firstChunk  = self::decodeRequestBody($history[0]);
        $secondChunk = self::decodeRequestBody($history[1]);

        self::assertSame('torrent-start', $firstChunk['method'] ?? null);
        self::assertSame(array_slice($hashes, 0, 500), $firstChunk['arguments']['ids'] ?? null);

        self::assertSame('torrent-start', $secondChunk['method'] ?? null);
        self::assertSame(array_slice($hashes, 500), $secondChunk['arguments']['ids'] ?? null);
    }

    /**
     * @return iterable<string, array{array<int, ResponseInterface>, bool}>
     */
    public static function provideActionResultForTwoChunksCases(): iterable
    {
        return [
            'all chunks succeed' => [[self::successResponse(), self::successResponse()], true],
            'first chunk fails'  => [[self::rpcFailureResponse(), self::successResponse()], false],
            'last chunk fails'   => [[self::successResponse(), self::rpcFailureResponse()], false],
        ];
    }

    /**
     * @param callable(Transmission): bool $action
     * @param array<string, mixed>         $expectedArguments
     */
    #[DataProvider('provideActionSendsExpectedRpcMethodAndArgumentsCases')]
    public function testActionSendsExpectedRpcMethodAndArguments(
        callable $action,
        string $expectedMethod,
        array $expectedArguments,
    ): void {
        [$transmission, $history] = self::createClient(replies: [self::successResponse()]);

        self::assertTrue($action($transmission));
        self::assertCount(1, $history);

        $body = self::decodeRequestBody($history[0]);

        self::assertSame($expectedMethod, $body['method'] ?? null);
        self::assertSame($expectedArguments, $body['arguments'] ?? null);
    }

    /**
     * @return iterable<string, array{callable(Transmission): bool, string, array<string, mixed>}>
     */
    public static function provideActionSendsExpectedRpcMethodAndArgumentsCases(): iterable
    {
        $hash = str_repeat('ab', 20);

        return [
            'start'               => [
                static fn(Transmission $client): bool => $client->startTorrents(torrentHashes: [$hash]),
                'torrent-start',
                ['ids' => [$hash]],
            ],
            'force start'         => [
                static fn(Transmission $client): bool => $client->startTorrents(torrentHashes: [$hash], forceStart: true),
                'torrent-start-now',
                ['ids' => [$hash]],
            ],
            'stop'                => [
                static fn(Transmission $client): bool => $client->stopTorrents(torrentHashes: [$hash]),
                'torrent-stop',
                ['ids' => [$hash]],
            ],
            'recheck'             => [
                static fn(Transmission $client): bool => $client->recheckTorrents(torrentHashes: [$hash]),
                'torrent-verify',
                ['ids' => [$hash]],
            ],
            'remove without data' => [
                static fn(Transmission $client): bool => $client->removeTorrents(torrentHashes: [$hash]),
                'torrent-remove',
                ['ids' => [$hash], 'delete-local-data' => false],
            ],
            'remove with data'    => [
                static fn(Transmission $client): bool => $client->removeTorrents(torrentHashes: [$hash], deleteFiles: true),
                'torrent-remove',
                ['ids' => [$hash], 'delete-local-data' => true],
            ],
        ];
    }

    /**
     * Путь чтения использует общий validateResponse: корректный ответ
     * с пустым arguments.torrents возвращает пустой набор раздач.
     */
    public function testGetTorrentsReturnsEmptyListWhenNoTorrents(): void
    {
        [$transmission, $history] = self::createClient(replies: [
            new Response(200, [], '{"arguments":{"torrents":[]},"result":"success"}'),
        ]);

        $torrents = $transmission->getTorrents();

        self::assertTrue($torrents->empty());
        self::assertCount(0, $torrents);
        self::assertSame([], $torrents->getHashes());

        self::assertCount(1, $history);

        $body = self::decodeRequestBody($history[0]);
        self::assertSame('torrent-get', $body['method'] ?? null);
        self::assertContains('hashString', $body['arguments']['fields'] ?? []);
    }

    /**
     * Собирает клиент Transmission без вызова конструктора: HTTP-клиент
     * подменён Guzzle MockHandler, каждый выполненный запрос попадает
     * в историю Middleware::history.
     *
     * @param array<int, ResponseInterface|Throwable> $replies
     *
     * @return array{Transmission, ArrayObject<int, array<array-key, mixed>>}
     */
    private static function createClient(array $replies): array
    {
        /** @var ArrayObject<int, array<array-key, mixed>> $history */
        $history = new ArrayObject();

        // Guzzle принимает контейнер по ссылке; сохраняем типизированный объект истории отдельно.
        $container = $history;

        $stack = HandlerStack::create(new MockHandler($replies));
        $stack->push(Middleware::history($container));

        $reflection   = new ReflectionClass(Transmission::class);
        $transmission = $reflection->newInstanceWithoutConstructor();

        $reflection->getProperty('logger')->setValue($transmission, new NullLogger());
        $reflection->getProperty('client')->setValue($transmission, new Client([
            'base_uri' => self::RPC_URI,
            'handler'  => $stack,
        ]));

        return [$transmission, $history];
    }

    /**
     * Декодирует тело RPC-запроса из записи истории Guzzle.
     *
     * @param null|array<array-key, mixed> $transaction
     *
     * @return array<array-key, mixed>
     */
    private static function decodeRequestBody(?array $transaction): array
    {
        self::assertNotNull($transaction);

        $request = $transaction['request'] ?? null;
        self::assertInstanceOf(RequestInterface::class, $request);

        $decoded = json_decode(
            json: (string) $request->getBody(),
            associative: true,
            flags: JSON_THROW_ON_ERROR,
        );

        if (!is_array($decoded)) {
            self::fail('RPC-запрос содержит некорректное тело: ожидается JSON-объект');
        }

        return $decoded;
    }

    /**
     * Уникальные хеши фиксированной длины 40 символов.
     *
     * @return string[]
     */
    private static function makeHashes(int $count): array
    {
        return array_map(
            static fn(int $index): string => sprintf('%040x', $index),
            range(0, $count - 1),
        );
    }

    private static function successResponse(): Response
    {
        return new Response(200, [], '{"arguments":{},"result":"success"}');
    }

    private static function rpcFailureResponse(): Response
    {
        return new Response(200, [], '{"arguments":{},"result":"unrecognized info"}');
    }
}
