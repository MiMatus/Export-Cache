<?php

declare(strict_types=1);

namespace MiMatus\ExportCache\Tests\Integration;

use Cache\IntegrationTests\SimpleCacheTest;
use Closure;
use MiMatus\ExportCache\ExportCache;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionFunction;

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
        return new ExportCache(__DIR__ . \DIRECTORY_SEPARATOR . 'data');
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
}
