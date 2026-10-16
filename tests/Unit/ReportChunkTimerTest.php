<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\Tests\Unit;

use DateTimeImmutable;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response;
use KeepersTeam\Webtlo\Action\SendKeeperReports;
use KeepersTeam\Webtlo\Config\ApiCredentials;
use KeepersTeam\Webtlo\Config\ReportSend;
use KeepersTeam\Webtlo\Config\SubForums;
use KeepersTeam\Webtlo\Config\Telemetry;
use KeepersTeam\Webtlo\Config\TorrentClients;
use KeepersTeam\Webtlo\Enum\SendReportMethod;
use KeepersTeam\Webtlo\External\ApiReportClient;
use KeepersTeam\Webtlo\Infrastructure\Database\ConnectionInterface;
use KeepersTeam\Webtlo\Module\Report\CreateReport;
use KeepersTeam\Webtlo\Module\Report\SendReport;
use KeepersTeam\Webtlo\Storage\Table\UpdateTime;
use KeepersTeam\Webtlo\Timers;
use KeepersTeam\Webtlo\WebTLO;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Symfony\Component\Clock\MockClock;

/**
 * @internal
 *
 * @covers \KeepersTeam\Webtlo\Action\SendKeeperReports::sendHashesReports
 */
final class ReportChunkTimerTest extends TestCase
{
    private MockClock $clock;

    protected function setUp(): void
    {
        $this->clock = new MockClock();
        Timers::setClock($this->clock);
        Timers::reset();
    }

    protected function tearDown(): void
    {
        Timers::setClock(null);
        Timers::reset();
    }

    public function testEachChunkLogsItsOwnElapsedTime(): void
    {
        $clock = $this->clock;

        $response = static function() use ($clock): Response {
            $clock->modify('+5 seconds');

            return new Response(200, [], '{}');
        };

        $guzzleHandler = new MockHandler([$response, $response, new Response(200, [], '{}')]);

        $logHandler = new TestHandler();
        $logger     = new Logger('test', [$logHandler]);

        $db = $this->createMock(ConnectionInterface::class);
        $db->expects(self::once())->method('query')->willReturn([
            ['hash' => str_repeat('a', 40), 'author' => 2, 'done' => 1.0],
            ['hash' => str_repeat('b', 40), 'author' => 2, 'done' => 0.5],
        ]);

        $updateTime = new UpdateTime($db);

        $auth   = new ApiCredentials(1, 'test-bt-key', 'test-api-key');
        $webtlo = new WebTLO('test', '', '', '', '', 'test', '');

        $config = new ReportSend(
            sendReports        : true,
            sendMethod         : SendReportMethod::Hash,
            reporterId         : 0,
            sendTelemetry      : false,
            excludeAuthored    : false,
            unsetOtherTopics   : false,
            unsetOtherSubForums: false,
            daysUpdateExpire   : 5,
            excludedSubForums  : [],
            excludedClients    : [],
            excludedKeepers    : []
        );

        $creator = new CreateReport(
            db         : $db,
            subForums  : new SubForums([1], []),
            clients    : new TorrentClients([]),
            auth       : $auth,
            reportSend : $config,
            telemetry  : new Telemetry([]),
            tableUpdate: $updateTime,
            webtlo     : $webtlo,
            logger     : $logger,
        );

        $sendReport = new SendReport(
            apiCredentials: $auth,
            apiReport     : new ApiReportClient(new Client(['handler' => $guzzleHandler]), $auth, $logger),
            webtlo        : $webtlo,
        );

        $sender = new SendKeeperReports(
            configReport: $config,
            createReport: $creator,
            sendReport  : $sendReport,
            updateTime  : $updateTime,
            logger      : $logger
        );

        // Вызываем отправку отчётов по хешам.
        $action = new ReflectionMethod(SendKeeperReports::class, 'sendHashesReports');
        $action->invoke($sender, new DateTimeImmutable(), false);

        // Собираем подходящие записи.
        $records = [];
        foreach ($logHandler->getRecords() as $record) {
            if ($record->message === 'API. Отчёт отправлен [{current}] {sec}') {
                $records[] = $record->context;
            }
        }

        self::assertCount(2, $records);
        self::assertSame([1, 2], array_column($records, 'current'));
        foreach ($records as $context) {
            self::assertSame('5s', $context['sec']);
        }

        self::assertSame(0, $guzzleHandler->count());
    }
}
