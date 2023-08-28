<?php

declare(strict_types=1);

namespace Keboola\GoogleSheetsWriter\Input;

class TableFactory
{
    private string $dataDir;

    public function __construct(string $dataDir)
    {
        $this->dataDir = $dataDir;
    }

    public function getTable(string $tableId): Table
    {
        return new Table($this->dataDir, $tableId);
    }
}
