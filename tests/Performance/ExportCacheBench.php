<?php

declare(strict_types=1);

namespace MiMatus\ExportCache\Performance;

use DateInterval;
use MiMatus\ExportCache\ExportCache;
use MiMatus\ExportCache\Tests\SerializableDTO;
use PhpBench\Attributes\BeforeMethods;
use PhpBench\Attributes\Iterations;
use PhpBench\Attributes\ParamProviders;
use PhpBench\Attributes\RetryThreshold;
use PhpBench\Attributes\Revs;
use PhpBench\Attributes\Warmup;

/**
 * @todo Check correctness of operations
 *
 * @phpstan-type CacheEntries array<string, array{data: mixed, ttl: DateInterval|int|null, set: bool}>
 */
class ExportCacheBench
{
    private ExportCache $exportCache;

    /**
     * @param CacheEntries $params
     */
    #[Revs(10000)]
    #[Iterations(5)]
    #[Warmup(5)]
    #[RetryThreshold(2)]
    #[BeforeMethods('setupCache')]
    #[ParamProviders('cacheDataProvider')]
    public function benchGet(array $params): void
    {
        foreach ($params as $key => $_) { // phpcs:ignore SlevomatCodingStandard.Variables.UnusedVariable.UnusedVariable
            $this->exportCache->get($key);
        }
    }

    /**
     * @param CacheEntries $params
     */
    #[Revs(10000)]
    #[Iterations(5)]
    #[Warmup(5)]
    #[RetryThreshold(2)]
    #[BeforeMethods('setupCache')]
    #[ParamProviders('cacheDataProvider')]
    public function benchHas(array $params): void
    {
        foreach (array_keys($params) as $key) {
            $this->exportCache->has($key);
        }
    }

    /**
     * @param CacheEntries $params
     */
    #[Revs(10000)]
    #[Iterations(5)]
    #[Warmup(5)]
    #[RetryThreshold(2)]
    #[BeforeMethods('setupCache')]
    #[ParamProviders('cacheDataProvider')]
    public function benchGetMultiple(array $params): void
    {
        foreach ($this->exportCache->getMultiple(array_keys($params)) as $_) { // phpcs:ignore SlevomatCodingStandard.Variables.UnusedVariable.UnusedVariable
        }
    }

    /**
     * @param CacheEntries $params
     */
    #[Revs(1000)]
    #[Iterations(5)]
    #[Warmup(5)]
    #[RetryThreshold(2)]
    #[BeforeMethods('setupCache')]
    #[ParamProviders('cacheDataProvider')]
    public function benchSet(array $params): void
    {
        foreach ($params as $key => $config) {
            $this->exportCache->set($key, $config['data'], $config['ttl']);
        }
    }

    /**
     * @return array<string, CacheEntries>
     */
    public function cacheDataProvider(): array
    {
        $itemsCount = 50;
        $skeleton = range(0, $itemsCount);
        $keys = array_map(fn($index) => 'key' . $index, $skeleton);

        return [
            'non existent keys' => array_combine($keys, array_map(fn(int $i) => ['data' => 'data' . $i, 'ttl' => null, 'set' => false], $skeleton)),
            'existing & non existing keys' => array_combine($keys, array_map(fn(int $i) => ['data' => 'data' . $i, 'ttl' => null, 'set' => $i % 2 === 0], $skeleton)),
            'strings' => array_combine($keys, array_map(fn(int $i) => ['data' => 'data' . $i, 'ttl' => null, 'set' => $i % 2 === 0], $skeleton)),
            'arrays' => array_combine($keys, array_map(fn(int $i) => ['data' => array_fill(0, 1000, 'hello world'), 'ttl' => null, 'set' => $i % 2 === 0], $skeleton)),
            'serializable objects' => array_combine($keys, array_map(fn(int $i) => ['data' => new SerializableDTO((string)$i), 'ttl' => null, 'set' => $i % 2 === 0], $skeleton)),
            'mixed data' => array_combine($keys, array_map(fn(int $i) => ['data' => $i < $itemsCount / 3 ? new SerializableDTO((string)$i) : array_fill(0, 1000, 'hello world'), 'ttl' => null, 'set' => $i % 2 === 0], $skeleton)),
            'mixed data with ttls' => array_combine($keys, array_map(fn(int $i) => ['data' => $i < $itemsCount / 3 ? new SerializableDTO((string)$i) : array_fill(0, 1000, 'hello world'), 'ttl' => $i % 2 === 0 ? new DateInterval('P2D') :  null, 'set' => $i % 2 === 0], $skeleton)),
        ];
    }

    /**
     * @param CacheEntries $params
     */
    public function setupCache(array $params): void
    {
        $storagePath = sys_get_temp_dir() . \DIRECTORY_SEPARATOR . 'export-cache';
        $this->exportCache = new ExportCache($storagePath);

        if (is_dir($storagePath)) {
            $this->exportCache->clear() ?: throw new \RuntimeException('Unable to clear cache storage');
        } elseif (!mkdir($storagePath, 0777)) {
            throw new \RuntimeException('Unable to setup cache storage');
        }

        foreach ($params as $key => $config) {
            if (!$config['set']) {
                continue;
            }
            $this->exportCache->set($key, $config['data'], $config['ttl']);
        }
    }
}
