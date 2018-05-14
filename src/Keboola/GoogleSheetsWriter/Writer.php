<?php

declare(strict_types=1);

namespace Keboola\GoogleSheetsWriter;

use Keboola\GoogleSheetsWriter\Input\TableFactory;
use Keboola\GoogleSheetsClient\Client;
use Monolog\Logger;
use Psr\Http\Message\ResponseInterface;

class Writer
{
    /** @var Client */
    private $driveApi;

    /** @var TableFactory */
    private $input;

    /** @var Logger */
    private $logger;

    public function __construct(Client $driveApi, TableFactory $input, Logger $logger)
    {
        $this->driveApi = $driveApi;
        $this->input = $input;
        $this->logger = $logger;

        $this->driveApi->getApi()->setBackoffCallback403($this->getBackoffCallback403());
        $this->driveApi->getApi()->setRefreshTokenCallback(function (): void {
        });
    }

    public function getBackoffCallback403() : callable
    {
        return function ($response) {
            /** @var ResponseInterface $response */
            $reason = $response->getReasonPhrase();

            if ($reason == 'insufficientPermissions'
                || $reason == 'dailyLimitExceeded'
                || $reason == 'usageLimits.userRateLimitExceededUnreg'
            ) {
                return false;
            }

            return true;
        };
    }

    public function process(array $sheets): void
    {
        foreach ($sheets as $sheetCfg) {
            if ($sheetCfg['enabled']) {
                $this->logger->info(sprintf(
                    'Processing sheet "%s" in file "%s"',
                    $sheetCfg['sheetTitle'],
                    $sheetCfg['title']
                ));

                $sheetWriter = new Sheet(
                    $this->driveApi,
                    $this->input->getTable($sheetCfg['tableId']),
                    $this->logger
                );
                $sheetWriter->process($sheetCfg);
            }
        }
    }

    public function createFileMetadata(array $file) : array
    {
        $params = [
            'mimeType' => Client::MIME_TYPE_SPREADSHEET,
        ];
        if (isset($file['folder']['id'])) {
            $params['parents'] = [$file['folder']['id']];
        }
        return $this->driveApi->createFileMetadata($file['title'], $params);
    }

    public function getSpreadsheet(string $fileId) : array
    {
        return (array) $this->driveApi->getSpreadsheet($fileId);
    }

    public function createSpreadsheet(array $file) : array
    {
        $gdFile = $this->createFileMetadata($file);

        return (array) $this->driveApi->getSpreadsheet($gdFile['id']);
    }

    public function addSheet(array $sheet) : array
    {
        $this->logger->debug('Add Sheet action');
        $spreadsheet = $this->driveApi->getSpreadsheet($sheet['fileId']);
        $this->logger->debug('get spreadsheet', [
            'response' => [
                'spreadsheet' => $spreadsheet,
            ],
        ]);
        foreach ($spreadsheet['sheets'] as $gdSheet) {
            if ($gdSheet['properties']['title'] == $sheet['sheetTitle']) {
                return $gdSheet['properties'];
            }
        }

        $addSheetResponse = $this->driveApi->addSheet(
            $sheet['fileId'],
            [
                'properties' => [
                    'title' => $sheet['sheetTitle'],
                ],
            ]
        );
        $this->logger->info('add sheet', [
            'response' => $addSheetResponse,
        ]);

        return $addSheetResponse['replies'][0]['addSheet']['properties'];
    }

    public function deleteSheet(array $sheet) : array
    {
        return $this->driveApi->deleteSheet(
            $sheet['fileId'],
            $sheet['sheetId']
        );
    }
}
