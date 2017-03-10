<?php
/**
 * Created by PhpStorm.
 * User: miroslavcillik
 * Date: 17/01/17
 * Time: 14:23
 */

namespace Keboola\GoogleDriveWriter\Writer;

use GuzzleHttp\Exception\ClientException;
use Keboola\GoogleDriveWriter\Configuration\ConfigDefinition;
use Keboola\GoogleDriveWriter\Exception\ApplicationException;
use Keboola\GoogleDriveWriter\Exception\UserException;
use Keboola\GoogleDriveWriter\Input\Table;
use Keboola\GoogleSheetsClient\Client;

class Sheet
{
    /** @var Client */
    private $client;

    /** @var Table */
    private $inputTable;

    public function __construct(Client $client, Table $inputTable)
    {
        $this->client = $client;
        $this->inputTable = $inputTable;
    }

    public function process($sheet)
    {
        // get metadata from Google Drive file
        $gdFile = $this->getFile($sheet);

        // sync metadata (parent folder, title)
        $this->syncFileMetadata($sheet, $gdFile);

        // update sheets metadata (title, rows and cols count) first
        $this->updateSheetMetadata($sheet, [
            'rowCount' => $this->inputTable->getRowCount(),
            'columnCount' => $this->inputTable->getColumnCount()
        ]);

        // upload data
        try {
            $this->uploadValues($sheet['fileId'], $sheet);
        } catch (ClientException $e) {
            //@todo handle API exception
            throw new UserException($e->getMessage(), 0, $e, [
                'response' => $e->getResponse()->getBody()->getContents(),
                'reasonPhrase' => $e->getResponse()->getReasonPhrase()
            ]);
        }
    }

    /**
     * @param $sheetTitle
     * @param $columnCount
     * @param int $rowOffset
     * @param int $rowLimit
     * @return string
     */
    private function getRange($sheetTitle, $columnCount, $rowOffset = 1, $rowLimit = 1000)
    {
        $lastColumn = $this->getColumnA1($columnCount-1);
        $start = 'A' . $rowOffset;
        $end = $lastColumn . ($rowOffset + $rowLimit - 1);

        return urlencode($sheetTitle) . '!' . $start . ':' . $end;
    }

    /**
     * @param $columnNumber
     * @return string
     */
    private function getColumnA1($columnNumber)
    {
        $alphas = range('A', 'Z');

        $prefix = '';
        if ($columnNumber > 25) {
            $quotient = intval(floor($columnNumber/26));
            $prefix = $alphas[$quotient-1];
        }

        $remainder = $columnNumber%26;

        return $prefix . $alphas[$remainder];
    }


    private function getFile($sheet)
    {
        try {
            return $this->client->getFile($sheet['fileId'], ['id', 'name', 'parents']);
        } catch (ClientException $e) {
            if ($e->getResponse()->getStatusCode() == 404) {
                throw new UserException(sprintf(
                    'File %s (%s) not found',
                    $sheet['title'],
                    $sheet['fileId']
                ));
            }
            throw $e;
        }
    }

    private function uploadValues($sheet, Table $inputTable)
    {
        // clear values
        if ($sheet['action'] === ConfigDefinition::ACTION_UPDATE) {
            $this->client->clearSpreadsheetValues($sheet['fileId'], urlencode($sheet['sheetTitle']));
        }

        // insert new values
        $csvFile = $inputTable->getCsvFile();
        $offset = 1;
        $limit = 1000;
        $responses = [];
        while ($csvFile->current()) {
            $i = 0;
            $values = [];
            while ($i < $limit && $csvFile->current()) {
                $values[] = $csvFile->current();
                $csvFile->next();
                $i++;
            }

            switch ($sheet['action']) {
                case ConfigDefinition::ACTION_UPDATE:
                    $responses[] = $this->client->updateSpreadsheetValues(
                        $sheet['fileId'],
                        $this->getRange($sheet['sheetTitle'], $inputTable->getColumnCount(), $offset, $limit),
                        $values
                    );
                    break;
                case ConfigDefinition::ACTION_APPEND:
                    $responses[] = $this->client->appendSpreadsheetValues(
                        $sheet['fileId'],
                        urlencode($sheet['sheetTitle']),
                        $values
                    );
                    break;
                default:
                    throw new ApplicationException(sprintf("Action '%s' not allowed", $sheet['action']));
                    break;
            }

            $offset = $offset + $i;
        }

        return $responses;
    }

    /**
     * Sync title and parent folder
     *
     * @param $sheet
     * @param $gdFile
     */
    private function syncFileMetadata($sheet, $gdFile)
    {
        $parentsToAdd = [];
        foreach ($sheet['parents'] as $parent) {
            if (false === array_search($parent, $gdFile['parents'])) {
                $parentsToAdd[] = $parent;
            }
        }
        $body = [];
        if ($sheet['title'] !== $gdFile['name']) {
            $body['name'] = $sheet['title'];
        }
        $params = [];
        if (!empty($parentsToAdd)) {
            $params['addParents'] = $parentsToAdd;
        }

        if (!empty($body) || !empty($params)) {
            $this->client->updateFileMetadata($gdFile['id'], $body, $params);
        }
    }

    /**
     * Update sheets metadata - title, columnCount, rowCount
     *
     * @param $sheet
     * @param $gridProperties
     *      [
     *          'rowCount' => NUMBER OF ROWS
     *          'columnCount' => NUMBER OF COLUMNS
     *      ]
     */
    private function updateSheetMetadata($sheet, $gridProperties)
    {
        // update sheets properties - title and gridProperties
        $requests[] = [
            'updateSheetProperties' => [
                'properties' => [
                    'sheetId' => $sheet['sheetId'],
                    'title' => $sheet['sheetTitle'],
                    'gridProperties' => $gridProperties
                ],
                'fields' => 'title,gridProperties'
            ]
        ];

        if (!empty($requests)) {
            $this->client->batchUpdateSpreadsheet($sheet['fileId'], [
                'requests' => $requests
            ]);
        }
    }
}
