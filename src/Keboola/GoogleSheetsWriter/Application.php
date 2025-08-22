<?php

declare(strict_types=1);

namespace Keboola\GoogleSheetsWriter;

use GuzzleHttp\Exception\RequestException;
use Keboola\Google\ClientBundle\Google\RestApi;
use Keboola\GoogleSheetsClient\Client;
use Keboola\GoogleSheetsWriter\Auth\ServiceAccountTokenFactory;
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
        $container['logger'] = static function () use ($logger) {
            return $logger;
        };

        // Validate params early
        $container['parameters'] = $this->validateParameters($config['parameters'] ?? []);

        // Decide auth mode: prefer Service Account if present, else OAuth
        $saRaw   = $config['authorization']['#serviceAccountJson'] ?? $config['authorization']['serviceAccountJson'] ?? null;
        $hasSa   = !empty($saRaw);
        $hasOauth = isset($config['authorization']['oauth_api']['credentials']['#data']);

        if (!$hasSa && !$hasOauth) {
            throw new UserException('Missing authorization: provide either authorization.#serviceAccountJson or authorization.oauth_api.credentials.#data');
        }

        if ($hasSa) {
            // --- Service Account path ---
            $container['google_client'] = function ($c) use ($saRaw) {
                $sa = is_string($saRaw) ? json_decode($saRaw, true) : $saRaw;
                if (!is_array($sa) || empty($sa['client_email']) || empty($sa['private_key'])) {
                    throw new UserException('Invalid Service Account JSON in authorization.#serviceAccountJson');
                }

                $scopes = [
                    'https://www.googleapis.com/auth/drive.file',
                    'https://www.googleapis.com/auth/spreadsheets',
                ];

                $tokenFactory = new ServiceAccountTokenFactory();
                $accessToken = $tokenFactory->getAccessToken($sa, $scopes);

                // Construct a real RestApi; pass empty appKey/appSecret/refresh_token.
                // RestApi will use the provided access token and won’t attempt refresh (no refresh token).
                $retries = ($c['action'] !== 'run') ? 2 : 7;
                $api = new RestApi(
                    '',                  // appKey (unused for SA)
                    '',                  // appSecret (unused for SA)
                    $accessToken,        // access token from SA
                    '',                  // refresh_token not used for SA
                    $c['logger']
                );
                $api->setBackoffsCount($retries);
                return $api;
            };
        } else {
            // --- OAuth path ---
            $credentials = $config['authorization']['oauth_api']['credentials'];
            if (!isset($credentials['#data'], $credentials['appKey'], $credentials['#appSecret'])) {
                throw new UserException('Missing authorization data');
            }
            $tokenData = json_decode($credentials['#data'], true);
            if (!$tokenData || empty($tokenData['refresh_token'])) {
                throw new UserException('OAuth credentials are invalid: missing refresh_token.');
            }

            $container['google_client'] = function ($c) use ($credentials, $tokenData) {
                $retries = ($c['action'] !== 'run') ? 2 : 7;
                $api = new RestApi(
                    (string) $credentials['appKey'],
                    (string) $credentials['#appSecret'],
                    (string) ($tokenData['access_token'] ?? ''),
                    (string) $tokenData['refresh_token'],
                    $c['logger']
                );
                $api->setBackoffsCount($retries);
                return $api;
            };
        }

        $container['google_sheets_client'] = static function ($c) {
            $client = new Client($c['google_client']); // expects Keboola\Google\ClientBundle\Google\RestApi
            $client->setTeamDriveSupport(true);
            return $client;
        };

        $container['input'] = static function ($c) {
            return new TableFactory($c['parameters']['data_dir']);
        };

        $container['writer'] = static function ($c) {
            return new Writer(
                $c['google_sheets_client'],
                $c['input'],
                $c['logger']
            );
        };

        $this->container = $container;
    }

    public function run(): array
    {
        $actionMethod = $this->container['action'] . 'Action';
        if (!method_exists($this, $actionMethod)) {
            throw new UserException(sprintf("Action '%s' does not exist.", $this->container['action']));
        }

        try {
            return $this->$actionMethod();
        } catch (RequestException $e) {
            if ($e->getCode() === 401) {
                throw new UserException('Expired or wrong credentials, please reauthorize.', $e->getCode(), $e);
            }
            if ($e->getCode() === 403) {
                if ($e->getResponse() && strtolower($e->getResponse()->getReasonPhrase()) === 'forbidden') {
                    $this->container['logger']->warning("You don't have access to Google Drive resource.");
                    return [];
                }
                $reason = $e->getResponse() ? $e->getResponse()->getReasonPhrase() : 'Forbidden';
                throw new UserException('Reason: ' . $reason, $e->getCode(), $e);
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

            $response = $e->getResponse() ? ['response' => $e->getResponse()->getBody()->getContents()] : [];
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
