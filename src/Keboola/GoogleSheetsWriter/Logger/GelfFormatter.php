<?php
/** @codingStandardsIgnoreFile */
declare(strict_types=1);

namespace Keboola\GoogleSheetsWriter\Logger;

use Keboola\Csv\CsvFile;
use Monolog\Formatter\GelfMessageFormatter;

class GelfFormatter extends GelfMessageFormatter
{
    protected function normalize($data, $depth = 0)
    {
        if ($data instanceof CsvFile) {
            return "csv file: " . $data->getFilename();
        }
        return parent::normalize($data, $depth);
    }
}
