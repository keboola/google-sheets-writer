<?php

declare(strict_types=1);

namespace Keboola\GoogleSheetsWriter\Input;

use Keboola\Csv\CsvFile;

class Table
{
    /** @var string */
    private $dataDir;

    /** @var string */
    private $tableId;

    /** @var CsvFile */
    private $csvFile;

    /** @var int */
    private $rowCount;

    /** @var int */
    private $columnCount;

    public function __construct(string $dataDir, string $tableId)
    {
        $this->dataDir = $dataDir;
        $this->tableId = $tableId;
        $this->csvFile = new CsvFile($this->getPathname());
        $this->rowCount = $this->countLines();
        $this->columnCount = $this->csvFile->getColumnsCount();
    }

    public function getPathname() : string
    {
        return sprintf('%s/in/tables/%s.csv', $this->dataDir, $this->tableId);
    }

    public function getCsvFile() : CsvFile
    {
        return $this->csvFile;
    }

    public function getRowCount() : int
    {
        return $this->rowCount;
    }

    public function getColumnCount() : int
    {
        return $this->columnCount;
    }

    private function countLines() : int
    {
        $csvFile = clone $this->csvFile;
        $cnt = iterator_count($csvFile);
        $csvFile->rewind();
        return $cnt;
    }
}
