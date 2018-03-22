<?php

declare(strict_types=1);

namespace Keboola\GoogleSheetsWriter\Test;

use Keboola\Csv\CsvFile;
use Keboola\Google\ClientBundle\Google\RestApi;
use Keboola\GoogleSheetsClient\Client;
use PHPUnit\Framework\TestCase;

class BaseTest extends TestCase
{
    /** @var string */
    protected $dataPath = __DIR__ . '/../../../../tests/data';

    /** @var Client */
    protected $client;

    public function setUp(): void
    {
        $api = new RestApi(getenv('CLIENT_ID'), getenv('CLIENT_SECRET'));
        $api->setCredentials(getenv('ACCESS_TOKEN'), getenv('REFRESH_TOKEN'));
        $api->setBackoffsCount(2); // Speeds up the tests
        $this->client = new Client($api);
        $this->client->setTeamDriveSupport(true);
    }

    protected function prepareConfig() : array
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

    protected function csvToArray(string $pathname) : array
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
}
