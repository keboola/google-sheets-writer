<?php

declare(strict_types=1);

namespace Keboola\GoogleSheetsWriter\Input;

class Page
{
    /** @var array */
    private $values;

    /** @var int */
    private $offset;

    /** @var int */
    private $limit;

    public function __construct(array $values, int $offset, int $limit)
    {
        $this->values = $values;
        $this->offset = $offset;
        $this->limit = $limit;
    }

    public function getValues(): array
    {
        return $this->values;
    }

    public function getLimit(): int
    {
        return $this->limit;
    }

    public function getOffset(): int
    {
        return $this->offset;
    }

    public function isFirst(): bool
    {
        return $this->offset === 1;
    }
}
