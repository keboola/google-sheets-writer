<?php

declare(strict_types=1);

namespace Keboola\GoogleSheetsWriter;

use Keboola\Google\ClientBundle\Google\RestApi;
use Keboola\GoogleSheetsClient\Client;
use Keboola\GoogleSheetsWriter\Configuration\ConfigDefinition;
use Keboola\GoogleSheetsWriter\Exception\UserException;
use Keboola\GoogleSheetsWriter\Input\TableFactory;
use Monolog\Handler\NullHandler;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;

/**
 * Covers the "sheetId" guard in Writer::process().
 *
 * "sheetId" is an optional node in ConfigDefinition and is only populated by the
 * SHEET_MODE_ADD branch, so a sheet left in the default "replace" mode without one passed
 * config validation and then died in Sheet::process() with `Undefined array key "sheetId"`
 * — an opaque internal error (exit 2) that told the user nothing. It is now surfaced as a
 * UserException (exit 1) naming the sheet and what to set.
 *
 * These tests are hermetic: the guard runs before any Drive API or input-table access, so
 * the mocked Client and TableFactory are never called.
 */
class WriterTest extends TestCase
{
    private function createWriter(): Writer
    {
        $restApi = $this->createMock(RestApi::class);
        $client = $this->createMock(Client::class);
        $client->method('getApi')->willReturn($restApi);

        return new Writer(
            $client,
            $this->createMock(TableFactory::class),
            new Logger('test', [new NullHandler()]),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function sheetConfig(array $overrides = []): array
    {
        return array_merge([
            'id' => 0,
            'fileId' => 'some-file-id',
            'title' => 'titanic',
            'sheetTitle' => 'casualties',
            'tableId' => 'in.c-main.titanic',
            'action' => ConfigDefinition::ACTION_UPDATE,
            'sheetMode' => ConfigDefinition::SHEET_MODE_REPLACE,
            'enabled' => true,
        ], $overrides);
    }

    public function testMissingSheetIdThrowsUserException(): void
    {
        $this->expectException(UserException::class);
        $this->expectExceptionMessage(
            'Sheet "casualties" in file "titanic" is missing the "sheetId" configuration value. '
            . 'Select an existing sheet for this table, or set the sheet mode to "add" '
            . 'to have a new sheet created.',
        );

        $this->createWriter()->process([$this->sheetConfig()]);
    }

    public function testNullSheetIdThrowsUserException(): void
    {
        // An explicitly null sheetId failed too (a TypeError deeper in Sheet), so converting
        // it here does not change any previously-succeeding run.
        $this->expectException(UserException::class);

        $this->createWriter()->process([$this->sheetConfig(['sheetId' => null])]);
    }

    public function testDisabledSheetIsStillSkippedBeforeTheGuard(): void
    {
        // Guards the ordering: the guard must not fire for a disabled sheet, which the
        // component has always skipped entirely regardless of its configuration.
        $this->createWriter()->process([$this->sheetConfig(['enabled' => false])]);

        $this->expectNotToPerformAssertions();
    }
}
