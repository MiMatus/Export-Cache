<?php declare(strict_types=1);

namespace MiMatus\ExportCache;

use Brick\VarExporter\ExportException;
use Brick\VarExporter\VarExporter;
use Closure;
use DateInterval;
use Error;
use Psr\SimpleCache\CacheInterface;

class ExportCache implements CacheInterface
{

    private static int $initTime;

    private Closure $errorHandler;

    public function __construct(
        private ?string $storagePath = null,
    ) {
        if(
            !function_exists('opcache_invalidate') 
                || !filter_var(\ini_get('opcache.enable'), \FILTER_VALIDATE_BOOL)
                || (in_array(\PHP_SAPI, ['cli', 'phpdbg'], true) && !filter_var(\ini_get('opcache.enable_cli'), \FILTER_VALIDATE_BOOL))
        ) {
            //throw new ExportCacheException('OPCache is not enabled, check your ini configuration');
        }

        self::$initTime ??= time();
        $this->storagePath = rtrim($this->storagePath ?? sys_get_temp_dir(), \DIRECTORY_SEPARATOR);
        $this->errorHandler = static function ($type, $msg, $file, $line) {
            throw new ExportCacheException($msg);
        };
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $keyFilePath = $this->getKeyFilePath($key);

        set_error_handler($this->errorHandler);

        try{
            $cachedItem = include $keyFilePath;
            if($cachedItem === false){
                return null;
            }

            if($this->isExpired($cachedItem)){
                return $default;
            }

            return $cachedItem['value'];
        } catch(ExportCacheException){
            return $default;
        } catch(Error){
            throw new ExportCacheException('Invalid stored value for key:'.$key);
        } finally {
            restore_error_handler();
        }
    }

    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $items = [];
        foreach($keys as $key){
            $items[$key] = $this->get($key, $default);
        }
        return $items;
    }

    public function set(string $key, mixed $value, null|int|DateInterval $ttl = null): bool
    {
        try{
            $exportedValue = VarExporter::export(['expiration' => $this->getExpirationTimestamp($ttl), 'value' => $value]);
        } catch(ExportException $e){
            throw new InvalidArgumentException(sprintf('Cache key "%s" has non-exportable "%s" value.', $key, get_debug_type($value)), 0, $e);
        }

        $filePath = $this->getKeyFilePath($key);
        $keyResource = $this->getWritableResource($filePath);
        $cacheValue = '<?php return '.$exportedValue.';';

        set_error_handler($this->errorHandler);
        try{
            if(!ftruncate($keyResource, 0)){
                throw new ExportCacheException('Unable to empty key: '.$key);
            }

            $result = fwrite($keyResource, $cacheValue) === strlen($cacheValue);
            if(!$result){
                $this->delete($key);
            }
            fflush($keyResource);
            touch($filePath, self::$initTime - 10); //https://www.php.net/manual/en/function.opcache-compile-file.php#121990
            flock($keyResource, LOCK_UN);
            return $result;
        } finally {
            restore_error_handler();
        }
        return true;
    }

    public function setMultiple(iterable $values, null|int|DateInterval $ttl = null): bool
    {
        $result = true;
        foreach($values as $key => $value){
            $result = $this->set($key, $value, $ttl) && $result;
        }
        return $result;
    }

    public function has(string $key): bool
    {
        return $this->get($key) !== null;
    }

    public function delete(string $key): bool
    {

        $keyFilePath = $this->getKeyFilePath($key);
        if(!file_exists($keyFilePath)){
            return true;
        }

        return $this->removeCacheFile($keyFilePath);
    }

    public function deleteMultiple(iterable $keys): bool
    {
        $result = true;
        foreach($keys as $key){
            $result = $this->delete($key) && $result;
        }
        return $result;
    }

    private function removeCacheFile(string $filePath): bool
    {
        set_error_handler($this->errorHandler);
        try{
            $keyResource = $this->getWritableResource($filePath);
            ftruncate($keyResource, 0);
            flock($keyResource, LOCK_UN);
            fclose($keyResource);

            return unlink($filePath);
        } finally {
            restore_error_handler();
        }
    }

    public function clear(): bool
    {
        set_error_handler($this->errorHandler);

        try{
            $files = scandir($this->storagePath, \SCANDIR_SORT_NONE);
            if ($files === false){
                throw new ExportCacheException('Unable to retrieve cache files list');
            }
        } finally{
            restore_error_handler();
        }

        $result = true;
        foreach($files as $file){
            if ($file == '.' || $file === '..') {
                continue;
            }
            $result = $this->removeCacheFile($this->storagePath.\DIRECTORY_SEPARATOR.$file) && $result;
        }

        return $result;
    }

    /**
     * @return resource
     * @throws ExportCacheException
     */
    private function getWritableResource(string $filePath)
    {
        set_error_handler($this->errorHandler);
        try{
            $fileResource = fopen($filePath, 'c+b');
            if (!$fileResource) {
                
            }

            if(!flock($fileResource, \LOCK_EX)){
                throw new ExportCacheException(
                    sprintf('Unable to aquire lock for file %s', $filePath)
                );
            }
        } finally {
            restore_error_handler();
        }

        return $fileResource;
    }

    private function getKeyFilePath(string $key): string
    {
        $hash = hash('md5', static::class.$key);
        return $this->storagePath.\DIRECTORY_SEPARATOR.$hash;
    }

    private function getExpirationTimestamp(null|int|DateInterval $expiraton): ?float
    {
        if ($expiraton === null) {
            return null;
        } elseif ($expiraton instanceof \DateInterval) {
            return (float)(new \DateTimeImmutable('now'))->add($expiraton)->format('U.u');
        } elseif (\is_int($expiraton)) {
            return $expiraton + time();
        } else {
            throw new InvalidArgumentException(sprintf('Expiration date must be an integer, a DateInterval or null, "%s" given.', get_debug_type($expiraton)));
        }
    }

    private function isExpired(array $cacheItem): bool
    {
       return $cacheItem['expiration'] !== null && $cacheItem['expiration'] <= time();
    }

}