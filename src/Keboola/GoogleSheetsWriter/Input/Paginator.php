<?php

declare(strict_types=1);

namespace Keboola\GoogleSheetsWriter\Input;

use Generator;

class Paginator
{
    protected Table $inputTable;

    protected int $limit;

    protected int $offset = 1;

    public function __construct(Table $inputTable, int $limit = 5000)
    {
        $this->inputTable = $inputTable;
        $this->limit = $limit;
    }

    /** @return \Generator|Page[] */
    public function pages(): Generator
    {
        $csvFile = $this->inputTable->getCsvFile();
        while ($csvFile->current()) {
            $i = 0;
            $values = [];
            while ($i < $this->limit && $csvFile->current()) {
                $values[] = $csvFile->current();
                $csvFile->next();
                $i++;
            }

            yield new Page($values, $this->offset, $this->limit);
            $this->offset = $this->offset + $i;
        }
    }
}
