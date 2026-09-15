<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\Tests\Unit;

use KeepersTeam\Webtlo\Helper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @internal
 *
 * @coversNothing
 */
final class HelperTest extends TestCase
{
    private string $tmpDir;
    private string|false $originalStorageDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/webtlo_test_' . uniqid('', true);
        mkdir($this->tmpDir, 0o777, true);

        // Сохраняем исходное значение переменной и подменяем на тестовую
        $this->originalStorageDir = getenv('WEBTLO_DIR');
        putenv('WEBTLO_DIR=' . $this->tmpDir);
    }

    protected function tearDown(): void
    {
        if ($this->originalStorageDir === false) {
            putenv('WEBTLO_DIR');
        } else {
            putenv('WEBTLO_DIR=' . $this->originalStorageDir);
        }

        if (is_dir($this->tmpDir)) {
            Helper::removeDirRecursive($this->tmpDir);
        }
    }

    // -----------------------------------------------------------------
    // convertBytes
    // -----------------------------------------------------------------

    #[DataProvider('provideConvertBytesCases')]
    public function testConvertBytes(int $size, int $maxPow, string $expected): void
    {
        self::assertSame($expected, Helper::convertBytes($size, $maxPow));
    }

    /**
     * @return iterable<string, array{int, int, string}>
     */
    public static function provideConvertBytesCases(): iterable
    {
        return [
            'zero'          => [0, 3, '0 B'],
            'negative'      => [-100, 3, '0 B'],
            'one byte'      => [1, 3, '1 B'],
            '1023 bytes'    => [1023, 3, '1023 B'],
            '1 KB'          => [1024, 3, '1 KB'],
            '1.5 KB'        => [1536, 3, '1.5 KB'],
            '1 MB'          => [1024 ** 2, 3, '1 MB'],
            '1 GB'          => [1024 ** 3, 3, '1 GB'],
            '5 GB'          => [5 * 1024 ** 3, 3, '5 GB'],
            '1 TB'          => [1024 ** 4, 3, '1024 GB'],
            '1 TB maxPow=4' => [1024 ** 4, 4, '1 TB'],
            '1 PB maxPow=5' => [1024 ** 5, 5, '1 PB'],
        ];
    }

    // -----------------------------------------------------------------
    // convertSeconds
    // -----------------------------------------------------------------

    #[DataProvider('provideConvertSecondsCases')]
    public function testConvertSeconds(int $seconds, bool $leadZeros, string $expected): void
    {
        self::assertSame($expected, Helper::convertSeconds($seconds, $leadZeros));
    }

    /**
     * @return iterable<string, array{int, bool, string}>
     */
    public static function provideConvertSecondsCases(): iterable
    {
        return [
            'zero'                  => [0, false, '0s'],
            'zero with zeros'       => [0, true, '00s'],
            'negative'              => [-5, false, '-5s'],
            'negative with zeros'   => [-5, true, '-5s'],
            'seconds only'          => [30, false, '30s'],
            'seconds with zeros'    => [5, true, '05s'],
            'one minute'            => [60, false, '1m 0s'],
            'one minute with zeros' => [60, true, '01m 00s'],
            'minutes and seconds'   => [125, false, '2m 5s'],
            'minutes pad'           => [125, true, '02m 05s'],
            'one hour'              => [3600, false, '1h 0m 0s'],
            'one hour padded'       => [3600, true, '01h 00m 00s'],
            'full'                  => [3661, false, '1h 1m 1s'],
            'full padded'           => [3661, true, '01h 01m 01s'],
        ];
    }

    // -----------------------------------------------------------------
    // makeDirRecursive / checkDirRecursive
    // -----------------------------------------------------------------

    public function testMakeDirRecursiveCreatesNestedDirectories(): void
    {
        $path = $this->tmpDir . '/a/b/c';

        self::assertTrue(Helper::makeDirRecursive($path));
        self::assertDirectoryExists($path);
    }

    public function testMakeDirRecursiveReturnsTrueWhenAlreadyExists(): void
    {
        $path = $this->tmpDir . '/existing';
        mkdir($path);

        self::assertTrue(Helper::makeDirRecursive($path));
        self::assertDirectoryExists($path);
    }

    public function testMakeDirRecursiveReturnsFalseWhenPathIsAFile(): void
    {
        $path = $this->tmpDir . '/file.txt';
        file_put_contents($path, 'data');

        self::assertFalse(Helper::makeDirRecursive($path));
    }

    public function testCheckDirRecursiveCreatesDirectory(): void
    {
        $path = $this->tmpDir . '/created';

        Helper::checkDirRecursive($path);

        self::assertDirectoryExists($path);
    }

    public function testCheckDirRecursiveThrowsWhenPathIsAFile(): void
    {
        $path = $this->tmpDir . '/file.txt';
        file_put_contents($path, 'data');

        $this->expectException(RuntimeException::class);

        Helper::checkDirRecursive($path);
    }

    // -----------------------------------------------------------------
    // removeDirRecursive
    // -----------------------------------------------------------------

    public function testRemoveDirRecursiveReturnsTrueForMissingPath(): void
    {
        self::assertTrue(Helper::removeDirRecursive($this->tmpDir . '/nope'));
    }

    public function testRemoveDirRecursiveRemovesFile(): void
    {
        $path = $this->tmpDir . '/file.txt';
        file_put_contents($path, 'data');

        self::assertTrue(Helper::removeDirRecursive($path));
        self::assertFileDoesNotExist($path);
    }

    public function testRemoveDirRecursiveRemovesEmptyDirectory(): void
    {
        $path = $this->tmpDir . '/empty';
        mkdir($path);

        self::assertTrue(Helper::removeDirRecursive($path));
        self::assertDirectoryDoesNotExist($path);
    }

    public function testRemoveDirRecursiveRemovesNestedStructure(): void
    {
        $path = $this->tmpDir . '/deep';
        mkdir($path . '/sub/inner', 0o777, true);
        file_put_contents($path . '/root.txt', 'r');
        file_put_contents($path . '/sub/one.txt', '1');
        file_put_contents($path . '/sub/inner/two.txt', '2');

        self::assertTrue(Helper::removeDirRecursive($path));
        self::assertDirectoryDoesNotExist($path);
    }

    // -----------------------------------------------------------------
    // getProjectRoot / getStorageDir
    // -----------------------------------------------------------------

    public function testGetProjectRootReturnsExistingDirectory(): void
    {
        $root = Helper::getProjectRoot();

        self::assertDirectoryExists($root);
        self::assertDirectoryExists($root . '/src');
    }

    public function testGetStorageDirUsesEnvVariable(): void
    {
        putenv('WEBTLO_DIR=/custom/storage/path');

        self::assertSame('/custom/storage/path', Helper::getStorageDir());
    }

    public function testGetStorageDirFallsBackToProjectRoot(): void
    {
        putenv('WEBTLO_DIR');

        self::assertSame(
            Helper::getProjectRoot() . '/data',
            Helper::getStorageDir()
        );
    }

    // -----------------------------------------------------------------
    // getStorageSubFolderPath / getStorageLogsPath / getPathWithFile
    // -----------------------------------------------------------------

    public function testGetStorageSubFolderPathWithoutArguments(): void
    {
        self::assertSame($this->tmpDir, Helper::getStorageSubFolderPath());
    }

    public function testGetStorageSubFolderPathWithSubFolderCreatesIt(): void
    {
        $path = Helper::getStorageSubFolderPath('logs');

        self::assertSame($this->tmpDir . '/logs', $path);
        self::assertDirectoryExists($path);
    }

    public function testGetStorageSubFolderPathWithFileOnly(): void
    {
        $path = Helper::getStorageSubFolderPath(null, 'app.log');

        self::assertSame($this->tmpDir . '/app.log', $path);
    }

    public function testGetStorageSubFolderPathWithSubFolderAndFile(): void
    {
        $path = Helper::getStorageSubFolderPath('logs', 'app.log');

        self::assertSame($this->tmpDir . '/logs/app.log', $path);
        self::assertDirectoryExists($this->tmpDir . '/logs');
    }

    public function testGetStorageLogsPathWithoutFile(): void
    {
        $path = Helper::getStorageLogsPath();

        self::assertSame($this->tmpDir . '/logs', $path);
        self::assertDirectoryExists($path);
    }

    public function testGetStorageLogsPathWithFile(): void
    {
        $path = Helper::getStorageLogsPath('app.log');

        self::assertSame($this->tmpDir . '/logs/app.log', $path);
    }

    public function testGetPathWithFileJoinsAndNormalizes(): void
    {
        self::assertSame(
            $this->tmpDir . '/sub/file.txt',
            Helper::getPathWithFile($this->tmpDir . '/sub', 'file.txt')
        );
    }

    public function testGetPathWithFileNormalizesDoubleSlashes(): void
    {
        self::assertSame(
            '/foo/bar.txt',
            Helper::getPathWithFile('/foo/', 'bar.txt')
        );
    }

    // -----------------------------------------------------------------
    // normalizePath
    // -----------------------------------------------------------------

    #[DataProvider('provideNormalizePathCases')]
    public function testNormalizePath(string $input, string $expected): void
    {
        self::assertSame($expected, Helper::normalizePath($input));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideNormalizePathCases(): iterable
    {
        $s = DIRECTORY_SEPARATOR;

        return [
            'empty'              => ['', ''],
            'relative'           => ['foo' . $s . 'bar', 'foo' . $s . 'bar'],
            'absolute'           => [$s . 'foo' . $s . 'bar', $s . 'foo' . $s . 'bar'],
            'trailing separator' => [$s . 'foo' . $s, $s . 'foo'],
            'double separators'  => [$s . 'foo' . $s . $s . 'bar', $s . 'foo' . $s . 'bar'],
            'dot segment'        => [$s . 'foo' . $s . '.' . $s . 'bar', $s . 'foo' . $s . 'bar'],
            'parent segment'     => [$s . 'foo' . $s . '..' . $s . 'bar', $s . 'bar'],
            'parent at root'     => [$s . '..' . $s . 'bar', $s . 'bar'],
            'mixed'              => [$s . 'a' . $s . '.' . $s . 'b' . $s . '..' . $s . 'c', $s . 'a' . $s . 'c'],
        ];
    }

    // -----------------------------------------------------------------
    // normalizePathEncoding / encodeCyrillicString
    // -----------------------------------------------------------------

    public function testNormalizePathEncodingReturnsInputOnLinux(): void
    {
        if (PHP_OS === 'WINNT') {
            self::markTestSkipped('Тест только для не-Windows систем');
        }

        self::assertSame('/foo/bar', Helper::normalizePathEncoding('/foo/bar'));
    }

    public function testEncodeCyrillicStringIsReversible(): void
    {
        $original = 'Привет, мир!';
        $encoded  = Helper::encodeCyrillicString($original);

        self::assertNotSame($original, $encoded);
        self::assertSame(
            $original,
            mb_convert_encoding($encoded, 'UTF-8', 'Windows-1251')
        );
    }

    // -----------------------------------------------------------------
    // prepareCompareString
    // -----------------------------------------------------------------

    #[DataProvider('providePrepareCompareStringCases')]
    public function testPrepareCompareString(string $input, string $expected): void
    {
        self::assertSame($expected, Helper::prepareCompareString($input));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function providePrepareCompareStringCases(): iterable
    {
        return [
            'lowercase'    => ['HELLO', 'hello'],
            'yo to ye'     => ['Ёлка', 'елка'],
            'yo uppercase' => ['ЁЖИК', 'ежик'],
            'mixed'        => ['Привет Ёж', 'привет еж'],
            'no cyrillic'  => ['Test 123', 'test 123'],
        ];
    }

    // -----------------------------------------------------------------
    // explodeInt
    // -----------------------------------------------------------------

    public function testExplodeIntWithDefaultSeparator(): void
    {
        self::assertSame([1, 2, 3], Helper::explodeInt('1|2|3'));
    }

    public function testExplodeIntReplacesNonDigitCharacters(): void
    {
        self::assertSame([1, 2, 3], Helper::explodeInt('1, 2; 3'));
    }

    public function testExplodeIntPreservesKeysAfterFiltering(): void
    {
        // "0" отбрасывается, массив остаётся плотным
        self::assertSame([1, 2], Helper::explodeInt('1|0|2'));
    }

    public function testExplodeIntReturnsEmptyForZero(): void
    {
        self::assertSame([], Helper::explodeInt('0'));
    }

    public function testExplodeIntReturnsEmptyForEmptyString(): void
    {
        self::assertSame([], Helper::explodeInt(''));
    }

    public function testExplodeIntReturnsEmptyForLetters(): void
    {
        self::assertSame([], Helper::explodeInt('abc'));
    }

    public function testExplodeIntWithCustomSeparator(): void
    {
        self::assertSame([10, 20, 30], Helper::explodeInt('10-20-30', '-'));
    }

    public function testExplodeIntWithSpacesAndCustomSeparator(): void
    {
        self::assertSame([10, 20, 30], Helper::explodeInt('10 20 30', '-'));
    }

    // -----------------------------------------------------------------
    // convertKeysToInt / convertKeysToString
    // -----------------------------------------------------------------

    public function testConvertKeysToInt(): void
    {
        $input    = ['1' => 'a', '2' => 'b', '10' => 'c'];
        $expected = [1 => 'a', 2 => 'b', 10 => 'c'];

        self::assertSame($expected, Helper::convertKeysToInt($input));
    }

    public function testConvertKeysToIntCastsNonNumericKeysToZero(): void
    {
        $input    = ['foo' => 'a'];
        $expected = [0 => 'a'];

        self::assertSame($expected, Helper::convertKeysToInt($input));
    }

    public function testConvertKeysToString(): void
    {
        $input    = [1 => 'a', 2 => 'b', 10 => 'c'];
        $expected = ['1' => 'a', '2' => 'b', '10' => 'c'];

        self::assertSame($expected, Helper::convertKeysToString($input));
    }

    public function testConvertKeysWithEmptyArray(): void
    {
        self::assertSame([], Helper::convertKeysToInt([]));
        self::assertSame([], Helper::convertKeysToString([]));
    }
}
