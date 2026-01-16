<?php

declare(strict_types=1);

namespace Keboola\GoogleSheetsWriter;

use GuzzleHttp\Exception\RequestException;
use Keboola\Google\ClientBundle\Google\RestApi;
use Keboola\GoogleSheetsClient\Client;
use Keboola\GoogleSheetsWriter\Configuration\ConfigDefinition;
use Keboola\GoogleSheetsWriter\Exception\ApplicationException;
use Keboola\GoogleSheetsWriter\Exception\UserException;
use Keboola\GoogleSheetsWriter\Input\TableFactory;
use Monolog\Logger;
use Pimple\Container;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;

class Application
{
    private Container $container;

    public function __construct(array $config, Logger $logger)
    {
        $container = new Container();
        $container['action'] = $config['action'] ?? 'run';
        $container['logger'] = function () use ($logger) {
            return $logger;
        };

        $container['parameters'] = $this->validateParameters($config['parameters']);

        // Check for service account credentials first
        if (isset($config['authorization']['service_account']['#data'])) {
            $serviceAccountData = json_decode($config['authorization']['service_account']['#data'], true);
            if (!is_array($serviceAccountData)) {
                throw new UserException('Invalid service account credentials format');
            }
            $container['google_client'] = function ($container) use ($serviceAccountData) {
                $retries = 7;
                if ($container['action'] !== 'run') {
                    $retries = 2;
                }
                $scopes = [
                    'https://www.googleapis.com/auth/drive',
                    'https://www.googleapis.com/auth/spreadsheets',
                ];
                $api = RestApi::createWithServiceAccount($serviceAccountData, $scopes, $container['logger']);
                $api->setBackoffsCount($retries);
                return $api;
            };
        } elseif (isset($config['authorization']['oauth_api']['credentials'])) {
            // Fall back to OAuth credentials
            $credentials = $config['authorization']['oauth_api']['credentials'];
            if (!isset($credentials['#data']) || !isset($credentials['appKey']) || !isset($credentials['#appSecret'])) {
                throw new UserException('Missing authorization data');
            }

            $tokenData = json_decode($credentials['#data'], true);
            if (!is_array($tokenData) || !isset($tokenData['access_token']) || !isset($tokenData['refresh_token'])) {
                throw new UserException('Invalid OAuth token data format');
            }
            $container['google_client'] = function ($container) use ($credentials, $tokenData) {
                $retries = 7;
                if ($container['action'] !== 'run') {
                    $retries = 2;
                }
                $api = RestApi::createWithOAuth(
                    $credentials['appKey'],
                    $credentials['#appSecret'],
                    $tokenData['access_token'],
                    $tokenData['refresh_token'],
                    $container['logger'],
                );
                $api->setBackoffsCount($retries);
                return $api;
            };
        } else {
            throw new UserException('Missing authorization data');
        }
        $container['google_sheets_client'] = function ($container) {
            $client = new Client($container['google_client']);
            $client->setTeamDriveSupport(true);
            return $client;
        };
        $container['input'] = function ($container) {
            return new TableFactory($container['parameters']['data_dir']);
        };
        $container['writer'] = function ($container) {
            return new Writer(
                $container['google_sheets_client'],
                $container['input'],
                $container['logger']
            );
        };

        $this->container = $container;
    }

    public function run(): array
    {
        $actionMethod = $this->container['action'] . 'Action';
        if (!method_exists($this, $actionMethod)) {
            throw new UserException(sprintf("Action '%s' does not exist.", $this['action']));
        }

        try {
            return $this->$actionMethod();
        } catch (RequestException $e) {
            if ($e->getCode() === 401) {
                throw new UserException('Expired or wrong credentials, please reauthorize.', $e->getCode(), $e);
            }
            if ($e->getCode() === 403) {
                if (strtolower($e->getResponse()->getReasonPhrase()) === 'forbidden') {
                    $this->container['logger']->warning("You don't have access to Google Drive resource.");
                    return [];
                }
                throw new UserException('Reason: ' . $e->getResponse()->getReasonPhrase(), $e->getCode(), $e);
            }
            if ($e->getCode() === 404) {
                throw new UserException('File or folder not found. ' . $e->getMessage(), $e->getCode(), $e);
            }
            if ($e->getCode() === 400) {
                throw new UserException($e->getMessage());
            }
            if ($e->getCode() >= 500 && $e->getCode() < 600) {
                throw new UserException('Google API error: ' . $e->getMessage(), $e->getCode(), $e);
            }

            $response = $e->getResponse() !== null ? ['response' => $e->getResponse()->getBody()->getContents()] : [];
            throw new ApplicationException($e->getMessage(), 500, $e, $response);
        }
    }

    protected function runAction(): array
    {
        /** @var Writer $writer */
        $writer = $this->container['writer'];
        $writer->process($this->container['parameters']['tables']);

        return [
            'status' => 'ok',
        ];
    }

    protected function getSpreadsheetAction(): array
    {
        /** @var Writer $writer */
        $writer = $this->container['writer'];
        $res = $writer->getSpreadsheet($this->container['parameters']['tables'][0]['fileId']);

        return [
            'status' => 'ok',
            'spreadsheet' => $res,
        ];
    }

    protected function createSpreadsheetAction(): array
    {
        /** @var Writer $writer */
        $writer = $this->container['writer'];
        $res = $writer->createSpreadsheet($this->container['parameters']['tables'][0]);

        return [
            'status' => 'ok',
            'spreadsheet' => $res,
        ];
    }

    protected function addSheetAction(): array
    {
        /** @var Writer $writer */
        $writer = $this->container['writer'];
        $res = $writer->addSheet($this->container['parameters']['tables'][0]);

        return [
            'status' => 'ok',
            'sheet' => $res,
        ];
    }

    protected function deleteSheetAction(): array
    {
        /** @var Writer $writer */
        $writer = $this->container['writer'];
        $writer->deleteSheet($this->container['parameters']['tables'][0]);

        return [
            'status' => 'ok',
        ];
    }

    private function validateParameters(array $parameters): array
    {
        try {
            $processor = new Processor();
            return $processor->processConfiguration(
                new ConfigDefinition(),
                [$parameters]
            );
        } catch (InvalidConfigurationException $e) {
            throw new UserException($e->getMessage(), 400, $e);
        }
    }
}
