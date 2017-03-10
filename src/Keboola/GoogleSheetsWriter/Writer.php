<?php
/**
 * Extractor.php
 *
 * @author: Miroslav Čillík <miro@keboola.com>
 * @created: 29.7.13
 */

namespace Keboola\GoogleDriveWriter;

use Keboola\GoogleDriveWriter\Configuration\ConfigDefinition;
use Keboola\GoogleDriveWriter\Input\TableFactory;
use Keboola\GoogleDriveWriter\Writer\Sheet;
use Keboola\GoogleSheetsClient\Client;
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

        $this->driveApi->getApi()->setBackoffsCount(7);
        $this->driveApi->getApi()->setBackoffCallback403($this->getBackoffCallback403());
        $this->driveApi->getApi()->setRefreshTokenCallback(function () {
        });
    }

    public function getBackoffCallback403()
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

    public function process(array $sheets)
    {
        foreach ($sheets as $sheetCfg) {
            $sheetWriter = new Sheet($this->driveApi, $this->input->getTable($sheetCfg['tableId']));
            $sheetWriter->process($sheetCfg);
        }
    }

    public function createFileMetadata(array $file)
    {
        $params = [
            'parents' => $file['parents']
        ];
        if ($file['type'] == ConfigDefinition::SHEET) {
            $params['mimeType'] = Client::MIME_TYPE_SPREADSHEET;
        }

        return $this->driveApi->createFileMetadata($file['title'], $params);
    }

    public function createSpreadsheet(array $file)
    {
        $gdFile = $this->createFileMetadata($file);

        return $this->driveApi->getSpreadsheet($gdFile['id']);
    }

    public function addSheet($sheet)
    {
        return $this->driveApi->addSheet(
            $sheet['fileId'],
            [
                'properties' => [
                    'title' => $sheet['sheetTitle']
                ]
            ]
        );
    }

    public function deleteSheet($sheet)
    {
        return $this->driveApi->deleteSheet(
            $sheet['fileId'],
            $sheet['sheetId']
        );
    }
}
