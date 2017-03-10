<?php

/**
 * Author: miro@keboola.com
 * Date: 10/03/2017
 */
namespace Keboola\GoogleSheetsWriter\Input;

use Keboola\Csv\CsvFile;

class Table
{
    private $dataDir;

    private $tableId;

    private $csvFile;

    private $rowCount;

    private $columnCount;

    public function __construct($dataDir, $tableId)
    {
        $this->dataDir = $dataDir;
        $this->tableId = $tableId;
        $this->csvFile = new CsvFile($this->getPathname());
        $this->rowCount = $this->countLines();
        $this->columnCount = $this->csvFile->getColumnsCount();
    }

    public function getPathname()
    {
        return sprintf('%s/in/tables/%s.csv', $this->dataDir, $this->tableId);
    }

    public function getCsvFile()
    {
        return $this->csvFile;
    }

    public function getRowCount()
    {
        return $this->rowCount;
    }

    public function getColumnCount()
    {
        return $this->columnCount;
    }

    private function countLines()
    {
        $csvFile = clone $this->csvFile;
        $cnt = iterator_count($csvFile);
        $csvFile->rewind();
        return $cnt;
    }
}
