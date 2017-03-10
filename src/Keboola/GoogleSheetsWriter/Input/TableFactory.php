<?php

/**
 * Author: miro@keboola.com
 * Date: 10/03/2017
 */
namespace Keboola\GoogleSheetsWriter\Input;

class TableFactory
{
    private $dataDir;

    public function __construct($dataDir)
    {
        $this->dataDir = $dataDir;
    }

    public function getTable($tableId)
    {
        return new Table($this->dataDir, $tableId);
    }
}
