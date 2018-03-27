<?php

declare(strict_types=1);

namespace Keboola\GoogleSheetsWriter;

use GuzzleHttp\Exception\ClientException;
use Keboola\GoogleSheetsWriter\Configuration\ConfigDefinition;
use Keboola\GoogleSheetsWriter\Exception\ApplicationException;
use Keboola\GoogleSheetsWriter\Exception\UserException;
use Keboola\GoogleSheetsWriter\Input\Table;
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

    /**
     * @param array $sheet
     * @throws UserException
     * @throws ApplicationException
     * @throws \Keboola\Google\ClientBundle\Exception\RestApiException
     */
    public function process(array $sheet) : void
    {
        try {
            $this->updateMetadata($sheet);

            if ($sheet['action'] === ConfigDefinition::ACTION_UPDATE) {
                $this->client->clearSpreadsheetValues($sheet['fileId'], urlencode($sheet['sheetTitle']));
            }

            $this->uploadValues($sheet, $this->inputTable);
        } catch (ClientException $e) {
            //@todo handle API exception
            throw new UserException($e->getMessage(), 0, $e, [
                'response' => $e->getResponse()->getBody()->getContents(),
                'reasonPhrase' => $e->getResponse()->getReasonPhrase(),
            ]);
        }
    }

    private function getRange(string $sheetTitle, int $columnCount, int $rowOffset = 1, int $rowLimit = 1000) : string
    {
        $lastColumn = $this->getColumnA1($columnCount-1);
        $start = 'A' . $rowOffset;
        $end = $lastColumn . ($rowOffset + $rowLimit - 1);

        return urlencode($sheetTitle) . '!' . $start . ':' . $end;
    }

    private function getColumnA1(int $columnNumber) : string
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

    /**
     * @param array $sheet
     * @param Table $inputTable
     * @return array
     * @throws ApplicationException
     * @throws \Keboola\Google\ClientBundle\Exception\RestApiException
     */
    private function uploadValues(array $sheet, Table $inputTable) : array
    {
        // insert new values, 1000 rows at a time
        $responses = [];
        $offset = 1;
        $limit = 1000;
        $csvFile = $inputTable->getCsvFile();
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
                    $range = $this->getRange(
                        $sheet['sheetTitle'],
                        $inputTable->getColumnCount(),
                        $offset,
                        $limit
                    );

                    // increase grid size of the sheet
                    $gridProperties = [
                        'columnCount' => $inputTable->getColumnCount(),
                        'rowCount' => $offset + $limit - 1,
                    ];
                    $this->updateMetadata($sheet, $gridProperties);

                    $responses[] = $this->updateValues($sheet, $values, $range);
                    break;
                case ConfigDefinition::ACTION_APPEND:
                    // if sheet already contains header, strip header from values to be uploaded
                    if ($offset === 1) {
                        $sheetValues = $this->client->getSpreadsheetValues(
                            $sheet['fileId'],
                            urlencode($sheet['sheetTitle'])
                        );

                        if (!empty($sheetValues['values'])) {
                            array_shift($values);
                        }
                    }
                    $responses[] = $this->appendValues($sheet, $values);
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
     * @param array $sheet
     * @param array $values
     * @param string $range
     * @return array
     * @throws \Keboola\Google\ClientBundle\Exception\RestApiException
     */
    private function updateValues(array $sheet, array $values, string $range) : array
    {
        return $this->client->updateSpreadsheetValues(
            $sheet['fileId'],
            $range,
            $values
        );
    }

    /**
     * @param array $sheet
     * @param array $values
     * @return array
     * @throws \Keboola\Google\ClientBundle\Exception\RestApiException
     */
    private function appendValues(array $sheet, array $values) : array
    {
        return $this->client->appendSpreadsheetValues(
            $sheet['fileId'],
            $sheet['sheetTitle'],
            $values
        );
    }

    /**
     * Update sheets metadata - title, columnCount, rowCount
     *
     * @param array $sheet
     * @param array $gridProperties
     *      [
     *          'rowCount' => NUMBER OF ROWS
     *          'columnCount' => NUMBER OF COLUMNS
     *      ]
     * @return array
     * @throws \Keboola\Google\ClientBundle\Exception\RestApiException
     */
    private function updateMetadata(array $sheet, array $gridProperties = []) : array
    {
        // update sheets properties - title and gridProperties
        $request = [
            'updateSheetProperties' => [
                'properties' => [
                    'sheetId' => $sheet['sheetId'],
                    'title' => $sheet['sheetTitle'],
                ],
                'fields' => 'title',
            ],
        ];

        if (!empty($gridProperties)) {
            $request['updateSheetProperties']['properties']['gridProperties'] = $gridProperties;
            $request['updateSheetProperties']['fields'] = 'title,gridProperties';
        }

        return $this->client->batchUpdateSpreadsheet($sheet['fileId'], [
            'requests' => [$request],
        ]);
    }
}
