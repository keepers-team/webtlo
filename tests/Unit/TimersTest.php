<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\Tests\Unit;

use KeepersTeam\Webtlo\Timers;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/**
 * @internal
 *
 * @covers \KeepersTeam\Webtlo\Timers
 */
final class TimersTest extends TestCase
{
    private MockClock $clock;

    protected function setUp(): void
    {
        $this->clock = new MockClock('2024-01-01 00:00:00');
        Timers::setClock($this->clock);
        Timers::reset();
    }

    protected function tearDown(): void
    {
        Timers::setClock(null);
        Timers::reset();
    }

    public function testExecTimeReflectsClockAdvance(): void
    {
        Timers::start('t');
        $this->clock->sleep(5);

        self::assertSame('5s', Timers::getExecTime('t'));
    }

    public function testExecTimeWithoutStartReturnsZero(): void
    {
        self::assertSame('0s', Timers::getExecTime('never-started'));
    }

    public function testDefaultMarkerIsUsedWhenNotPassed(): void
    {
        Timers::start();
        $this->clock->sleep(3);

        self::assertSame('3s', Timers::getExecTime());
    }

    public function testMarkersAreIsolated(): void
    {
        Timers::start('a');
        Timers::start('b');

        $this->clock->sleep(2);
        self::assertSame('2s', Timers::getExecTime('a'));

        $this->clock->sleep(3);
        self::assertSame('5s', Timers::getExecTime('b'));
    }

    public function testExecTimeIsFrozenAfterStop(): void
    {
        Timers::start('t');
        $this->clock->sleep(3);
        Timers::stop('t');

        $this->clock->sleep(7);

        self::assertSame('3s', Timers::getExecTime('t'));
    }

    public function testGetExecTimeFreezesMarkerOnFirstRead(): void
    {
        Timers::start('t');

        $this->clock->sleep(3);
        $first = Timers::getExecTime('t');

        $this->clock->sleep(7);
        $second = Timers::getExecTime('t');

        self::assertSame('3s', $first);
        self::assertSame($first, $second, 'Повторное чтение не должно менять зафиксированное значение.');
    }

    public function testStartResetsFrozenMarker(): void
    {
        Timers::start('t');
        $this->clock->sleep(3);
        self::assertSame('3s', Timers::getExecTime('t'));

        // Новый замер с чистого листа.
        Timers::start('t');
        $this->clock->sleep(5);

        self::assertSame('5s', Timers::getExecTime('t'));
    }

    public function testStashStoresFormattedTime(): void
    {
        Timers::start('a');
        $this->clock->sleep(5);
        Timers::stash('a');

        self::assertSame(['a' => '5s'], Timers::getStash());
    }

    public function testStashStoresMultipleMarkers(): void
    {
        Timers::start('a');
        $this->clock->sleep(2);
        Timers::stash('a');

        Timers::start('b');
        $this->clock->sleep(3);
        Timers::stash('b');

        self::assertSame(['a' => '2s', 'b' => '3s'], Timers::getStash());
    }

    public function testGetStashClearsStashAfterRead(): void
    {
        Timers::start('a');
        $this->clock->sleep(5);
        Timers::stash('a');

        self::assertSame(['a' => '5s'], Timers::getStash());
        self::assertSame([], Timers::getStash(), 'Повторное чтение должно вернуть пустой stash.');
    }

    public function testResetClearsMarkersAndStash(): void
    {
        Timers::start('a');
        $this->clock->sleep(5);
        Timers::stash('a');

        Timers::reset();

        // Маркер стёрт — как будто start() не вызывали.
        self::assertSame('0s', Timers::getExecTime('a'));
        // Stash пуст.
        self::assertSame([], Timers::getStash());
    }

    public function testFallsBackToSystemClockWhenNotSet(): void
    {
        Timers::setClock(null);

        Timers::start('t');

        // Проверяем только что не падает и возвращает строку вида "5s"/"0s".
        self::assertMatchesRegularExpression('/^\d+[smhd]?$/', Timers::getExecTime('t'));
    }
}
