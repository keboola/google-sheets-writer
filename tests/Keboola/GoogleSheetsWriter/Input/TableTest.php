<?php

declare(strict_types=1);

namespace Keboola\GoogleSheetsWriter\Input;

use Keboola\GoogleSheetsWriter\Exception\UserException;
use PHPUnit\Framework\TestCase;

class TableTest extends TestCase
{
    private const DATA_PATH = __DIR__ . '/../../../data';

    public function testExistingInputTableIsReadable(): void
    {
        // Happy path: an input table that is present is read exactly as before.
        $table = new Table(self::DATA_PATH, 'titanic');

        $this->assertSame(6, $table->getColumnCount());
        $this->assertGreaterThan(0, $table->getRowCount());
    }

    public function testMissingInputTableRaisesUserException(): void
    {
        // A configured input table that is absent from /data/in/tables must surface as a
        // UserException (exit 1) instead of an opaque Keboola\Csv\Exception (internal error, exit 2).
        $emptyDataDir = sys_get_temp_dir() . '/gsheets-wr-test-' . uniqid();

        $this->expectException(UserException::class);
        $this->expectExceptionMessage('Input table "missing_table" could not be read');

        new Table($emptyDataDir, 'missing_table');
    }
}
