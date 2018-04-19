<?php

declare(strict_types=1);

namespace Keboola\GoogleSheetsWriter;

use GuzzleHttp\Exception\ClientException;
use Keboola\GoogleSheetsWriter\Configuration\ConfigDefinition;
use Keboola\GoogleSheetsWriter\Exception\ApplicationException;
use Keboola\GoogleSheetsWriter\Exception\UserException;
use Keboola\GoogleSheetsWriter\Input\Table;
use Keboola\GoogleSheetsClient\Client;
use Monolog\Logger;

class Sheet
{
    /** @var Client */
    private $client;

    /** @var Table */
    private $inputTable;

    /** @var Logger */
    private $logger;

    public function __construct(Client $client, Table $inputTable, Logger $logger)
    {
        $this->client = $client;
        $this->inputTable = $inputTable;
        $this->logger = $logger;
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
            // Update sheets title and grid properties.
            // This will adjust the column count to the size of the uploaded table,
            // so the limit of 2M cells can be used efficiently.
            $spreadsheet = $this->client->getSpreadsheet($sheet['fileId']);
            $sheetProperties = $this->findSheetPropertiesById($spreadsheet['sheets'], $sheet['sheetId']);
            $gridProperties = [
                'columnCount' => $this->inputTable->getColumnCount(),
                'rowCount' => $sheetProperties['properties']['gridProperties']['rowCount'],
            ];

            $this->updateMetadata($sheet, $gridProperties);

            if ($sheet['action'] === ConfigDefinition::ACTION_UPDATE) {
                $this->client->clearSpreadsheetValues($sheet['fileId'], urlencode($sheet['sheetTitle']));
            }

            // upload data
            $this->uploadValues($sheet, $this->inputTable);
        } catch (ClientException $e) {
            //@todo handle API exception
            throw new UserException($e->getMessage(), 0, $e, [
                'response' => $e->getResponse()->getBody()->getContents(),
                'reasonPhrase' => $e->getResponse()->getReasonPhrase(),
            ]);
        }
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
        $this->logger->debug("Uploading values", ['sheet' => $sheet]);

        // insert new values, 1000 rows at a time
        $responses = [];
        $offset = 1;
        $limit = 5000;
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
                    $responses[] = $this->appendValues($sheet, $values);
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
     * @return array
     * @throws \Keboola\Google\ClientBundle\Exception\RestApiException
     */
    private function appendValues(array $sheet, array $values) : array
    {
        return $this->client->appendSpreadsheetValues(
            $sheet['fileId'],
            urlencode($sheet['sheetTitle']),
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

    private function findSheetPropertiesById(array $sheets, int $sheetId) : array
    {
        $results = array_filter($sheets, function ($item) use ($sheetId) {
            return $item['properties']['sheetId'] === $sheetId;
        });
        return array_shift($results);
    }
}
