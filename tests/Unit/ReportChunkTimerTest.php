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
use ReflectionProperty;

/**
 * @internal
 *
 * @covers \KeepersTeam\Webtlo\Action\SendKeeperReports
 */
final class ReportChunkTimerTest extends TestCase
{
    public function testEachChunkLogsItsOwnElapsedTime(): void
    {
        $markers = new ReflectionProperty(Timers::class, 'markers');
        $markers->setAccessible(true);
        $savedMarkers = $markers->getValue();
        $markers->setValue(null, []);

        try {
            // Advance the started timers without sleeping or making real requests.
            $response = static function() use ($markers): Response {
                /** @var array<string, array<string, mixed>> $values */
                $values = $markers->getValue();
                foreach ($values as &$value) {
                    if (isset($value['start'])) {
                        $value['start'] = microtime(true) - 5;
                    }
                }
                unset($value);
                $markers->setValue(null, $values);

                return new Response(200, [], '{}');
            };
            $handler = new MockHandler([$response, $response, new Response(200, [], '{}')]);
            $log     = new TestHandler();
            $logger  = new Logger('test', [$log]);
            $auth    = new ApiCredentials(1, 'test-bt-key', 'test-api-key');
            $config  = new ReportSend(true, SendReportMethod::Hash, 0, false, false, false, false, 5, [], [], []);
            $webtlo  = new WebTLO('test', '', '', '', '', 'test', '');

            $db = $this->createMock(ConnectionInterface::class);
            $db->expects(self::once())->method('query')->willReturn([
                ['hash' => str_repeat('a', 40), 'author' => 2, 'done' => 1.0],
                ['hash' => str_repeat('b', 40), 'author' => 2, 'done' => 0.5],
            ]);
            $updateTime = new UpdateTime($db);
            $creator    = new CreateReport(
                $db,
                new SubForums([1], []),
                new TorrentClients([]),
                $auth,
                $config,
                new Telemetry([]),
                $updateTime,
                $webtlo,
                $logger,
            );
            $creator->forums = [1];
            $report          = new SendReport(
                $auth,
                new ApiReportClient(new Client(['handler' => $handler]), $auth, $logger),
                $webtlo,
            );
            $action = new SendKeeperReports($config, $creator, $report, $updateTime, $logger);

            $reportDate = new ReflectionProperty(SendKeeperReports::class, 'fullUpdateTime');
            $reportDate->setAccessible(true);
            $reportDate->setValue($action, new DateTimeImmutable());
            $send = new ReflectionMethod(SendKeeperReports::class, 'sendHashesReports');
            $send->setAccessible(true);
            $send->invoke($action, false);

            $chunks = [];
            foreach ($log->getRecords() as $record) {
                if ($record->message === 'API. Отчёт отправлен [{current}] {sec}') {
                    $chunks[] = $record->context;
                }
            }

            self::assertCount(2, $chunks);
            self::assertSame([1, 2], array_column($chunks, 'current'));
            foreach ($chunks as $chunk) {
                self::assertNotSame('0s', $chunk['sec']);
            }
            self::assertSame(0, $handler->count());
        } finally {
            $markers->setValue(null, $savedMarkers);
        }
    }
}
