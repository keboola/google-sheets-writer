<?php

declare(strict_types=1);

namespace Keboola\GoogleSheetsWriter\Input;

class Paginator
{
    /** @var Table */
    protected $inputTable;

    /** @var int */
    protected $limit;

    /** @var int */
    protected $offset = 1;

    public function __construct(Table $inputTable, int $limit = 5000)
    {
        $this->inputTable = $inputTable;
        $this->limit = $limit;
    }

    public function pages(): \Generator
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

            yield $this->offset => $values;
            $this->offset = $this->offset + $i;
        }
    }

    public function getLimit(): int
    {
        return $this->limit;
    }
}
