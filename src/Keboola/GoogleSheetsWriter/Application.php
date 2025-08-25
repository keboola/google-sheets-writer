<?php

declare(strict_types=1);

/* phpcs:disable */

namespace Keboola\GoogleSheetsWriter;

use GuzzleHttp\Exception\RequestException;
use Keboola\Google\ClientBundle\Google\RestApi;
use Keboola\GoogleSheetsClient\Client;
use Keboola\GoogleSheetsWriter\Auth\ServiceAccountTokenFactory;
use Keboola\GoogleSheetsWriter\Configuration\ConfigDefinition;
use Keboola\GoogleSheetsWriter\Exception\ApplicationException;
use Keboola\GoogleSheetsWriter\Exception\UserException;
use Keboola\GoogleSheetsWriter\Http\RestApiBearer;
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

        $container['action'] = isset($config['action']) ? (string) $config['action'] : 'run';
        $container['logger'] = static function () use ($logger) {
            return $logger;
        };

        // Validate parameters
        $container['parameters'] = $this->validateParameters($config['parameters'] ?? []);

        // ---- AUTH (Service Account preferred, OAuth fallback) ----
        $saJson    = $config['parameters']['#serviceAccountJson'] ?? null;
        $auth      = $config['authorization'] ?? [];
        $oauthCreds = $auth['oauth_api']['credentials'] ?? null;

        if (!$saJson && !$oauthCreds) {
            throw new UserException('Missing authorization data');
        }

        $container['google_client'] = function () use ($container, $saJson, $oauthCreds) {
            $retries = ($container['action'] !== 'run') ? 2 : 7;

            // Prefer Service Account when provided
            if (is_array($saJson) && !empty($saJson)) {
                // In this repo the factory expects the whole SA JSON in the constructor.
                $factory = new ServiceAccountTokenFactory($saJson);

                // Try common method names to obtain the access token.
                if (method_exists($factory, 'getAccessToken')) {
                    $accessToken = $factory->getAccessToken();
                } elseif (method_exists($factory, 'createAccessToken')) {
                    $accessToken = $factory->createAccessToken();
                } elseif (method_exists($factory, 'fromServiceAccountJson')) {
                    $accessToken = $factory->fromServiceAccountJson($saJson);
                } else {
                    throw new UserException(
                        'ServiceAccountTokenFactory does not expose a supported method to obtain an access token.'
                    );
                }

                $api = new RestApiBearer($accessToken, $container['logger']);
                $api->setBackoffsCount($retries);
                return $api;
            }

            // OAuth fallback (legacy path)
            if (!isset($oauthCreds['#data'], $oauthCreds['appKey'], $oauthCreds['#appSecret'])) {
                throw new UserException('Missing authorization data');
            }

            $tokenData = json_decode((string) $oauthCreds['#data'], true) ?: [];
            $appKey    = (string) $oauthCreds['appKey'];
            $appSecret = (string) $oauthCreds['#appSecret'];
            $access    = (string) ($tokenData['access_token'] ?? '');
            $refresh   = (string) ($tokenData['refresh_token'] ?? '');

            if ($appKey === '' || $appSecret === '' || $access === '' || $refresh === '') {
                throw new UserException('Missing authorization data');
            }

            $api = new RestApi($appKey, $appSecret, $access, $refresh, $container['logger']);
            $api->setBackoffsCount($retries);
            return $api;
        };

        $container['google_sheets_client'] = static function ($container) {
            $client = new Client($container['google_client']);
            $client->setTeamDriveSupport(true);
            return $client;
        };

        $container['input'] = static function ($container) {
            return new TableFactory((string) $container['parameters']['data_dir']);
        };

        $container['writer'] = static function ($container) {
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
            throw new UserException(sprintf("Action '%s' does not exist.", $this->container['action']));
        }

        try {
            /** @var array<string, mixed> */
            return $this->$actionMethod();
        } catch (RequestException $e) {
            $code = (int) $e->getCode();

            if ($code === 401) {
                throw new UserException('Expired or wrong credentials, please reauthorize.', $code, $e);
            }

            if ($code === 403) {
                $resp = $e->getResponse();
                if ($resp && strtolower($resp->getReasonPhrase()) === 'forbidden') {
                    $this->container['logger']->warning("You don't have access to Google Drive resource.");
                    return [];
                }
                $reason = $resp ? $resp->getReasonPhrase() : 'Forbidden';
                throw new UserException('Reason: ' . $reason, $code, $e);
            }

            if ($code === 404) {
                throw new UserException('File or folder not found. ' . $e->getMessage(), $code, $e);
            }

            if ($code === 400) {
                throw new UserException($e->getMessage(), $code, $e);
            }

            if ($code >= 500 && $code < 600) {
                throw new UserException('Google API error: ' . $e->getMessage(), $code, $e);
            }

            $resp = $e->getResponse();
            $data = $resp ? ['response' => $resp->getBody()->getContents()] : [];
            throw new ApplicationException($e->getMessage(), 500, $e, $data);
        }
    }

    // ---- Actions ----

    protected function runAction(): array
    {
        /** @var Writer $writer */
        $writer = $this->container['writer'];
        $writer->process($this->container['parameters']['tables']);

        return ['status' => 'ok'];
    }

    protected function getSpreadsheetAction(): array
    {
        /** @var Writer $writer */
        $writer = $this->container['writer'];
        $res = $writer->getSpreadsheet((string) $this->container['parameters']['tables'][0]['fileId']);

        return [
            'status' => 'ok',
            'spreadsheet' => $res,
        ];
    }

    protected function createSpreadsheetAction(): array
    {
        /** @var Writer $writer */
        $writer = $this->container['writer'];
        /** @var array<string, mixed> $file */
        $file = $this->container['parameters']['tables'][0];
        $res = $writer->createSpreadsheet($file);

        return [
            'status' => 'ok',
            'spreadsheet' => $res,
        ];
    }

    protected function addSheetAction(): array
    {
        /** @var Writer $writer */
        $writer = $this->container['writer'];
        /** @var array<string, mixed> $sheet */
        $sheet = $this->container['parameters']['tables'][0];
        $res = $writer->addSheet($sheet);

        return [
            'status' => 'ok',
            'sheet' => $res,
        ];
    }

    protected function deleteSheetAction(): array
    {
        /** @var Writer $writer */
        $writer = $this->container['writer'];
        /** @var array<string, mixed> $sheet */
        $sheet = $this->container['parameters']['tables'][0];
        $writer->deleteSheet($sheet);

        return ['status' => 'ok'];
    }

    /** @return array<string, mixed> */
    private function validateParameters(array $parameters): array
    {
        try {
            $processor = new Processor();

            /** @var array<string, mixed> */
            return $processor->processConfiguration(
                new ConfigDefinition(),
                [$parameters]
            );
        } catch (InvalidConfigurationException $e) {
            throw new UserException($e->getMessage(), 400, $e);
        }
    }
}
