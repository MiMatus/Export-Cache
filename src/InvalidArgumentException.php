<?php declare(strict_types=1);

namespace MiMatus\ExportCache;

use Psr\SimpleCache\InvalidArgumentException as SimpleCacheInvalidArgumentException;

class InvalidArgumentException extends ExportCacheException implements SimpleCacheInvalidArgumentException
{

}