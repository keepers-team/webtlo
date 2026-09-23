<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\Tests\Unit\Module\Report;

use DateTimeImmutable;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use KeepersTeam\Webtlo\Config\ApiCredentials;
use KeepersTeam\Webtlo\External\ApiReportClient;
use KeepersTeam\Webtlo\Module\Report\ReportStatus;
use KeepersTeam\Webtlo\Module\Report\SendReport;
use KeepersTeam\Webtlo\WebTLO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * @internal
 *
 * @covers \KeepersTeam\Webtlo\External\ApiReport\Actions\SendReportTrait
 * @covers \KeepersTeam\Webtlo\Module\Report\SendReport
 */
final class SendReportTest extends TestCase
{
    #[DataProvider('provideSendReportHashesAcceptsOnlyJsonArraysCases')]
    public function testSendReportHashesAcceptsOnlyJsonArrays(mixed $response, bool $success): void
    {
        $report = $this->createReportSender([$response]);

        $result = $report->sendReportHashes(
            hashes    : ['first-hash'],
            reportDate: new DateTimeImmutable('2026-09-23T00:00:00+00:00'),
            status    : 1,
        );

        self::assertSame($success, $result['success']);
        if ($success) {
            self::assertArrayHasKey('result', $result);
            self::assertSame([], $result['result'] ?? null);
        } else {
            self::assertArrayNotHasKey('result', $result);
        }
    }

    /**
     * @return iterable<string, array{mixed, bool}>
     */
    public static function provideSendReportHashesAcceptsOnlyJsonArraysCases(): iterable
    {
        return [
            'empty JSON array is a valid API response' => [new Response(200, [], '[]'), true],
            'malformed JSON fails'                     => [new Response(200, [], '{'), false],
            'scalar JSON fails'                        => [new Response(200, [], 'true'), false],
            'transport failure fails'                  => [new ConnectException('offline', new Request('POST', '/')), false],
        ];
    }

    public function testSendForumTopicsCombinesForumAndReleaseResults(): void
    {
        $report = $this->createReportSender([
            new Response(200, [], '{"result":false}'),
            new Response(200, [], '{}'),
        ]);

        $result = $report->sendForumTopics(
            forumId       : 123,
            topicsToReport: [['id' => 456, 'done' => 1.0]],
            reportDate    : new DateTimeImmutable('2026-09-23T00:00:00+00:00'),
            statusRules   : new ReportStatus(subForum: 1, keptTopics: 2, downloadingTopics: 3),
        );

        self::assertFalse($result['success']);
        self::assertArrayHasKey('reportComplete', $result);
        self::assertSame([], $result['reportComplete'] ?? null);
    }

    /**
     * @param mixed[] $responses
     */
    private function createReportSender(array $responses): SendReport
    {
        $handler = new MockHandler($responses);
        $client  = new Client([
            'base_uri' => 'https://report-api.test/',
            'handler'  => HandlerStack::create($handler),
        ]);
        $credentials = new ApiCredentials(userId: 1, btKey: 'bt-key', apiKey: 'api-key');

        return new SendReport(
            apiCredentials: $credentials,
            apiReport     : new ApiReportClient($client, $credentials, new NullLogger()),
            webtlo        : new WebTLO('4.6.0', '', '', '', '', '', ''),
        );
    }
}
