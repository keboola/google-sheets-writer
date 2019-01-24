<?php
/** @codingStandardsIgnoreFile */
declare(strict_types=1);

namespace Keboola\GoogleSheetsWriter\Logger;

use Keboola\Csv\CsvFile;

class LineFormatter extends \Monolog\Formatter\LineFormatter
{
    protected function normalize($data, $depth = 0)
    {
        if ($data instanceof CsvFile) {
            return "csv file: " . $data->getFilename();
        }
        return parent::normalize($data, $depth);
    }
}
