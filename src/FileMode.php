<?php

declare(strict_types=1);

namespace MiMatus\ExportCache;

enum FileMode
{
    case Read;
    case Write;

    public function getFileLock(): int
    {
        return $this === self::Write ? \LOCK_EX : \LOCK_SH;
    }
}
