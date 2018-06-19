<?php

declare(strict_types=1);

namespace Keboola\GoogleSheetsWriter;

use Keboola\Csv\CsvFile;
use Keboola\GoogleSheetsClient\Client;
use Keboola\GoogleSheetsWriter\Configuration\ConfigDefinition;
use Keboola\GoogleSheetsWriter\Exception\UserException;
use Keboola\GoogleSheetsWriter\Logger\HandlerFactory;
use Keboola\GoogleSheetsWriter\Test\BaseTest;
use Monolog\Logger;

class SheetTest extends BaseTest
{
    public function testProcessSheetNotFound(): void
    {
        $this->prepareDataFiles();

        // create spreadsheet
        $gdFile = $this->client->createFileMetadata(
            'titanic',
            [
                'parents' => [getenv('GOOGLE_DRIVE_FOLDER')],
                'mimeType' => Client::MIME_TYPE_SPREADSHEET,
            ]
        );

        // add another sheet
        $this->client->addSheet($gdFile['id'], ['properties' => ['title' => 'Sheet2']]);

        // delete first sheet
        $gdSpreadsheet = $this->client->getSpreadsheet($gdFile['id']);
        $sheetId = $gdSpreadsheet['sheets'][0]['properties']['sheetId'];
        $this->client->deleteSheet($gdFile['id'], $sheetId);

        $sheetConfig = [
            'id' => 0,
            'fileId' => $gdFile['id'],
            'title' => 'titanic',
            'folder' => ['id' => getenv('GOOGLE_DRIVE_FOLDER')],
            'sheetId' => $sheetId,
            'sheetTitle' => 'casualties',
            'tableId' => 'titanic_2',
            'action' => ConfigDefinition::ACTION_UPDATE,
            'enabled' => true,
        ];

        $logger = new Logger('wr-google-drive', HandlerFactory::getStderrHandlers());
        $sheetWriter = new Sheet($this->client, new Input\Table($this->tmpDataPath, 'titanic_2'), $logger);

        $this->expectException('Keboola\\GoogleSheetsWriter\\Exception\\UserException');
        $this->expectExceptionMessage("Sheet \"casualties (0)\" not found in file \"titanic");
        $sheetWriter->process($sheetConfig);
    }

    public function testProcessTooLarge(): void
    {
        $this->prepareDataFiles();

        // create sheet
        $gdFile = $this->client->createFile(
            $this->dataPath . '/in/tables/titanic_1.csv',
            'titanic_1',
            [
                'parents' => [getenv('GOOGLE_DRIVE_FOLDER')],
                'mimeType' => Client::MIME_TYPE_SPREADSHEET,
            ]
        );

        $gdSpreadsheet = $this->client->getSpreadsheet($gdFile['id']);
        $sheetId = $gdSpreadsheet['sheets'][0]['properties']['sheetId'];

        // create large file (> 2M cells)
        $inputCsvPath = $this->tmpDataPath . '/in/tables/large.csv';
        touch($inputCsvPath);
        $inputCsv = new CsvFile($inputCsvPath);
        $inputCsv->writeRow(['id', 'random']);
        for ($i = 0; $i < 1000002; $i++) {
            $inputCsv->writeRow([$i, uniqid()]);
        }

        // update sheet
        $tableId = 'large';
        $sheetConfig = [
            'id' => 0,
            'fileId' => $gdFile['id'],
            'title' => 'pirates',
            'folder' => ['id' => getenv('GOOGLE_DRIVE_FOLDER')],
            'sheetId' => $sheetId,
            'sheetTitle' => 'Long John Silver',
            'tableId' => $tableId,
            'action' => ConfigDefinition::ACTION_UPDATE,
            'enabled' => true,
        ];

        $logger = new Logger('wr-google-drive', HandlerFactory::getStderrHandlers());
        $sheetWriter = new Sheet($this->client, new Input\Table($this->tmpDataPath, $tableId), $logger);

        $this->expectException('Keboola\\GoogleSheetsWriter\\Exception\\UserException');
        $this->expectExceptionMessage('CSV file exceeds the limit of 2000000 cells');
        $sheetWriter->process($sheetConfig);
    }
}