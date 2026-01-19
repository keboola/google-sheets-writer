<?php

declare(strict_types=1);

namespace Keboola\GoogleSheetsWriter\Test;

use Keboola\Csv\CsvFile;
use Keboola\Google\ClientBundle\Google\RestApi;
use Keboola\GoogleSheetsClient\Client;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

class BaseTest extends TestCase
{
    protected string $dataPath = __DIR__ . '/../../../../tests/data';

    protected string $tmpDataPath = '/tmp/data-test';

    protected Client $client;

    public function setUp(): void
    {
        if (getenv('SERVICE_ACCOUNT_JSON')) {
            $serviceAccountJson = json_decode(getenv('SERVICE_ACCOUNT_JSON'), true);
            $scopes = [
                'https://www.googleapis.com/auth/drive',
                'https://www.googleapis.com/auth/spreadsheets',
            ];
            $api = RestApi::createWithServiceAccount($serviceAccountJson, $scopes);
        } else {
            $api = RestApi::createWithOAuth(
                getenv('CLIENT_ID'),
                getenv('CLIENT_SECRET'),
                getenv('ACCESS_TOKEN'),
                getenv('REFRESH_TOKEN'),
            );
        }
        $api->setBackoffsCount(2); // Speeds up the tests
        $this->client = new Client($api);
        $this->client->setTeamDriveSupport(true);
    }

    protected function prepareConfig(): array
    {
        $config['parameters']['data_dir'] = $this->dataPath;
        $config['authorization']['oauth_api']['credentials'] = [
            'appKey' => getenv('CLIENT_ID'),
            '#appSecret' => getenv('CLIENT_SECRET'),
            '#data' => json_encode([
                'access_token' => getenv('ACCESS_TOKEN'),
                'refresh_token' => getenv('REFRESH_TOKEN'),
            ]),
        ];

        return $config;
    }

    protected function prepareConfigWithServiceAccount(): array
    {
        $config['parameters']['data_dir'] = $this->dataPath;

        if (getenv('SERVICE_ACCOUNT_JSON')) {
            $config['parameters']['#serviceAccount'] = getenv('SERVICE_ACCOUNT_JSON');
        } else {
            // Fall back to OAuth
            return $this->prepareConfig();
        }

        return $config;
    }

    protected function csvToArray(string $pathname): array
    {
        $values = [];
        $csvFile = new CsvFile($pathname);
        $csvFile->next();
        while ($csvFile->current()) {
            $values[] = $csvFile->current();
            $csvFile->next();
        }

        return $values;
    }

    protected function prepareDataFiles(): void
    {
        $fs = new Filesystem();
        $fs->remove($this->tmpDataPath);
        $fs->mkdir($this->tmpDataPath);
        $fs->mkdir($this->tmpDataPath . '/in/tables/');
        $fs->copy($this->dataPath . '/in/tables/titanic.csv', $this->tmpDataPath . '/in/tables/titanic.csv');
        $fs->copy($this->dataPath . '/in/tables/titanic_1.csv', $this->tmpDataPath . '/in/tables/titanic_1.csv');
        $fs->copy($this->dataPath . '/in/tables/titanic_2.csv', $this->tmpDataPath . '/in/tables/titanic_2.csv');
        $fs->copy(
            $this->dataPath . '/in/tables/titanic_2_append.csv',
            $this->tmpDataPath . '/in/tables/titanic_2_append.csv',
        );
        $fs->copy(
            $this->dataPath . '/in/tables/titanic_2_append_2.csv',
            $this->tmpDataPath . '/in/tables/titanic_2_append_2.csv',
        );
    }
}
