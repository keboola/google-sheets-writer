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
        $container['action'] = isset($config['action']) && is_string($config['action']) ? $config['action'] : 'run';
        $container['logger'] = static fn () => $logger;

        $container['parameters'] = $this->validateParameters(
            isset($config['parameters']) && is_array($config['parameters']) ? $config['parameters'] : []
        );

        // --- Authorization: Service Account OR OAuth (fallback) ---
        $saRaw   = $config['authorization']['#serviceAccountJson'] ?? $config['authorization']['serviceAccountJson'] ?? null;
        $hasSa   = is_array($saRaw) || is_string($saRaw);
        $hasOauth = isset($config['authorization']['oauth_api']['credentials']);

        if (!$hasSa && !$hasOauth) {
            throw new UserException(
                'Missing authorization: provide either authorization.#serviceAccountJson or authorization.oauth_api.credentials.#data'
            );
        }

        if ($hasSa) {
            // Service Account flow
            $sa = is_string($saRaw) ? json_decode($saRaw, true) : $saRaw;
            if (!is_array($sa)) {
                throw new UserException('Invalid Service Account JSON.');
            }

            $scopes = [
                'https://www.googleapis.com/auth/drive',
                'https://www.googleapis.com/auth/spreadsheets',
            ];

            $tokenFactory = new ServiceAccountTokenFactory();
            $accessToken = $tokenFactory->getAccessToken($sa, $scopes);

            // We still construct Keboola RestApi so GoogleSheetsClient\Client gets the class it expects.
            // Empty refresh_token is fine; the writer sets a no-op refresh callback anyway.
            $container['google_client'] = function ($c) use ($accessToken): RestApi {
                $api = new RestApi(
                    'service-account',           // appKey (unused for SA)
                    'service-account',           // appSecret (unused for SA)
                    $accessToken,                // bearer
                    '',                          // refresh token not used for SA
                    $c['logger']
                );
                $api->setBackoffsCount($c['action'] !== 'run' ? 2 : 7);
                return $api;
            };
        } else {
            // OAuth flow
            /** @var array<string,mixed> $credentials */
            $credentials = is_array($config['authorization']['oauth_api']['credentials'])
                ? $config['authorization']['oauth_api']['credentials']
                : [];

            $appKey = isset($credentials['appKey']) && is_string($credentials['appKey']) ? $credentials['appKey'] : '';
            $appSecret = isset($credentials['#appSecret']) && is_string($credentials['#appSecret']) ? $credentials['#appSecret'] : '';
            $dataRaw = $credentials['#data'] ?? null;

            if ($appKey === '' || $appSecret === '' || (!is_string($dataRaw) && !is_array($dataRaw))) {
                throw new UserException('Missing authorization data');
            }

            /** @var array<string,mixed> $tokenData */
            $tokenData = is_string($dataRaw) ? (json_decode($dataRaw, true) ?: []) : $dataRaw;
            $accessToken = isset($tokenData['access_token']) && is_string($tokenData['access_token'])
                ? $tokenData['access_token']
                : '';
            $refreshToken = isset($tokenData['refresh_token']) && is_string($tokenData['refresh_token'])
                ? $tokenData['refresh_token']
                : '';

            $container['google_client'] = function ($c) use ($appKey, $appSecret, $accessToken, $refreshToken): RestApi {
                $api = new RestApi(
                    $appKey,
                    $appSecret,
                    $accessToken,
                    $refreshToken,
                    $c['logger']
                );
                $api->setBackoffsCount($c['action'] !== 'run' ? 2 : 7);
                return $api;
            };
        }

        $container['google_sheets_client'] = static function ($c): Client {
            $client = new Client($c['google_client']);
            $client->setTeamDriveSupport(true);
            return $client;
        };

        $container['input'] = static fn ($c) => new TableFactory($c['parameters']['data_dir']);

        $container['writer'] = static fn ($c) => new Writer(
            $c['google_sheets_client'],
            $c['input'],
            $c['logger']
        );

        $this->container = $container;
    }

    public function run(): array
    {
        $actionMethod = (string) $this->container['action'] . 'Action';
        if (!method_exists($this, $actionMethod)) {
            throw new UserException(sprintf("Action '%s' does not exist.", (string) $this->container['action']));
        }

        try {
            /** @var array<string,mixed> */
            return $this->$actionMethod();
        } catch (RequestException $e) {
            $resp = $e->getResponse();

            if ($e->getCode() === 401) {
                throw new UserException('Expired or wrong credentials, please reauthorize.', $e->getCode(), $e);
            }
            if ($e->getCode() === 403) {
                if ($resp !== null && strtolower($resp->getReasonPhrase()) === 'forbidden') {
                    $this->container['logger']->warning("You don't have access to Google Drive resource.");
                    return [];
                }
                $reason = $resp ? $resp->getReasonPhrase() : 'Forbidden';
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

            $response = $resp !== null ? ['response' => $resp->getBody()->getContents()] : [];
            throw new ApplicationException($e->getMessage(), 500, $e, $response);
        }
    }

    /** @return array{status:string} */
    protected function runAction(): array
    {
        /** @var Writer $writer */
        $writer = $this->container['writer'];
        /** @var array<int,array<string,mixed>> $tables */
        $tables = $this->container['parameters']['tables'];
        $writer->process($tables);

        return ['status' => 'ok'];
    }

    /** @return array{status:string, spreadsheet: array<string,mixed>} */
    protected function getSpreadsheetAction(): array
    {
        /** @var Writer $writer */
        $writer = $this->container['writer'];
        /** @var array<int,array<string,mixed>> $tables */
        $tables = $this->container['parameters']['tables'];
        $res = $writer->getSpreadsheet((string) $tables[0]['fileId']);

        return ['status' => 'ok', 'spreadsheet' => $res];
    }

    /** @return array{status:string, spreadsheet: array<string,mixed>} */
    protected function createSpreadsheetAction(): array
    {
        /** @var Writer $writer */
        $writer = $this->container['writer'];
        /** @var array<int,array<string,mixed>> $tables */
        $tables = $this->container['parameters']['tables'];
        $res = $writer->createSpreadsheet($tables[0]);

        return ['status' => 'ok', 'spreadsheet' => $res];
    }

    /** @return array{status:string, sheet: array<string,mixed>} */
    protected function addSheetAction(): array
    {
        /** @var Writer $writer */
        $writer = $this->container['writer'];
        /** @var array<int,array<string,mixed>> $tables */
        $tables = $this->container['parameters']['tables'];
        $res = $writer->addSheet($tables[0]);

        return ['status' => 'ok', 'sheet' => $res];
    }

    /** @return array{status:string} */
    protected function deleteSheetAction(): array
    {
        /** @var Writer $writer */
        $writer = $this->container['writer'];
        /** @var array<int,array<string,mixed>> $tables */
        $tables = $this->container['parameters']['tables'];
        $writer->deleteSheet($tables[0]);

        return ['status' => 'ok'];
    }

    /** @param array<string,mixed> $parameters */
    private function validateParameters(array $parameters): array
    {
        try {
            $processor = new Processor();
            /** @var array<string,mixed> */
            return $processor->processConfiguration(
                new ConfigDefinition(),
                [$parameters]
            );
        } catch (InvalidConfigurationException $e) {
            throw new UserException($e->getMessage(), 400, $e);
        }
    }
}
