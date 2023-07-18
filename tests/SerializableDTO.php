<?php

declare(strict_types=1);

namespace MiMatus\ExportCache\Tests;

class SerializableDTO
{
    public function __construct(private string $stringData)
    {
    }

    /**
     * @return array{stringData: string}
     */
    public function __serialize(): array
    {
        return ["stringData" => $this->stringData];
    }

    /**
     * @param array{stringData: string} $data
     */
    public function __unserialize(array $data)
    {
        $this->stringData = $data["stringData"];
    }
}
