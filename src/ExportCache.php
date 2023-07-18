<?php

declare(strict_types=1);

namespace MiMatus\ExportCache;

use Brick\VarExporter\ExportException;
use Brick\VarExporter\VarExporter;
use DateInterval;
use DateTimeImmutable;
use Psr\SimpleCache\CacheInterface;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * @phpstan-type CacheItem array{expiration: float|null, value: mixed}
 */
class ExportCache implements CacheInterface
{
    private static bool $opCacheEnabled;

    private static int $fileMTime;

    private readonly string $storagePath;

    private readonly \Closure $errorHandler;

    public function __construct(
        ?string $storagePath = null,
    ) {
        self::$fileMTime ??= time() - 10; // https://www.php.net/manual/en/function.opcache-compile-file.php#121990
        $this->storagePath = rtrim($storagePath ?? sys_get_temp_dir(), \DIRECTORY_SEPARATOR);
        $this->errorHandler = static function ($type, $msg, $file, $line) {
            throw new ExportCacheException($msg);
        };
        self::$opCacheEnabled ??=
                function_exists('opcache_invalidate')
                && filter_var(\ini_get('opcache.enable'), \FILTER_VALIDATE_BOOL)
                && (!\in_array(\PHP_SAPI, ['cli', 'phpdbg'], true) || filter_var(ini_get('opcache.enable_cli'), \FILTER_VALIDATE_BOOL));
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $cachedItem = $this->getCacheItem($key);
        if ($cachedItem === null || $this->isExpired($cachedItem)) {
            return $default;
        }

        return $cachedItem['value'];
    }

    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        foreach ($keys as $key) {
            yield $key => $this->get((string)$key, $default);
        }
    }

    public function set(string $key, mixed $value, null|int|DateInterval $ttl = null): bool
    {
        try {
            $exportedValue = VarExporter::export(['expiration' => $this->getExpirationTimestamp($ttl), 'value' => $value], VarExporter::CLOSURE_SNAPSHOT_USES | VarExporter::INLINE_SCALAR_LIST);
        } catch (ExportException $e) {
            throw new InvalidArgumentException(sprintf('Cache key "%s" has non-exportable "%s" value.', $key, get_debug_type($value)), 0, $e);
        }

        [$directoryPath, $filename, $filePath] = $this->getFilePath($key);
        $cacheValue = '<?php return ' . $exportedValue . ';';

        $this->exclusivelyAccess($directoryPath, $filename, static function ($file) use ($cacheValue, $filePath) {
            if (!ftruncate($file, 0)) {
                throw new ExportCacheException('Unable to empty file: ' . $filePath);
            }

            if (fwrite($file, $cacheValue) !== strlen($cacheValue)) {
                throw new ExportCacheException('Unable to write data into: ' . $filePath);
            }

            if (!fflush($file)) {
                throw new ExportCacheException('Unable to flush buffered data into: ' . $filePath);
            }


            if (!self::$opCacheEnabled) {
                return;
            }

            if (!touch($filePath, self::$fileMTime)) {
                throw new ExportCacheException('Unable to modify file MTime for file: ' . $filePath);
            }

            if (!opcache_invalidate($filePath, true) || !opcache_compile_file($filePath)) {
                throw new ExportCacheException('Unable properly update op cache' . $filePath);
            }
        });

        return true;
    }

    public function setMultiple(iterable $values, null|int|DateInterval $ttl = null): bool
    {
        $result = true;
        foreach ($values as $key => $value) {
            $result = $this->set((string)$key, $value, $ttl) && $result;
        }
        return $result;
    }

    public function has(string $key): bool
    {
        $cacheItem = $this->getCacheItem($key);

        return $cacheItem !== null && !$this->isExpired($cacheItem);
    }

    public function delete(string $key): bool
    {
        [, , $cacheFilePath] = $this->getFilePath($key);

        if (!file_exists($cacheFilePath)) {
            return true;
        }

        return $this->removeCacheFile($cacheFilePath);
    }

    public function deleteMultiple(iterable $keys): bool
    {
        $result = true;
        foreach ($keys as $key) {
            $result = $this->delete((string)$key) && $result;
        }
        return $result;
    }

    public function clear(): bool
    {
        if (!is_dir($this->storagePath)) {
            return true;
        }

        $result = true;
        $it = new RecursiveDirectoryIterator($this->storagePath, RecursiveDirectoryIterator::SKIP_DOTS);
        $files = new RecursiveIteratorIterator($it, RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            /** @var \SplFileInfo $file */
            $path = $file->getRealPath();
            if ($file->isFile()) {
                $result = $this->removeCacheFile($path) && $result;
            } elseif ($file->isDir()) {
                $result = rmdir($path) && $result;
            }
        }
        return $result;
    }

    private function removeCacheFile(string $filePath): bool
    {
        if (self::$opCacheEnabled && !opcache_invalidate($filePath, true)) {
            throw new ExportCacheException('Unable to invalidate op cache' . $filePath);
        }

        return unlink($filePath);
    }

    /**
     * @return CacheItem|null
     */
    private function getCacheItem(string $key): ?array
    {
        [, , $cacheFilePath] = $this->getFilePath($key);

        if (!file_exists($cacheFilePath)) {
            return null;
        }

        set_error_handler($this->errorHandler);

        try {
            $cachedItem = include $cacheFilePath;
            if ($cachedItem === false) {
                return null;
            }

            return $cachedItem;
        } catch (ExportCacheException) {
            return null;
        } finally {
            restore_error_handler();
        }
    }

    private function getExpirationTimestamp(null|int|DateInterval $expiraton): ?float
    {
        if ($expiraton === null) {
            return null;
        } elseif ($expiraton instanceof DateInterval) {
            return (float)(new DateTimeImmutable('now'))->add($expiraton)->format('U.u');
        } elseif (\is_int($expiraton)) {
            return $expiraton + time();
        } else {
            throw new InvalidArgumentException(sprintf('Expiration date must be an integer, a DateInterval or null, "%s" given.', get_debug_type($expiraton)));
        }
    }

    private function exclusivelyAccess(string $directoryPath, string $filename, \Closure $modifier): void
    {
        $filePath = $directoryPath . \DIRECTORY_SEPARATOR . $filename;

        set_error_handler($this->errorHandler);
        if (!is_dir($directoryPath) && !mkdir($directoryPath, 0777, true)) {
            throw new ExportCacheException('Unable to create directories: ' . $filePath);
        }

        $fileResource = fopen($filePath, 'c+b');
        if (!$fileResource) {
            throw new ExportCacheException(
                sprintf('Unable to aquire lock for file %s', $filePath)
            );
        }

        if (!flock($fileResource, \LOCK_EX | \LOCK_NB)) {
            throw new ExportCacheException(
                sprintf('Unable to aquire lock for file %s', $filePath)
            );
        }

        try {
            $modifier($fileResource);

            if (!flock($fileResource, \LOCK_UN)) {
                throw new ExportCacheException('Unable to flush buffered data into: ' . $fileResource);
            }
        } finally {
            fclose($fileResource);
        }

        restore_error_handler();
    }

    /**
     * @return array{0: string, 1: string, 2: string}
     */
    private function getFilePath(string $key): array
    {
        $hash = str_replace('/', '-', base64_encode(hash('xxh128', static::class . $key, true)));
        $directoryPath = $this->storagePath . \DIRECTORY_SEPARATOR . $hash[0] . \DIRECTORY_SEPARATOR . $hash[1];
        $filename = substr($hash, 2, 20);
        $filePath = $directoryPath . \DIRECTORY_SEPARATOR . $filename;

        return [$directoryPath, $filename, $filePath];
    }

    /**
     * @param CacheItem $cacheItem
     */
    private function isExpired(array $cacheItem): bool
    {
        return $cacheItem['expiration'] !== null && $cacheItem['expiration'] <= time();
    }
}
