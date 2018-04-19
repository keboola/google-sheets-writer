<?php
/** @codingStandardsIgnoreFile */
declare(strict_types=1);

namespace Keboola\GoogleSheetsWriter\Logger;

use Keboola\Csv\CsvFile;
use Monolog\Formatter\GelfMessageFormatter;

class GelfFormatter extends GelfMessageFormatter
{
    /**
     * @param CsvFile $data
     * @return array|string
     */
    protected function normalize($data)
    {
        if ($data instanceof CsvFile) {
            return "csv file: " . $data->getFilename();
        }
        return parent::normalize($data);
    }
}
