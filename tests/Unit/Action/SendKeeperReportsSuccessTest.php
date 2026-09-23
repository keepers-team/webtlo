<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\Tests\Unit\Action;

use DateTimeImmutable;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use KeepersTeam\Webtlo\Action\SendKeeperReports;
use KeepersTeam\Webtlo\Config\ApiCredentials;
use KeepersTeam\Webtlo\Config\ReportSend as ReportSendConfig;
use KeepersTeam\Webtlo\Config\SubForums;
use KeepersTeam\Webtlo\Config\Telemetry;
use KeepersTeam\Webtlo\Config\TorrentClients;
use KeepersTeam\Webtlo\Enum\SendReportMethod;
use KeepersTeam\Webtlo\Enum\UpdateMark;
use KeepersTeam\Webtlo\External\ApiReportClient;
use KeepersTeam\Webtlo\Infrastructure\Database\ConnectionInterface;
use KeepersTeam\Webtlo\Module\Report\CreateReport;
use KeepersTeam\Webtlo\Module\Report\SendReport;
use KeepersTeam\Webtlo\Storage\Table\UpdateTime;
use KeepersTeam\Webtlo\WebTLO;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;
use Throwable;

/**
 * @internal
 *
 * @covers \KeepersTeam\Webtlo\Action\SendKeeperReports
 */
final class SendKeeperReportsSuccessTest extends TestCase
{
    public function testHashesMarkSuccessOnlyAfterBatchesAndFinalizationSucceed(): void
    {
        [$action, $handler] = $this->createAction(
            method       : SendReportMethod::Hash,
            responses    : [new Response(200, [], '[]'), new Response(200, [], '{}')],
            topics       : [['hash' => 'complete', 'author' => 2, 'done' => 1.0]],
            expectMarker : true,
        );

        self::assertTrue($this->invokeSend($action, 'sendHashesReports'));
        self::assertSame(0, $handler->count());
    }

    /**
     * @param array<int, array{hash: string, author: int, done: float}> $topics
     * @param Response[]                                                $responses
     */
    #[DataProvider('provideHashesFailuresNeverMarkAndConsumeRemainingRequestsCases')]
    public function testHashesFailuresNeverMarkAndConsumeRemainingRequests(array $topics, array $responses): void
    {
        [$action, $handler] = $this->createAction(
            method       : SendReportMethod::Hash,
            responses    : $responses,
            topics       : $topics,
            expectMarker : false,
        );

        self::assertFalse($this->invokeSend($action, 'sendHashesReports'));
        self::assertSame(0, $handler->count());
    }

    /**
     * @return iterable<string, array{array<int, array{hash: string, author: int, done: float}>, array<int, Response>}>
     */
    public static function provideHashesFailuresNeverMarkAndConsumeRemainingRequestsCases(): iterable
    {
        return [
            'partial report failure' => [
                [
                    ['hash' => 'complete', 'author' => 2, 'done' => 1.0],
                    ['hash' => 'downloading', 'author' => 2, 'done' => 0.5],
                ],
                [new Response(200, [], '{'), new Response(200, [], '[]'), new Response(200, [], '[]')],
            ],
            'all report failures'    => [
                [
                    ['hash' => 'complete', 'author' => 2, 'done' => 1.0],
                    ['hash' => 'downloading', 'author' => 2, 'done' => 0.5],
                ],
                [new Response(200, [], '{'), new Response(200, [], 'false'), new Response(200, [], '{}')],
            ],
            'finalization failure'   => [
                [['hash' => 'complete', 'author' => 2, 'done' => 1.0]],
                [new Response(200, [], '[]'), new Response(200, [], 'false')],
            ],
        ];
    }

    public function testSubsectionsMarkSuccessOnlyAfterAllReportsAndFinalizationSucceed(): void
    {
        [$action, $handler] = $this->createAction(
            method       : SendReportMethod::Subsection,
            responses    : [
                new Response(200, [], '{}'),
                new Response(200, [], '{"result":true}'), new Response(200, [], '[]'),
                new Response(200, [], '{"result":true}'), new Response(200, [], '{}'),
                new Response(200, [], '[]'),
            ],
            topics       : [['id' => 100, 'done' => 1.0]],
            expectMarker : true,
        );

        self::assertTrue($this->invokeSend($action, 'sendSubsectionsReports'));
        self::assertSame(0, $handler->count());
    }

    /**
     * @param Response[] $responses
     */
    #[DataProvider('provideSubsectionFailuresNeverMarkAndContinueOtherForumsCases')]
    public function testSubsectionFailuresNeverMarkAndContinueOtherForums(array $responses): void
    {
        [$action, $handler] = $this->createAction(
            method       : SendReportMethod::Subsection,
            responses    : $responses,
            topics       : [['id' => 100, 'done' => 1.0]],
            expectMarker : false,
        );

        self::assertFalse($this->invokeSend($action, 'sendSubsectionsReports'));
        self::assertSame(0, $handler->count());
    }

    /**
     * @return iterable<string, array{array<int, Response>}>
     */
    public static function provideSubsectionFailuresNeverMarkAndContinueOtherForumsCases(): iterable
    {
        return [
            'partial report failure' => [[
                new Response(200, [], '{}'),
                new Response(200, [], '{"result":true}'), new Response(200, [], '{'),
                new Response(200, [], '{"result":true}'), new Response(200, [], '[]'),
                new Response(200, [], '{}'),
            ]],
            'all report failures'    => [[
                new Response(200, [], '{}'),
                new Response(200, [], '{"result":true}'), new Response(200, [], '{'),
                new Response(200, [], '{"result":true}'), new Response(200, [], 'false'),
                new Response(200, [], '[]'),
            ]],
            'finalization failure'   => [[
                new Response(200, [], '{}'),
                new Response(200, [], '{"result":true}'), new Response(200, [], '[]'),
                new Response(200, [], '{"result":true}'), new Response(200, [], '{}'),
                new Response(200, [], '{'),
            ]],
        ];
    }

    public function testSubsectionsWithoutReportAttemptsReturnTrueWithoutMarking(): void
    {
        [$action, $handler] = $this->createAction(
            method       : SendReportMethod::Subsection,
            responses    : [new Response(200, [], '{}')],
            topics       : [],
            expectMarker : false,
        );

        self::assertTrue($this->invokeSend($action, 'sendSubsectionsReports'));
        self::assertSame(0, $handler->count());
    }

    public function testSubsectionsDatabaseFailureBeforeSendingReturnsFalseWithoutMarking(): void
    {
        [$action, $handler] = $this->createAction(
            method         : SendReportMethod::Subsection,
            responses      : [new Response(200, [], '{}'), new Response(200, [], '[]')],
            topics         : [],
            expectMarker   : false,
            queryException : new RuntimeException('database unavailable'),
        );

        self::assertFalse($this->invokeSend($action, 'sendSubsectionsReports'));
        self::assertSame(0, $handler->count());
    }

    public function testFilteredEmptyHashesWithFailedFinalizationReturnFalseWithoutMarking(): void
    {
        [$action, $handler] = $this->createAction(
            method          : SendReportMethod::Hash,
            responses       : [new Response(200, [], 'false')],
            topics          : [['hash' => 'authored', 'author' => 1, 'done' => 1.0]],
            expectMarker    : false,
            excludeAuthored : true,
        );

        self::assertFalse($this->invokeSend($action, 'sendHashesReports'));
        self::assertSame(0, $handler->count());
    }

    public function testFilteredEmptyHashesReturnTrueWithoutMarkingAfterSuccessfulFinalization(): void
    {
        [$action, $handler] = $this->createAction(
            method          : SendReportMethod::Hash,
            responses       : [new Response(200, [], '[]')],
            topics          : [['hash' => 'authored', 'author' => 1, 'done' => 1.0]],
            expectMarker    : false,
            excludeAuthored : true,
        );

        self::assertTrue($this->invokeSend($action, 'sendHashesReports'));
        self::assertSame(0, $handler->count());
    }

    /**
     * @param Response[]                                  $responses
     * @param array<int, array<string, int|float|string>> $topics
     *
     * @return array{SendKeeperReports, MockHandler}
     */
    private function createAction(
        SendReportMethod $method,
        array $responses,
        array $topics,
        bool $expectMarker,
        bool $excludeAuthored = false,
        ?Throwable $queryException = null,
    ): array {
        $connection = $this->createMock(ConnectionInterface::class);
        $pdo        = new PDO('sqlite::memory:');
        $statement  = $pdo->prepare('SELECT 1');
        self::assertNotFalse($statement);

        $markerExpectation = $expectMarker ? self::once() : self::never();
        $connection->expects($markerExpectation)
            ->method('executeStatement')
            ->with(
                self::stringContains('INSERT INTO UpdateTime'),
                self::callback(static fn(array $params): bool => $params[0] === UpdateMark::SEND_REPORT->value),
            )
            ->willReturn($statement)
        ;
        if ($queryException === null) {
            $connection->method('query')->willReturn($topics);
        } else {
            $connection->method('query')->willThrowException($queryException);
        }

        $logger      = new NullLogger();
        $credentials = new ApiCredentials(userId: 1, btKey: 'bt-key', apiKey: 'api-key');
        $handler     = new MockHandler($responses);
        $client      = new Client([
            'base_uri' => 'https://report-api.test/',
            'handler'  => HandlerStack::create($handler),
        ]);
        $apiReport = new ApiReportClient($client, $credentials, $logger);
        $report    = new SendReport(
            apiCredentials: $credentials,
            apiReport     : $apiReport,
            webtlo        : new WebTLO('4.6.0', '', '', '', '', '', ''),
        );
        $config = new ReportSendConfig(
            sendReports        : true,
            sendMethod         : $method,
            reporterId         : 0,
            sendTelemetry      : true,
            excludeAuthored    : $excludeAuthored,
            unsetOtherTopics   : false,
            unsetOtherSubForums: false,
            daysUpdateExpire   : 5,
            excludedSubForums  : [],
            excludedClients    : [],
            excludedKeepers    : [],
        );
        $updateTime = new UpdateTime($connection);
        $creator    = new CreateReport(
            db         : $connection,
            subForums  : new SubForums([], []),
            clients    : new TorrentClients([]),
            auth       : $credentials,
            reportSend : $config,
            telemetry  : new Telemetry([]),
            tableUpdate: $updateTime,
            webtlo     : new WebTLO('4.6.0', '', '', '', '', '', ''),
            logger     : $logger,
        );

        $creator->forums = [10, 20];
        $action          = new SendKeeperReports($config, $creator, $report, $updateTime, $logger);

        $fullUpdateTime = new ReflectionProperty($action, 'fullUpdateTime');
        $fullUpdateTime->setValue($action, new DateTimeImmutable('2026-09-23T00:00:00+00:00'));

        return [$action, $handler];
    }

    private function invokeSend(SendKeeperReports $action, string $method): bool
    {
        $reflection = new ReflectionMethod($action, $method);
        $result     = $reflection->invoke($action, false);
        self::assertIsBool($result);

        return $result;
    }
}
