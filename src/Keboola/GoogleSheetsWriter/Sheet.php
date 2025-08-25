<?php

declare(strict_types=1);

namespace Keboola\GoogleSheetsWriter;

use GuzzleHttp\Exception\ClientException;
use Keboola\GoogleSheetsClient\Client;
use Keboola\GoogleSheetsWriter\Configuration\ConfigDefinition;
use Keboola\GoogleSheetsWriter\Exception\ApplicationException;
use Keboola\GoogleSheetsWriter\Exception\UserException;
use Keboola\GoogleSheetsWriter\Input\Page;
use Keboola\GoogleSheetsWriter\Input\Paginator;
use Keboola\GoogleSheetsWriter\Input\Table;
use Monolog\Logger;

class Sheet
{
    private Client $client;

    private Table $inputTable;

    private Logger $logger;

    public function __construct(Client $client, Table $inputTable, Logger $logger)
    {
        $this->client = $client;
        $this->inputTable = $inputTable;
        $this->logger = $logger;
    }

    public function process(array $sheet): array
    {
        try {
            // Update sheets title and grid properties.
            // This will adjust the columns and rows count to the size of the uploaded table
            $sheetProperties = $this->getSheetProperties($sheet['fileId'], $sheet['sheetId']);
            $columnCountSrc = $this->inputTable->getColumnCount();
            $rowCountSrc = $this->inputTable->getRowCount();
            $this->preFlightChecks($sheet, $sheetProperties, $columnCountSrc, $rowCountSrc);

            // update columns
            $rowCountDst = $sheetProperties['properties']['gridProperties']['rowCount'];
            $this->updateMetadata($sheet, ['columnCount' => $columnCountSrc, 'rowCount' => $rowCountDst]);

            // upload data
            switch ($sheet['action']) {
                case ConfigDefinition::ACTION_UPDATE:
                    $this->client->clearSpreadsheetValues(
                        $sheet['fileId'],
                        $this->getRange($sheet['sheetTitle'], $columnCountSrc, 1, $rowCountDst)
                    );

                    // update rows to match source size
                    $this->updateMetadata($sheet, ['columnCount' => $columnCountSrc, 'rowCount' => $rowCountSrc]);

                    return $this->updateAction($sheet, $this->inputTable);
                case ConfigDefinition::ACTION_APPEND:
                    return $this->appendAction($sheet, $this->inputTable);
                default:
                    throw new ApplicationException(sprintf('Unknown action "%s"', $sheet['action']));
            }
        } catch (ClientException $e) {
            throw new UserException($e->getMessage(), 0, $e, [
                'response' => $e->getResponse()->getBody()->getContents(),
                'reasonPhrase' => $e->getResponse()->getReasonPhrase(),
            ]);
        }
    }

    private function preFlightChecks(array $sheet, array $sheetProperties, int $columnCountSrc, int $rowCountSrc): void
    {
        if (empty($sheetProperties)) {
            $fileLabel = $sheet['title'] ?? $sheet['fileTitle'] ?? ($sheet['fileId'] ?? '(unknown)');
            throw new UserException(sprintf(
                'Sheet "%s" (%s) not found in file "%s" (%s)',
                $sheet['sheetTitle'],
                $sheet['sheetId'],
                $fileLabel,
                $sheet['fileId'],
            ));
        }

        if ($columnCountSrc * $rowCountSrc > 10000000) {
            throw new UserException('CSV file exceeds the limit of 10000000 cells');
        }
    }


    private function updateAction(array $sheet, Table $inputTable): array
    {
        $this->logger->info('Updating values', ['sheet' => $sheet]);

        $responses = [];
        $paginator = new Paginator($inputTable);

        foreach ($paginator->pages() as $page) {
            $range = $this->getRange(
                $sheet['sheetTitle'],
                $this->inputTable->getColumnCount(),
                $page->getOffset(),
                $page->getLimit()
            );
            $response = $this->updateValues($sheet, $range, $page->getValues());
            $this->logger->info(
                sprintf('Updating data in sheet "%s" in file "%s"', $sheet['sheetTitle'], $sheet['fileId']),
                [
                    'sheet' => $sheet,
                    'offset' => $page->getOffset(),
                    'range' => $range,
                    'response' => $response,
                ]
            );
            $responses[] = $response;
        }

        return $responses;
    }

    private function appendAction(array $sheet, Table $inputTable): array
    {
        $this->logger->info('Appending values', ['sheet' => $sheet]);

        $responses = [];
        $paginator = new Paginator($inputTable);
        $sheetHasHeader = $this->hasHeader($sheet);

        foreach ($paginator->pages() as $page) {
            $values = $page->getValues();
            if ($page->isFirst() && $sheetHasHeader) {
                array_shift($values);
            }

            $response = $this->appendValues($sheet, $values);
            $this->logger->info(
                sprintf('Appending data to sheet "%s" in file "%s"', $sheet['sheetTitle'], $sheet['fileId']),
                [
                    'sheet' => $sheet,
                    'offset' => $page->getOffset(),
                    'response' => $response,
                ]
            );
            $responses[] = $response;
        }

        return $responses;
    }

    private function appendValues(array $sheet, array $values): array
    {
        return $this->client->appendSpreadsheetValues(
            $sheet['fileId'],
            urlencode($sheet['sheetTitle']),
            $values
        );
    }

    private function updateValues(array $sheet, string $range, array $values): array
    {
        return $this->client->updateSpreadsheetValues(
            $sheet['fileId'],
            $range,
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
    private function updateMetadata(array $sheet, array $gridProperties = []): array
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

    private function getSheetProperties(string $fileId, int $sheetId): array
    {
        $spreadsheet = $this->client->getSpreadsheet($fileId);

        return $this->findSheetPropertiesById($spreadsheet['sheets'], $sheetId);
    }

    private function findSheetPropertiesById(array $sheets, int $sheetId): array
    {
        $results = array_filter($sheets, function ($item) use ($sheetId) {
            return $sheetId === (int) $item['properties']['sheetId'];
        });

        if (empty($results)) {
            return [];
        }

        return array_shift($results);
    }

    public function getRange(string $sheetTitle, int $columnCount, int $offset = 1, int $limit = 1000): string
    {
        $lastColumn = $this->columnToLetter($columnCount);

        $start = 'A' . $offset;
        $end = $lastColumn . ($offset + $limit - 1);

        return urlencode($sheetTitle) . '!' . $start . ':' . $end;
    }

    public function columnToLetter(int $column): string
    {
        $alphas = range('A', 'Z');
        $letter = '';

        while ($column > 0) {
            $remainder = ($column - 1) % 26;
            $letter = $alphas[$remainder] . $letter;
            $column = ($column - $remainder - 1) / 26;
        }

        return $letter;
    }

    public function validateRowCount(int $rowCountSrc, int $rowCountUpdated, array $sheet): void
    {
        $isAppend = $sheet['action'] === ConfigDefinition::ACTION_APPEND;
        $commonCondition = $rowCountSrc === $rowCountUpdated;
        $appendCondition = $isAppend && (($rowCountSrc - 1 === $rowCountUpdated) || $rowCountSrc === $rowCountUpdated);

        if (!$commonCondition && !$appendCondition) {
            $fileLabel = $sheet['title'] ?? $sheet['fileTitle'] ?? ($sheet['fileId'] ?? '(unknown)');
            throw new UserException(sprintf(
                'Number of written rows (%d) in the sheet does not match with source table (%d). '
                . 'File "%s" (%s), sheet "%s" (%s). '
                . 'Try disabling all filters in the sheet and run the writer again.',
                $rowCountUpdated,
                $rowCountSrc,
                $fileLabel,
                $sheet['fileId'],
                $sheet['sheetTitle'],
                $sheet['sheetId'],
            ));
        }
    }

    public function hasHeader(array $sheet): bool
    {
        $sheetValues = $this->client->getSpreadsheetValues(
            $sheet['fileId'],
            $this->getRange($sheet['sheetTitle'], $this->inputTable->getColumnCount(), 1, 1)
        );

        return (!empty($sheetValues['values']));
    }
}
