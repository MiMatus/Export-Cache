<?php

declare(strict_types=1);

namespace MiMatus\ExportCache;

use Brick\VarExporter\VarExporter;
use Closure;
use DateInterval;
use DateTimeImmutable;
use Psr\SimpleCache\CacheInterface;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Throwable;

/**
 * @phpstan-type CacheItem array{expiration: float|null, value: mixed}
 * @phpstan-type CacheFileInfo array{filePath: string, directoryPath: string, filename: string}
 */
class ExportCache implements CacheInterface
{
    private static bool $opCacheEnabled;

    private static int $fileMTime;

    private readonly string $storagePath;

    private readonly Closure $errorHandler;

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
        $fileInfo = $this->getCacheFileInfo($key);
        return $this->useCacheItem($key, function (?array $cachedItem) use ($fileInfo, $default) {
            if ($cachedItem === null) {
                return $default;
            }

            if (!$this->isExpired($cachedItem)) {
                return $cachedItem['value'];
            }

            if (!$this->removeCacheFile($fileInfo['filePath'])) {
                throw new ExportCacheException('Unable to clean-up expired file `' . $fileInfo['filePath'] . '`');
            }

            return $default;
        });
    }

    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        foreach ($keys as $key) {
            yield $key => $this->get((string)$key, $default);
        }
    }

    /**
     * {@inheritDoc}
     *
     * @throws InvalidArgumentException For invalid keys or values
     * @throws ExportCacheException When value can not be saved - lock is present, file system error etc.
     */
    public function set(string $key, mixed $value, null|int|DateInterval $ttl = null): bool
    {
        try {
            $exportedValue = VarExporter::export(['expiration' => $this->getExpirationTimestamp($ttl), 'value' => $value], VarExporter::CLOSURE_SNAPSHOT_USES | VarExporter::INLINE_SCALAR_LIST);
        } catch (Throwable $e) {
            throw new InvalidArgumentException(sprintf('Cache key "%s" has non-exportable "%s" value.', $key, get_debug_type($value)), 0, $e);
        }

        $fileInfo = $this->getCacheFileInfo($key);
        $cacheValue = '<?php return ' . $exportedValue . ';';

        $this->useFile($fileInfo, FileMode::Write, static function ($file) use ($cacheValue, $fileInfo) {
            if ($file === null) {
                throw new ExportCacheException('Unable to accuire file resource: ' . $fileInfo['filePath']);
            }

            if (!ftruncate($file, 0)) {
                throw new ExportCacheException('Unable to empty file: ' . $fileInfo['filePath']);
            }

            if (fwrite($file, $cacheValue) !== strlen($cacheValue)) {
                throw new ExportCacheException('Unable to write data into: ' . $fileInfo['filePath']);
            }

            if (!fflush($file)) {
                throw new ExportCacheException('Unable to flush buffered data into: ' . $fileInfo['filePath']);
            }

            if (!self::$opCacheEnabled) {
                return;
            }

            if (!touch($fileInfo['filePath'], self::$fileMTime)) {
                throw new ExportCacheException('Unable to modify file MTime for file: ' . $fileInfo['filePath']);
            }

            if (!opcache_invalidate($fileInfo['filePath'], true) || !opcache_compile_file($fileInfo['filePath'])) {
                throw new ExportCacheException('Unable properly update op cache' . $fileInfo['filePath']);
            }
        });

        return true;
    }

    /**
     * {@inheritDoc}
     *
     * @throws InvalidArgumentException For invalid keys or values
     * @throws ExportCacheException When value can not be saved - lock is present, file system error etc.
     */
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
        return $this->useCacheItem($key, function (?array $cachedItem) {
            return $cachedItem !== null && !$this->isExpired($cachedItem);
        });
    }

    public function delete(string $key): bool
    {
        ['filePath' => $filePath] = $this->getCacheFileInfo($key);

        if (!file_exists($filePath)) {
            return true;
        }

        return $this->removeCacheFile($filePath);
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

    /**
     * @throws ExportCacheException
     */
    private function removeCacheFile(string $filePath): bool
    {
        if (self::$opCacheEnabled && !opcache_invalidate($filePath, true)) {
            throw new ExportCacheException('Unable to invalidate op cache' . $filePath);
        }
        // Supress warning which would be generated for non-existent file
        return @unlink($filePath);
    }

    /**
     * @template T
     * @param Closure(CacheItem|null): T $modifier
     * @return T
     *
     * @throws ExportCacheException
     */
    private function useCacheItem(string $key, Closure $modifier): mixed
    {
        $fileInfo = $this->getCacheFileInfo($key);

        return $this->useFile($fileInfo, FileMode::Read, static function () use ($fileInfo, $modifier) {
            $cachedItem = null;
            try {
                $includedValue = include $fileInfo['filePath'];
                if ($includedValue !== false) {
                    $cachedItem = $includedValue;
                }
            } catch (ExportCacheException) {
            }

            return $modifier($cachedItem);
        });
    }

    /**
     * @throws ExportCacheException
     */
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

    /**
     * @template T
     * @param Closure(resource|null): T $fileModifier
     * @param CacheFileInfo $fileInfo
     * @return T
     *
     * @throws ExportCacheException
     */
    private function useFile(array $fileInfo, FileMode $fileMode, Closure $fileModifier): mixed
    {
        ['filePath' => $filePath, 'directoryPath' => $directoryPath, ] = $fileInfo;

        set_error_handler($this->errorHandler);
        if ($fileMode === FileMode::Write && !is_dir($directoryPath) && !mkdir($directoryPath, 0777, true)) {
            throw new ExportCacheException('Unable to create directories: ' . $filePath);
        }

        if ($fileMode === FileMode::Read && !file_exists($filePath)) {
            return $fileModifier(null);
        }

        $fileResource = fopen($filePath, 'c+b');
        if (!$fileResource) {
            throw new ExportCacheException(
                sprintf('Unable to aquire lock for file %s', $filePath)
            );
        }

        if (!flock($fileResource, $fileMode->getFileLock() | \LOCK_NB)) {
            throw new ExportCacheException(
                sprintf('Unable to aquire lock for file %s', $filePath)
            );
        }

        try {
            return $fileModifier($fileResource);
        } finally {
            fclose($fileResource);
            restore_error_handler();
        }
    }

    /**
     * @return CacheFileInfo
     */
    private function getCacheFileInfo(string $key): array
    {
        $hash = str_replace('/', '-', base64_encode(hash('xxh128', static::class . $key, true)));
        $directoryPath = $this->storagePath . \DIRECTORY_SEPARATOR . $hash[0] . \DIRECTORY_SEPARATOR . $hash[1];
        $filename = substr($hash, 2, 20);
        $filePath = $directoryPath . \DIRECTORY_SEPARATOR . $filename;

        return ['filePath' => $filePath, 'directoryPath' => $directoryPath, 'filename' => $filename];
    }

    /**
     * @param CacheItem $cacheItem
     */
    private function isExpired(array $cacheItem): bool
    {
        return $cacheItem['expiration'] !== null && $cacheItem['expiration'] <= time();
    }
}
