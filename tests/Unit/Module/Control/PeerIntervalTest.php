<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\Tests\Unit\Module\Control;

use KeepersTeam\Webtlo\Module\Control\PeerInterval;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 *
 * @covers \KeepersTeam\Webtlo\Module\Control\PeerInterval
 */
final class PeerIntervalTest extends TestCase
{
    public function testDefaultPattern(): void
    {
        // Pattern without ":" meaning default 6-hour intervals
        $pattern      = '10/20/30/40';
        $peerInterval = new PeerInterval($pattern);

        // Test first interval (should be 0 to 6)
        self::assertSame(10, $peerInterval->getCurrentIntervalPeerLimit(0));
        self::assertSame(10, $peerInterval->getCurrentIntervalPeerLimit(5));

        // Test second interval (should be 6 to 12)
        self::assertSame(20, $peerInterval->getCurrentIntervalPeerLimit(6));
        self::assertSame(20, $peerInterval->getCurrentIntervalPeerLimit(11));

        // Test third interval (should be 12 to 18)
        self::assertSame(30, $peerInterval->getCurrentIntervalPeerLimit(12));
        self::assertSame(30, $peerInterval->getCurrentIntervalPeerLimit(17));

        // Test fourth interval (should be 18 to 24)
        self::assertSame(40, $peerInterval->getCurrentIntervalPeerLimit(18));
        self::assertSame(40, $peerInterval->getCurrentIntervalPeerLimit(23));
    }

    public function testPatternWithDuration(): void
    {
        // Custom intervals with explicit durations
        $pattern      = '5:3/10:6/20:12';
        $peerInterval = new PeerInterval($pattern);

        // Test first interval (should be 0 to 3 hours)
        self::assertSame(5, $peerInterval->getCurrentIntervalPeerLimit(0));
        self::assertSame(5, $peerInterval->getCurrentIntervalPeerLimit(2));

        // Test second interval (should be 3 to 9 hours)
        self::assertSame(10, $peerInterval->getCurrentIntervalPeerLimit(3));
        self::assertSame(10, $peerInterval->getCurrentIntervalPeerLimit(8));

        // Test third interval (should be 9 to 21 hours)
        self::assertSame(20, $peerInterval->getCurrentIntervalPeerLimit(9));
        self::assertSame(20, $peerInterval->getCurrentIntervalPeerLimit(20));

        // After 21st hour, there should be no interval (null expected)
        self::assertNull($peerInterval->getCurrentIntervalPeerLimit(21));
    }

    public function testTooManyIntervals(): void
    {
        // More than 24 intervals
        $pattern      = implode('/', array_fill(0, 30, '10'));
        $peerInterval = new PeerInterval($pattern);

        // Ensure that excess intervals are truncated (only 24 intervals should be used)
        self::assertSame(10, $peerInterval->getCurrentIntervalPeerLimit(0));
        self::assertSame(10, $peerInterval->getCurrentIntervalPeerLimit(23));

        // Any hour outside 0-23 should not have any intervals (null expected)
        self::assertNull($peerInterval->getCurrentIntervalPeerLimit(24));
    }

    public function testInvalidPattern(): void
    {
        // Non-numeric pattern should be ignored/replaced
        $pattern      = '10/abc/20';
        $peerInterval = new PeerInterval($pattern);

        self::assertSame(10, $peerInterval->getCurrentIntervalPeerLimit(0));
        self::assertSame(10, $peerInterval->getCurrentIntervalPeerLimit(11));

        self::assertSame(20, $peerInterval->getCurrentIntervalPeerLimit(12));
        self::assertSame(20, $peerInterval->getCurrentIntervalPeerLimit(23));
    }

    public function testEmptyPattern(): void
    {
        // Empty pattern should result in no intervals
        $peerInterval = new PeerInterval('');

        self::assertNull($peerInterval->getCurrentIntervalPeerLimit(0));
        self::assertNull($peerInterval->getCurrentIntervalPeerLimit(12));
    }
}
