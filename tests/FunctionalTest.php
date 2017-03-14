<?php
/**
 * Created by PhpStorm.
 * User: miroslavcillik
 * Date: 10/08/16
 * Time: 16:45
 */
namespace Keboola\GoogleSheetsWriter\Tests;

use Keboola\Csv\CsvFile;
use Keboola\GoogleSheetsClient\Client;
use Keboola\GoogleSheetsWriter\Configuration\ConfigDefinition;
use Keboola\GoogleSheetsWriter\Test\BaseTest;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

class FunctionalTest extends BaseTest
{
    private $tmpDataPath = '/tmp/data-test';

    public function setUp()
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
    public function testUpdateSpreadsheet()
    {
        $this->prepareDataFiles();

        // create sheet
        $gdFile = $this->client->createFile(
            $this->dataPath . '/in/tables/titanic_1.csv',
            'titanic_1',
            [
                'folder' => ['id' => getenv('GOOGLE_DRIVE_FOLDER')],
                'mimeType' => Client::MIME_TYPE_SPREADSHEET
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
            'folder' => ['id' => getenv('GOOGLE_DRIVE_FOLDER')],
            'sheetId' => $sheetId,
            'sheetTitle' => 'casualties',
            'tableId' => 'titanic_2',
            'action' => ConfigDefinition::ACTION_UPDATE,
            'enabled' => true
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

    /**
     * Update large Spreadsheet
     */
    public function testUpdateSpreadsheetLarge()
    {
        $this->prepareDataFiles();

        // create sheet
        $gdFile = $this->client->createFile(
            $this->dataPath . '/in/tables/titanic_1.csv',
            'titanic_1',
            [
                'parents' => [getenv('GOOGLE_DRIVE_FOLDER')],
                'mimeType' => Client::MIME_TYPE_SPREADSHEET
            ]
        );

        $gdSpreadsheet = $this->client->getSpreadsheet($gdFile['id']);
        $sheetId = $gdSpreadsheet['sheets'][0]['properties']['sheetId'];

        // create large file
        $inputCsvPath = $this->tmpDataPath . '/in/tables/large.csv';
        touch($inputCsvPath);
        $inputCsv = new CsvFile($inputCsvPath);
        $inputCsv->writeRow(['id', 'random_string']);
        for ($i = 0; $i < 2000; $i++) {
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
            'enabled' => true
        ];

        $process = $this->runProcess($config);

        $this->assertEquals(0, $process->getExitCode(), $process->getOutput());

        $response = $this->client->getSpreadsheet($gdFile['id']);
        $values = $this->client->getSpreadsheetValues(
            $gdFile['id'],
            urlencode($newSheetTitle),
            [
                'valueRenderOption' => 'UNFORMATTED_VALUE'
            ]
        );

        $this->assertEquals($gdFile['id'], $response['spreadsheetId']);
        $this->assertEquals('pirates', $response['properties']['title']);
        $this->assertEquals($newSheetTitle, $response['sheets'][0]['properties']['title']);
        $this->assertEquals($this->csvToArray($inputCsvPath), $values['values']);
    }

    /**
     * Append content to a sheet
     */
    public function testAppendSheet()
    {
        $this->prepareDataFiles();

        // create spreadsheet
        $gdFile = $this->client->createFile(
            $this->dataPath . '/in/tables/titanic_1.csv',
            'titanic',
            [
                'parents' => [getenv('GOOGLE_DRIVE_FOLDER')],
                'mimeType' => Client::MIME_TYPE_SPREADSHEET
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
            'tableId' => 'titanic_2_headerless',
            'action' => ConfigDefinition::ACTION_APPEND,
            'enabled' => true
        ];

        $process = $this->runProcess($config);
        $this->assertEquals(0, $process->getExitCode(), $process->getErrorOutput());

        $response = $this->client->getSpreadsheetValues($gdFile['id'], 'casualties');
        $this->assertEquals($this->csvToArray($this->dataPath . '/in/tables/titanic.csv'), $response['values']);
    }

    /**
     * Create New Spreadsheet using sync action
     */
    public function testSyncActionCreateSpreadsheet()
    {
        $this->prepareDataFiles();

        $config = $this->prepareConfig();
        $config['action'] = 'createSpreadsheet';
        $config['parameters']['tables'][] = [
            'id' => 0,
            'title' => 'titanic',
            'enabled' => true,
            'folder' => ['id' => getenv('GOOGLE_DRIVE_FOLDER')],
            'action' => ConfigDefinition::ACTION_UPDATE
        ];

        $process = $this->runProcess($config);
        $this->assertEquals(0, $process->getExitCode(), $process->getErrorOutput());
        $response = json_decode($process->getOutput(), true);
        $gdFile = $this->client->getSpreadsheet($response['spreadsheet']['spreadsheetId']);
        $this->assertArrayHasKey('spreadsheetId', $gdFile);
        $this->assertEquals('titanic', $gdFile['properties']['title']);
    }

    /**
     * Add Sheet to a Spreadsheet using sync action
     */
    public function testSyncActionAddSheet()
    {
        $this->prepareDataFiles();

        // create spreadsheet
        $gdFile = $this->client->createFile(
            $this->dataPath . '/in/tables/titanic_1.csv',
            'titanic',
            [
                'parents' => [getenv('GOOGLE_DRIVE_FOLDER')],
                'mimeType' => Client::MIME_TYPE_SPREADSHEET
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
            'action' => ConfigDefinition::ACTION_UPDATE
        ];

        $process = $this->runProcess($config);
        $this->assertEquals(0, $process->getExitCode(), $process->getErrorOutput());
        $response = json_decode($process->getOutput(), true);
        $this->assertArrayHasKey('replies', $response['sheet']);
        $this->assertArrayHasKey('spreadsheetId', $response['sheet']);

        $gdFile = $this->client->getSpreadsheet($response['sheet']['spreadsheetId']);
        $this->assertCount(2, $gdFile['sheets']);
        $this->assertEquals('Sheet2', $gdFile['sheets'][1]['properties']['title']);
    }

    public function testSyncActionDeleteSheet()
    {
        $this->prepareDataFiles();

        // create spreadsheet
        $gdFile = $this->client->createFile(
            $this->dataPath . '/in/tables/titanic_1.csv',
            'titanic',
            [
                'parents' => [getenv('GOOGLE_DRIVE_FOLDER')],
                'mimeType' => Client::MIME_TYPE_SPREADSHEET
            ]
        );

        // add sheet
        $this->client->addSheet(
            $gdFile['id'],
            [
                'properties' => [
                    'title' => 'Sheet2'
                ]
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
            'sheetId' => $sheet2Id
        ];

        $process = $this->runProcess($config);
        $this->assertEquals(0, $process->getExitCode(), $process->getErrorOutput());
        $response = json_decode($process->getOutput(), true);
        $this->assertEquals('ok', $response['status']);

        $gdSpreadsheet = $this->client->getSpreadsheet($gdFile['id']);
        $this->assertCount(1, $gdSpreadsheet['sheets']);
    }

    /**
     * @param $config
     * @return Process
     */
    private function runProcess($config)
    {
        file_put_contents($this->tmpDataPath . '/config.json', json_encode($config));

        $process = new Process(sprintf('php run.php --data=%s', $this->tmpDataPath));
        $process->setTimeout(180);
        $process->run();

        return $process;
    }

    private function prepareDataFiles()
    {
        $fs = new Filesystem();
        $fs->remove($this->tmpDataPath);
        $fs->mkdir($this->tmpDataPath);
        $fs->mkdir($this->tmpDataPath . '/in/tables/');
        $fs->copy($this->dataPath . '/in/tables/titanic.csv', $this->tmpDataPath . '/in/tables/titanic.csv');
        $fs->copy($this->dataPath . '/in/tables/titanic_1.csv', $this->tmpDataPath . '/in/tables/titanic_1.csv');
        $fs->copy($this->dataPath . '/in/tables/titanic_2.csv', $this->tmpDataPath . '/in/tables/titanic_2.csv');
        $fs->copy(
            $this->dataPath . '/in/tables/titanic_2_headerless.csv',
            $this->tmpDataPath . '/in/tables/titanic_2_headerless.csv'
        );
    }
}
