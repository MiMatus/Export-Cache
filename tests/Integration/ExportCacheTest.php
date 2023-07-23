<?php

declare(strict_types=1);

namespace MiMatus\ExportCache\Tests\Integration;

use Cache\IntegrationTests\SimpleCacheTest;
use Closure;
use MiMatus\ExportCache\ExportCache;
use MiMatus\ExportCache\InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionFunction;
use SplFileInfo;

class ExportCacheTest extends SimpleCacheTest
{
    /**
     * @var array<string, string>
     */
    protected $skippedTests = [
            'testGetInvalidKeys' => 'Skipped PHP stric typing takes care of it',
            'testGetMultipleInvalidKeys' => 'Skipped PHP stric typing takes care of it',
            'testGetMultipleNoIterable' => 'Skipped PHP stric typing takes care of it',
            'testSetInvalidKeys' => 'Skipped PHP stric typing takes care of it',
            'testSetMultipleInvalidKeys' => 'Skipped PHP stric typing takes care of it',
            'testSetMultipleNoIterable' => 'Skipped PHP stric typing takes care of it',
            'testHasInvalidKeys' => 'Skipped PHP stric typing takes care of it',
            'testDeleteInvalidKeys' => 'Skipped PHP stric typing takes care of it',
            'testDeleteMultipleInvalidKeys' => 'Skipped PHP stric typing takes care of it',
            'testDeleteMultipleNoIterable' => 'Skipped PHP stric typing takes care of it',
            'testSetInvalidTtl' => 'Skipped PHP stric typing takes care of it',
            'testSetMultipleInvalidTtl' => 'Skipped  PHP stric typing takes care of it',
    ];

    public function createSimpleCache()
    {
        $storagePath = sys_get_temp_dir() . \DIRECTORY_SEPARATOR . 'export-cache-tests';
        return new ExportCache($storagePath);
    }

    /**
     * @todo Closure equality check
     */
    #[DataProvider('getClosureData')]
    public function testDataTypeClosure(Closure $closure): void
    {
        $this->cache->set('key', $closure);
        $result = $this->cache->get('key');

        $actualClosure = new ReflectionFunction($closure);
        $cachedClosure = new ReflectionFunction($result);

        $this->assertEquals($actualClosure->getParameters(), $cachedClosure->getParameters(), 'Closure\'s have different parameters');
        $this->assertEquals($actualClosure->getReturnType(), $cachedClosure->getReturnType(), 'Closure\'s have different return types');
        $this->assertEquals($actualClosure->isStatic(), $cachedClosure->isStatic(), 'Closure\'s static def. does not match');
    }

    /**
     * @return array<string, array<string, Closure>>
     */
    public static function getClosureData(): array
    {
        $localVariable = 1;
        return [
            'without use statement' => [
                'closure' => function (): string {
                    return 'test';
                },
            ],
            'with use statement' => [
                'closure' => function () use ($localVariable) {
                    return $localVariable;
                },
            ],
            'arrow function' => [
                'closure' => fn() => 'test',
            ],
            'static closure' => [
                'closure' => static function (): string {
                    return 'test';
                },
            ],
        ];
    }

    #[DataProvider('getUnsupportedDataValues')]
    public function testUnsupportedDataTypeClosure(mixed $value): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->cache->set('key', $value);
    }

        /**
     * @return array<string, array<string, mixed>>
     */
    public static function getUnsupportedDataValues(): array
    {
        $callable1 = fn() => 4; $callable2 = fn() => 5; // phpcs:ignore Generic.Formatting.DisallowMultipleStatements.SameLine
        return [
            '1st class callable from named function' => [
                'value' => str_contains(...),
            ],
            'multiple callable on same line' => [
                'value' => $callable1,
            ],
            'multiple callable on same line 2' => [
                'value' => $callable2,
            ],
            // Most of internal objects are not supported
            'internal object' => [
                'value' => new SplFileInfo('example.php'),
            ],
        ];
    }
}
