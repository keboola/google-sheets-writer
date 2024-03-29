<?php

declare(strict_types=1);

namespace Keboola\GoogleSheetsWriter;

use Generator;
use Keboola\Csv\CsvFile;
use Keboola\GoogleSheetsClient\Client;
use Keboola\GoogleSheetsWriter\Configuration\ConfigDefinition;
use Keboola\GoogleSheetsWriter\Test\BaseTest;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

class FunctionalTest extends BaseTest
{
    public function setUp(): void
    {
        parent::setUp();
        $testFiles = $this->client->listFiles("name contains 'titanic' and trashed != true");
        foreach ($testFiles['files'] as $file) {
            $this->client->deleteFile($file['id']);
        }
    }

    /**
     * Create or update a sheet
     */
    public function testUpdateSpreadsheet(): void
    {
        $this->prepareDataFiles();

        // create sheet
        $gdFile = $this->client->createFile(
            $this->dataPath . '/in/tables/titanic_1.csv',
            'Titanic 1 +',
            [
                'parents' => [getenv('GOOGLE_DRIVE_FOLDER')],
                'mimeType' => Client::MIME_TYPE_SPREADSHEET,
            ]
        );

        $gdSpreadsheet = $this->client->getSpreadsheet($gdFile['id']);
        $sheetId = $gdSpreadsheet['sheets'][0]['properties']['sheetId'];

        // update sheet
        $config = $this->prepareConfig();
        $config['parameters']['tables'][] = [
            'id' => 0,
            'fileId' => $gdFile['id'],
            'title' => 'Titanic 1 +',
            'folder' => ['id' => getenv('GOOGLE_DRIVE_FOLDER')],
            'sheetId' => $sheetId,
            'sheetTitle' => 'casualties 1+',
            'tableId' => 'titanic_2',
            'action' => ConfigDefinition::ACTION_UPDATE,
            'enabled' => true,
        ];

        $process = $this->runProcess($config);
        $this->assertEquals(0, $process->getExitCode(), $process->getOutput());

        $response = $this->client->getSpreadsheet($gdFile['id']);
        $values = $this->client->getSpreadsheetValues($gdFile['id'], urlencode('casualties 1+'));

        $this->assertEquals($gdFile['id'], $response['spreadsheetId']);
        $this->assertEquals('Titanic 1 +', $response['properties']['title']);
        $this->assertEquals('casualties 1+', $response['sheets'][0]['properties']['title']);
        $this->assertEquals($this->csvToArray($this->dataPath . '/in/tables/titanic_2.csv'), $values['values']);

        $this->client->deleteFile($gdFile['id']);
    }

    public function testUpdateSpreadsheetWithStringId(): void
    {
        $this->prepareDataFiles();

        // create sheet
        $gdFile = $this->client->createFile(
            $this->dataPath . '/in/tables/titanic_1.csv',
            'Titanic 1 +',
            [
                'parents' => [getenv('GOOGLE_DRIVE_FOLDER')],
                'mimeType' => Client::MIME_TYPE_SPREADSHEET,
            ]
        );

        $gdSpreadsheet = $this->client->getSpreadsheet($gdFile['id']);
        $sheetId = (string) $gdSpreadsheet['sheets'][0]['properties']['sheetId'];

        // update sheet
        $config = $this->prepareConfig();
        $config['parameters']['tables'][] = [
            'id' => 0,
            'fileId' => $gdFile['id'],
            'title' => 'Titanic 1 +',
            'folder' => ['id' => getenv('GOOGLE_DRIVE_FOLDER')],
            'sheetId' => $sheetId,
            'sheetTitle' => 'casualties 1+',
            'tableId' => 'titanic_2',
            'action' => ConfigDefinition::ACTION_UPDATE,
            'enabled' => true,
        ];

        $process = $this->runProcess($config);

        $this->assertEquals(0, $process->getExitCode(), $process->getOutput());

        $response = $this->client->getSpreadsheet($gdFile['id']);
        $values = $this->client->getSpreadsheetValues($gdFile['id'], urlencode('casualties 1+'));

        $this->assertEquals($gdFile['id'], $response['spreadsheetId']);
        $this->assertEquals('Titanic 1 +', $response['properties']['title']);
        $this->assertEquals('casualties 1+', $response['sheets'][0]['properties']['title']);
        $this->assertEquals($this->csvToArray($this->dataPath . '/in/tables/titanic_2.csv'), $values['values']);

        $this->client->deleteFile($gdFile['id']);
    }

    public function testUpdateSpreadsheetInTeamDrive(): void
    {
        $this->prepareDataFiles();

        // create sheet
        $gdFile = $this->client->createFile(
            $this->dataPath . '/in/tables/titanic_1.csv',
            'titanic',
            [
                'parents' => [getenv('GOOGLE_DRIVE_TEAM_FOLDER')],
                'mimeType' => Client::MIME_TYPE_SPREADSHEET,
            ]
        );

        $gdSpreadsheet = $this->client->getSpreadsheet($gdFile['id']);
        $sheetId = $gdSpreadsheet['sheets'][0]['properties']['sheetId'];

        // update sheet
        $config = $this->prepareConfig();
        $config['parameters']['tables'][] = [
            'id' => 0,
            'fileId' => $gdFile['id'],
            'title' => 'titanic',
            'folder' => ['id' => getenv('GOOGLE_DRIVE_TEAM_FOLDER')],
            'sheetId' => $sheetId,
            'sheetTitle' => 'casualties',
            'tableId' => 'titanic_2',
            'action' => ConfigDefinition::ACTION_UPDATE,
            'enabled' => true,
        ];

        $process = $this->runProcess($config);
        $this->assertEquals(0, $process->getExitCode(), $process->getErrorOutput());

        $response = $this->client->getSpreadsheet($gdFile['id']);
        $values = $this->client->getSpreadsheetValues($gdFile['id'], 'casualties');

        $this->assertEquals($gdFile['id'], $response['spreadsheetId']);
        $this->assertEquals('titanic', $response['properties']['title']);
        $this->assertEquals('casualties', $response['sheets'][0]['properties']['title']);
        $this->assertEquals($this->csvToArray($this->dataPath . '/in/tables/titanic_2.csv'), $values['values']);
    }

    public function testUpdateSpreadsheetDisabled(): void
    {
        $this->prepareDataFiles();

        // create sheet
        $gdFile = $this->client->createFile(
            $this->dataPath . '/in/tables/titanic_1.csv',
            'titanic',
            [
                'parents' => [getenv('GOOGLE_DRIVE_FOLDER')],
                'mimeType' => Client::MIME_TYPE_SPREADSHEET,
            ]
        );

        $gdSpreadsheet = $this->client->getSpreadsheet($gdFile['id']);
        $modified = $this->client->getFile($gdFile['id'], ['modifiedTime']);

        $sheetId = $gdSpreadsheet['sheets'][0]['properties']['sheetId'];

        // update sheet
        $config = $this->prepareConfig();
        $config['parameters']['tables'][] = [
            'id' => 0,
            'fileId' => $gdFile['id'],
            'title' => 'titanic',
            'folder' => ['id' => getenv('GOOGLE_DRIVE_FOLDER')],
            'sheetId' => $sheetId,
            'sheetTitle' => 'casualties',
            'tableId' => 'titanic_2',
            'action' => ConfigDefinition::ACTION_UPDATE,
            'enabled' => false,
        ];

        sleep(5);

        $process = $this->runProcess($config);
        $this->assertEquals(0, $process->getExitCode(), $process->getErrorOutput());

        $modified2 = $this->client->getFile($gdFile['id'], ['createdTime', 'modifiedTime']);

        $this->assertEquals($modified['modifiedTime'], $modified2['modifiedTime']);
    }

    public function testUpdateSpreadsheetLong(): void
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

        // create large file
        $inputCsvPath = $this->tmpDataPath . '/in/tables/large.csv';
        touch($inputCsvPath);
        $inputCsv = new CsvFile($inputCsvPath);
        $inputCsv->writeRow(['id', 'random']);
        for ($i = 0; $i < 200000; $i++) {
            $inputCsv->writeRow([$i, uniqid()]);
        }

        // update sheet
        $newSheetTitle = 'Long John Silver';
        $config = $this->prepareConfig();
        $config['parameters']['tables'][] = [
            'id' => 0,
            'fileId' => $gdFile['id'],
            'title' => 'pirates',
            'folder' => ['id' => getenv('GOOGLE_DRIVE_FOLDER')],
            'sheetId' => $sheetId,
            'sheetTitle' => $newSheetTitle,
            'tableId' => 'large',
            'action' => ConfigDefinition::ACTION_UPDATE,
            'enabled' => true,
        ];

        $process = $this->runProcess($config);

        $this->assertEquals(0, $process->getExitCode(), $process->getOutput());

        $response = $this->client->getSpreadsheet($gdFile['id']);
        $values = $this->client->getSpreadsheetValues(
            $gdFile['id'],
            urlencode($newSheetTitle),
            [
                'valueRenderOption' => 'UNFORMATTED_VALUE',
            ]
        );

        $this->assertEquals($gdFile['id'], $response['spreadsheetId']);
        $this->assertEquals('titanic_1', $response['properties']['title']);
        $this->assertEquals($newSheetTitle, $response['sheets'][0]['properties']['title']);
        $this->assertEquals($this->csvToArray($inputCsvPath), $values['values']);

        $this->client->deleteFile($gdFile['id']);
    }

    public function testUpdateSpreadsheetWide(): void
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

        // create large file
        $inputCsvPath = $this->tmpDataPath . '/in/tables/large.csv';
        touch($inputCsvPath);
        $inputCsv = new CsvFile($inputCsvPath);
        $inputCsv->writeRow([
                'id', '1', '2', '3', '4', '5', '6', '7', '8', '9', '10',
                '11', '12', '13', '14', '15', '16', '17', '18', '19', '20',
                '21', '22', '23', '24', '25', '26', '27', '28', '29', '30',
                '31', '32', '33', '34', '35', '36', '37', '38', '39', '40',
            ]);
        for ($i = 0; $i < 5000; $i++) {
            $inputCsv->writeRow([
                $i,
                uniqid(), uniqid(),
                uniqid(), uniqid(),
                uniqid(), uniqid(),
                uniqid(), uniqid(),
                uniqid(), uniqid(),
                uniqid(), uniqid(),
                uniqid(), uniqid(),
                uniqid(), uniqid(),
                uniqid(), uniqid(),
                uniqid(), uniqid(),
                uniqid(), uniqid(),
                uniqid(), uniqid(),
                uniqid(), uniqid(),
                uniqid(), uniqid(),
                uniqid(), uniqid(),
                uniqid(), uniqid(),
                uniqid(), uniqid(),
                uniqid(), uniqid(),
                uniqid(), uniqid(),
                uniqid(), uniqid(),
            ]);
        }

        // update sheet
        $newSheetTitle = 'Long John Silver';
        $config = $this->prepareConfig();
        $config['parameters']['tables'][] = [
            'id' => 0,
            'fileId' => $gdFile['id'],
            'title' => 'pirates',
            'folder' => ['id' => getenv('GOOGLE_DRIVE_FOLDER')],
            'sheetId' => $sheetId,
            'sheetTitle' => $newSheetTitle,
            'tableId' => 'large',
            'action' => ConfigDefinition::ACTION_UPDATE,
            'enabled' => true,
        ];

        $process = $this->runProcess($config);

        $this->assertEquals(0, $process->getExitCode(), $process->getOutput());

        $response = $this->client->getSpreadsheet($gdFile['id']);
        $values = $this->client->getSpreadsheetValues(
            $gdFile['id'],
            urlencode($newSheetTitle),
            [
                'valueRenderOption' => 'UNFORMATTED_VALUE',
            ]
        );

        $this->assertEquals($gdFile['id'], $response['spreadsheetId']);
        $this->assertEquals('titanic_1', $response['properties']['title']);
        $this->assertEquals($newSheetTitle, $response['sheets'][0]['properties']['title']);
        $this->assertEquals($this->csvToArray($inputCsvPath), $values['values']);

        $this->client->deleteFile($gdFile['id']);
    }

    /**
     * Append content to a sheet
     */
    public function testAppendSheet(): void
    {
        $this->prepareDataFiles();

        // create spreadsheet
        $gdFile = $this->client->createFile(
            $this->dataPath . '/in/tables/titanic_1.csv',
            'titanic',
            [
                'parents' => [getenv('GOOGLE_DRIVE_FOLDER')],
                'mimeType' => Client::MIME_TYPE_SPREADSHEET,
            ]
        );

        $gdSpreadsheet = $this->client->getSpreadsheet($gdFile['id']);
        $sheetId = $gdSpreadsheet['sheets'][0]['properties']['sheetId'];

        // append other data do the sheet
        $config = $this->prepareConfig();
        $config['parameters']['tables'][] = [
            'id' => 0,
            'fileId' => $gdFile['id'],
            'title' => 'titanic',
            'folder' => ['id' => getenv('GOOGLE_DRIVE_FOLDER')],
            'sheetId' => $sheetId,
            'sheetTitle' => 'casualties',
            'tableId' => 'titanic_2_append',
            'action' => ConfigDefinition::ACTION_APPEND,
            'enabled' => true,
        ];

        $process = $this->runProcess($config);

        $this->assertEquals(0, $process->getExitCode(), $process->getErrorOutput());

        $config2 = $config;
        $config2['parameters']['tables'][0]['tableId'] = 'titanic_2_append_2';
        $process = $this->runProcess($config2);
        $this->assertEquals(0, $process->getExitCode(), $process->getErrorOutput());

        $response = $this->client->getSpreadsheetValues($gdFile['id'], 'casualties');
        $this->assertEquals($this->csvToArray($this->dataPath . '/in/tables/titanic.csv'), $response['values']);

        $this->client->deleteFile($gdFile['id']);
    }

    public function testAppendSheetLarge(): void
    {
        $this->prepareDataFiles();

        // create sheet
        $gdFile = $this->client->createFileMetadata(
            'titanic_1',
            [
                'parents' => [getenv('GOOGLE_DRIVE_FOLDER')],
                'mimeType' => Client::MIME_TYPE_SPREADSHEET,
            ]
        );

        $gdSpreadsheet = $this->client->getSpreadsheet($gdFile['id']);
        $sheetId = $gdSpreadsheet['sheets'][0]['properties']['sheetId'];

        // create large file
        $inputCsvPath = $this->tmpDataPath . '/in/tables/large-append.csv';
        touch($inputCsvPath);
        $inputCsv = new CsvFile($inputCsvPath);
        $inputCsv->writeRow(['id', 'random_string']);
        for ($i = 0; $i < 1000; $i++) {
            $inputCsv->writeRow([$i, uniqid()]);
        }

        // update sheet
        $newSheetTitle = 'Long John Silver';
        $config = $this->prepareConfig();
        $config['parameters']['tables'][] = [
            'id' => 0,
            'fileId' => $gdFile['id'],
            'title' => 'pirates',
            'folder' => ['id' => getenv('GOOGLE_DRIVE_FOLDER')],
            'sheetId' => $sheetId,
            'sheetTitle' => $newSheetTitle,
            'tableId' => 'large-append',
            'action' => ConfigDefinition::ACTION_APPEND,
            'enabled' => true,
        ];

        $process = $this->runProcess($config);
        $this->assertEquals(0, $process->getExitCode(), $process->getErrorOutput());
        $response = $this->client->getSpreadsheetValues($gdFile['id'], $newSheetTitle);
        $this->assertCount(1001, $response['values']);

        $process = $this->runProcess($config);
        $this->assertEquals(0, $process->getExitCode(), $process->getErrorOutput());
        $response = $this->client->getSpreadsheetValues($gdFile['id'], $newSheetTitle);
        $this->assertCount(2001, $response['values']);

        $process = $this->runProcess($config);
        $this->assertEquals(0, $process->getExitCode(), $process->getErrorOutput());
        $response = $this->client->getSpreadsheetValues($gdFile['id'], $newSheetTitle);
        $this->assertCount(3001, $response['values']);
    }

    public function testAppendToEmptySheet(): void
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

        $gdSpreadsheet = $this->client->getSpreadsheet($gdFile['id']);
        $sheetId = $gdSpreadsheet['sheets'][0]['properties']['sheetId'];

        // append other data do the sheet
        $config = $this->prepareConfig();
        $config['parameters']['tables'][] = [
            'id' => 0,
            'fileId' => $gdFile['id'],
            'title' => 'titanic',
            'folder' => ['id' => getenv('GOOGLE_DRIVE_FOLDER')],
            'sheetId' => $sheetId,
            'sheetTitle' => 'casualties',
            'tableId' => 'titanic_2_append',
            'action' => ConfigDefinition::ACTION_APPEND,
            'enabled' => true,
        ];

        $process = $this->runProcess($config);
        $this->assertEquals(0, $process->getExitCode(), $process->getErrorOutput());

        $response = $this->client->getSpreadsheetValues($gdFile['id'], 'casualties');
        $this->assertEquals(
            $this->csvToArray($this->dataPath . '/in/tables/titanic_2_append.csv'),
            $response['values']
        );

        $this->client->deleteFile($gdFile['id']);
    }

    public function testSheetNotFoundException(): void
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
        $this->client->deleteSheet((string) $gdFile['id'], (string) $sheetId);

        // run
        $config = $this->prepareConfig();
        $config['parameters']['tables'][] = [
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

        $process = $this->runProcess($config);

        $this->assertEquals(1, $process->getExitCode(), $process->getErrorOutput());
    }

    /**
     * Create New Spreadsheet using sync action
     */
    public function testSyncActionCreateSpreadsheet(): void
    {
        $this->prepareDataFiles();

        $config = $this->prepareConfig();
        $config['action'] = 'createSpreadsheet';
        $config['parameters']['tables'][] = [
            'id' => 0,
            'title' => 'titanic',
            'enabled' => true,
            'folder' => ['id' => getenv('GOOGLE_DRIVE_FOLDER')],
            'action' => ConfigDefinition::ACTION_UPDATE,
        ];

        $process = $this->runProcess($config);
        $this->assertEquals(0, $process->getExitCode(), $process->getErrorOutput());
        $response = json_decode($process->getOutput(), true);

        $gdFile = $this->client->getSpreadsheet($response['spreadsheet']['spreadsheetId']);
        $this->assertArrayHasKey('spreadsheetId', $gdFile);
        $this->assertEquals('titanic', $gdFile['properties']['title']);

        $this->client->deleteFile($response['spreadsheet']['spreadsheetId']);
    }

    public function testSyncActionCreateSpreadsheet404(): void
    {
        $this->prepareDataFiles();

        $config = $this->prepareConfig();
        $config['action'] = 'createSpreadsheet';
        $config['parameters']['tables'][] = [
            'id' => 0,
            'title' => 'titanic',
            'enabled' => true,
            'folder' => ['id' => 'non-existent-folder-id'],
            'action' => ConfigDefinition::ACTION_UPDATE,
        ];

        $process = $this->runProcess($config);
        $this->assertEquals(1, $process->getExitCode(), $process->getErrorOutput());
        $response = json_decode($process->getOutput(), true);
        $this->assertEquals('error', $response['status']);
        $this->assertEquals('User Error', $response['error']);
        $this->assertStringContainsString('File or folder not found.', $response['message']);
    }

    /**
     * Add Sheet to a Spreadsheet using sync action
     */
    public function testSyncActionAddSheet(): void
    {
        $this->prepareDataFiles();

        // create spreadsheet
        $gdFile = $this->client->createFile(
            $this->dataPath . '/in/tables/titanic_1.csv',
            'titanic',
            [
                'parents' => [getenv('GOOGLE_DRIVE_FOLDER')],
                'mimeType' => Client::MIME_TYPE_SPREADSHEET,
            ]
        );

        $config = $this->prepareConfig();
        $config['action'] = 'addSheet';
        $config['parameters']['tables'][] = [
            'id' => 0,
            'fileId' => $gdFile['id'],
            'title' => 'titanic',
            'folder' => ['id' => getenv('GOOGLE_DRIVE_FOLDER')],
            'sheetTitle' => 'Sheet2',
            'enabled' => true,
            'action' => ConfigDefinition::ACTION_UPDATE,
        ];

        $process = $this->runProcess($config);
        $this->assertEquals(0, $process->getExitCode(), $process->getErrorOutput());
        $response = json_decode($process->getOutput(), true);

        $this->assertArrayHasKey('sheetId', $response['sheet']);
        $this->assertArrayHasKey('title', $response['sheet']);

        $gdSpreadsheet = $this->client->getSpreadsheet($gdFile['id']);
        $this->assertCount(2, $gdSpreadsheet['sheets']);
        $this->assertEquals('Sheet2', $gdSpreadsheet['sheets'][1]['properties']['title']);

        $this->client->deleteFile($gdFile['id']);
    }

    /**
     * Add Sheet with same title to a Spreadsheet using sync action
     * This will return the existing sheet resource
     */
    public function testSyncActionAddSheetExisting(): void
    {
        $this->prepareDataFiles();

        // create spreadsheet
        $sheetTitle = 'titanic';
        $gdFile = $this->client->createFile(
            $this->dataPath . '/in/tables/titanic_1.csv',
            'titanic',
            [
                'parents' => [getenv('GOOGLE_DRIVE_FOLDER')],
                'mimeType' => Client::MIME_TYPE_SPREADSHEET,
            ]
        );

        $config = $this->prepareConfig();
        $config['action'] = 'addSheet';
        $config['parameters']['tables'][] = [
            'id' => 0,
            'fileId' => $gdFile['id'],
            'title' => 'titanic',
            'folder' => ['id' => getenv('GOOGLE_DRIVE_FOLDER')],
            'sheetTitle' => $sheetTitle,
            'enabled' => true,
            'action' => ConfigDefinition::ACTION_UPDATE,
        ];

        $process = $this->runProcess($config);
        $this->assertEquals(0, $process->getExitCode(), $process->getErrorOutput());
        $response = json_decode($process->getOutput(), true);

        $this->assertArrayHasKey('sheetId', $response['sheet']);
        $this->assertArrayHasKey('title', $response['sheet']);

        $gdSpreadsheet = $this->client->getSpreadsheet($gdFile['id']);
        $this->assertCount(1, $gdSpreadsheet['sheets']);
        $this->assertEquals($sheetTitle, $gdSpreadsheet['sheets'][0]['properties']['title']);

        $this->client->deleteFile($gdFile['id']);
    }

    public function testSyncActionDeleteSheet(): void
    {
        $this->prepareDataFiles();

        // create spreadsheet
        $gdFile = $this->client->createFile(
            $this->dataPath . '/in/tables/titanic_1.csv',
            'titanic',
            [
                'parents' => [getenv('GOOGLE_DRIVE_FOLDER')],
                'mimeType' => Client::MIME_TYPE_SPREADSHEET,
            ]
        );

        // add sheet
        $this->client->addSheet(
            $gdFile['id'],
            [
                'properties' => [
                    'title' => 'Sheet2',
                ],
            ]
        );

        $gdSpreadsheet = $this->client->getSpreadsheet($gdFile['id']);
        $sheet2Id = $gdSpreadsheet['sheets'][1]['properties']['sheetId'];

        // delete sheet
        $config = $this->prepareConfig();
        $config['action'] = 'deleteSheet';
        $config['parameters']['tables'][] = [
            'id' => 0,
            'fileId' => $gdFile['id'],
            'title' => 'titanic',
            'folder' => ['id' => getenv('GOOGLE_DRIVE_FOLDER')],
            'sheetId' => $sheet2Id,
        ];

        $process = $this->runProcess($config);
        $this->assertEquals(0, $process->getExitCode(), $process->getErrorOutput());
        $response = json_decode($process->getOutput(), true);
        $this->assertEquals('ok', $response['status']);

        $gdSpreadsheet = $this->client->getSpreadsheet($gdFile['id']);
        $this->assertCount(1, $gdSpreadsheet['sheets']);

        $this->client->deleteFile($gdFile['id']);
    }

    /**
     * Create New Spreadsheet using sync action
     */
    public function testSyncActionGetSpreadsheet(): void
    {
        $this->prepareDataFiles();

        // create spreadsheet
        $gdFile = $this->client->createFile(
            $this->dataPath . '/in/tables/titanic_1.csv',
            'titanic',
            [
                'parents' => [getenv('GOOGLE_DRIVE_FOLDER')],
                'mimeType' => Client::MIME_TYPE_SPREADSHEET,
            ]
        );

        $config = $this->prepareConfig();
        $config['action'] = 'getSpreadsheet';
        $config['parameters']['tables'][] = [
            'id' => 0,
            'title' => 'titanic',
            'fileId' => $gdFile['id'],
            'enabled' => true,
            'folder' => ['id' => getenv('GOOGLE_DRIVE_FOLDER')],
            'action' => ConfigDefinition::ACTION_UPDATE,
        ];

        $process = $this->runProcess($config);
        $this->assertEquals(0, $process->getExitCode(), $process->getErrorOutput());
        $response = json_decode($process->getOutput(), true);
        $this->assertEquals($gdFile['id'], $response['spreadsheet']['spreadsheetId']);

        $this->client->deleteFile($gdFile['id']);
    }

    /**
     * @param array $config
     */
    private function runProcess(array $config): Process
    {
        file_put_contents($this->tmpDataPath . '/config.json', json_encode($config));

        $process = new Process(['php', 'run.php', sprintf('--data=%s', $this->tmpDataPath)]);
        $process->setTimeout(500);
        $process->run();

        return $process;
    }

    /**
     * @dataProvider provideMissingOauthConfig
     */
    public function testMissingOauth(array $config): void
    {
        $fs = new Filesystem();
        $fs->remove($this->tmpDataPath);
        $fs->mkdir($this->tmpDataPath);
        $process = $this->runProcess($config);
        $this->assertEquals(1, $process->getExitCode());
        $this->assertStringContainsString(
            'Missing authorization data',
            $process->getErrorOutput(),
            $process->getOutput()
        );
    }

    public function provideMissingOauthConfig(): Generator
    {
        $parameters = [
            'parameters' => [
                'data_dir' => $this->dataPath,
                'tables' => [[
                    'id' => 0,
                    'title' => 'titanic',
                    'fileId' => 1,
                    'enabled' => true,
                    'folder' => ['id' => getenv('GOOGLE_DRIVE_FOLDER')],
                    'action' => ConfigDefinition::ACTION_UPDATE,
                ]],
            ],
        ];

        yield 'missing authorization' => [$parameters];

        yield 'missing oauth_api' => [$parameters, 'authorization' => []];

        yield 'missing credentials' => [$parameters, 'authorization' => ['oauth_api' => []]];

        yield 'missing data' => [$parameters, 'authorization' => ['oauth_api' => ['credentials' => [
            'appKey' => getenv('CLIENT_ID'),
            '#appSecret' => getenv('CLIENT_SECRET'),
        ]]]];

        yield 'missing appKey' => [$parameters, 'authorization' => ['oauth_api' => ['credentials' => [
            '#appSecret' => getenv('CLIENT_SECRET'),
            '#data' => json_encode([
                'access_token' => getenv('ACCESS_TOKEN'),
                'refresh_token' => getenv('REFRESH_TOKEN'),
            ]),
        ]]]];

        yield 'missing appSecret' => [$parameters, 'authorization' => ['oauth_api' => ['credentials' => [
            'appKey' => getenv('CLIENT_ID'),
            '#data' => json_encode([
                'access_token' => getenv('ACCESS_TOKEN'),
                'refresh_token' => getenv('REFRESH_TOKEN'),
            ]),
        ]]]];
    }
}
