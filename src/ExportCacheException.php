<?php

declare(strict_types=1);

namespace MiMatus\ExportCache;

use Exception;
use Psr\SimpleCache\CacheException;

class ExportCacheException extends Exception implements CacheException
{
}
