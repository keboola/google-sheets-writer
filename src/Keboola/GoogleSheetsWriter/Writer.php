<?php

declare(strict_types=1);

namespace Keboola\GoogleSheetsWriter;

use Keboola\GoogleSheetsClient\Client;
use Keboola\GoogleSheetsWriter\Configuration\ConfigDefinition;
use Keboola\GoogleSheetsWriter\Input\TableFactory;
use Monolog\Logger;
use Psr\Http\Message\ResponseInterface;

class Writer
{
    private Client $driveApi;

    private TableFactory $input;

    private Logger $logger;

    public function __construct(Client $driveApi, TableFactory $input, Logger $logger)
    {
        $this->driveApi = $driveApi;
        $this->input = $input;
        $this->logger = $logger;

        $this->driveApi->getApi()->setBackoffCallback403($this->getBackoffCallback403());
        $this->driveApi->getApi()->setRefreshTokenCallback(function (): void {
        });
    }

    public function getBackoffCallback403(): callable
    {
        return function ($response) {
            /** @var ResponseInterface $response */
            $reason = $response->getReasonPhrase();

            if ($reason === 'insufficientPermissions'
                || $reason === 'dailyLimitExceeded'
                || $reason === 'usageLimits.userRateLimitExceededUnreg'
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
                if ($sheetCfg['action'] === ConfigDefinition::ACTION_CREATE) {
                    $sheetCfg = $this->resolveCreateAction($sheetCfg);
                }

                $this->logger->info(sprintf(
                    'Processing sheet "%s" in file "%s"',
                    $sheetCfg['sheetTitle'],
                    $sheetCfg['title'],
                ));

                $sheetWriter = new Sheet(
                    $this->driveApi,
                    $this->input->getTable($sheetCfg['tableId']),
                    $this->logger,
                );
                $sheetWriter->process($sheetCfg);
            }
        }
    }

    private function resolveCreateAction(array $sheetCfg): array
    {
        $spreadsheet = $this->driveApi->getSpreadsheet($sheetCfg['fileId']);

        foreach ($spreadsheet['sheets'] as $gdSheet) {
            if ($gdSheet['properties']['title'] === $sheetCfg['sheetTitle']) {
                $sheetCfg['sheetId'] = (int) $gdSheet['properties']['sheetId'];
                $sheetCfg['action'] = ConfigDefinition::ACTION_APPEND;
                $this->logger->info(sprintf(
                    'Sheet "%s" found in spreadsheet, appending data',
                    $sheetCfg['sheetTitle'],
                ));
                return $sheetCfg;
            }
        }

        $addSheetResponse = $this->driveApi->addSheet(
            $sheetCfg['fileId'],
            [
                'properties' => [
                    'title' => $sheetCfg['sheetTitle'],
                ],
            ],
        );
        $sheetCfg['sheetId'] = (int) $addSheetResponse['replies'][0]['addSheet']['properties']['sheetId'];
        $sheetCfg['action'] = ConfigDefinition::ACTION_UPDATE;
        $this->logger->info(sprintf(
            'Sheet "%s" not found in spreadsheet, creating new tab',
            $sheetCfg['sheetTitle'],
        ));

        $tabCount = count($spreadsheet['sheets']) + 1;
        if ($tabCount > 150) {
            $this->logger->warning(sprintf(
                'Spreadsheet has %d tabs. Google Sheets limit is 200.',
                $tabCount,
            ));
        }

        return $sheetCfg;
    }

    public function createFileMetadata(array $file): array
    {
        $params = [
            'mimeType' => Client::MIME_TYPE_SPREADSHEET,
        ];
        if (isset($file['folder']['id'])) {
            $params['parents'] = [$file['folder']['id']];
        }
        return $this->driveApi->createFileMetadata($file['title'], $params);
    }

    public function getSpreadsheet(string $fileId): array
    {
        return $this->driveApi->getSpreadsheet($fileId);
    }

    public function createSpreadsheet(array $file): array
    {
        $gdFile = $this->createFileMetadata($file);

        return $this->driveApi->getSpreadsheet($gdFile['id']);
    }

    public function addSheet(array $sheet): array
    {
        $this->logger->debug('Add Sheet action');
        $spreadsheet = $this->driveApi->getSpreadsheet($sheet['fileId']);
        $this->logger->debug('get spreadsheet', [
            'response' => [
                'spreadsheet' => $spreadsheet,
            ],
        ]);
        foreach ($spreadsheet['sheets'] as $gdSheet) {
            if ($gdSheet['properties']['title'] === $sheet['sheetTitle']) {
                return $gdSheet['properties'];
            }
        }

        $addSheetResponse = $this->driveApi->addSheet(
            $sheet['fileId'],
            [
                'properties' => [
                    'title' => $sheet['sheetTitle'],
                ],
            ],
        );
        $this->logger->debug('add sheet', [
            'response' => $addSheetResponse,
        ]);

        return $addSheetResponse['replies'][0]['addSheet']['properties'];
    }

    public function deleteSheet(array $sheet): array
    {
        return $this->driveApi->deleteSheet(
            (string) $sheet['fileId'],
            (string) $sheet['sheetId'],
        );
    }
}
