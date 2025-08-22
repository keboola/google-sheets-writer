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

    /** @param array<string,mixed> $config */
    public function __construct(array $config, Logger $logger)
    {
        $container = new Container();
        $container['action'] = $config['action'] ?? 'run';
        $container['logger'] = function () use ($logger) {
            return $logger;
        };

        $container['parameters'] = $this->validateParameters($config['parameters']);

        if (!isset($config['authorization'])) {
            throw new UserException('Missing authorization data');
        }

        /** @var array<string,mixed> $auth */
        $auth = $config['authorization'];

        $container['google_client'] = function ($container) use ($auth) {
            // Prefer service account if present
            if (isset($auth['#serviceAccountJson']) && is_array($auth['#serviceAccountJson'])) {
                /** @var array<string,mixed> $sa */
                $sa = $auth['#serviceAccountJson'];
                $tokenFactory = new ServiceAccountTokenFactory($sa);
                $accessToken = $tokenFactory->createAccessToken();
                return new RestApiBearer($accessToken, $container['logger']);
            }

            // Fallback to OAuth credentials
            if (!isset($auth['oauth_api']['credentials'])) {
                throw new UserException('Missing authorization data');
            }
            /** @var array<string,mixed> $credentials */
            $credentials = $auth['oauth_api']['credentials'];
            if (!isset($credentials['#data'], $credentials['appKey'], $credentials['#appSecret'])) {
                throw new UserException('Missing authorization data');
            }

            /** @var array{access_token?:string,refresh_token?:string} $tokenData */
            $tokenData = (array) json_decode((string) $credentials['#data'], true);
            $accessToken = (string) ($tokenData['access_token'] ?? '');
            $refreshToken = (string) ($tokenData['refresh_token'] ?? '');

            $retries = ($container['action'] !== 'run') ? 2 : 7;

            $api = new RestApi(
                (string) $credentials['appKey'],
                (string) $credentials['#appSecret'],
                $accessToken,
                $refreshToken,
                $container['logger']
            );
            $api->setBackoffsCount($retries);
            return $api;
        };

        $container['google_sheets_client'] = function ($container) {
            $client = new Client($container['google_client']);
            $client->setTeamDriveSupport(true);
            return $client;
        };

        $container['input'] = function ($container) {
            /** @var array{data_dir:string} $params */
            $params = $container['parameters'];
            return new TableFactory($params['data_dir']);
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

    /** @return array<string,mixed> */
    public function run(): array
    {
        $actionMethod = $this->container['action'] . 'Action';
        if (!method_exists($this, $actionMethod)) {
            throw new UserException(sprintf("Action '%s' does not exist.", (string) $this->container['action']));
        }

        try {
            /** @var array<string,mixed> $res */
            $res = $this->$actionMethod();
            return $res;
        } catch (RequestException $e) {
            $code = (int) $e->getCode();
            if ($code === 401) {
                throw new UserException('Expired or wrong credentials, please reauthorize.', $code, $e);
            }
            if ($code === 403) {
                $reason = $e->getResponse() ? $e->getResponse()->getReasonPhrase() : '';
                if (strtolower((string) $reason) === 'forbidden') {
                    $this->container['logger']->warning("You don't have access to Google Drive resource.");
                    return [];
                }
                throw new UserException('Reason: ' . $reason, $code, $e);
            }
            if ($code === 404) {
                throw new UserException('File or folder not found. ' . $e->getMessage(), $code, $e);
            }
            if ($code === 400) {
                throw new UserException($e->getMessage(), $code);
            }
            if ($code >= 500 && $code < 600) {
                throw new UserException('Google API error: ' . $e->getMessage(), $code, $e);
            }

            $response = $e->getResponse() !== null ? ['response' => $e->getResponse()->getBody()->getContents()] : [];
            throw new ApplicationException($e->getMessage(), 500, $e, $response);
        }
    }

    /** @return array<string,mixed> */
    protected function runAction(): array
    {
        /** @var Writer $writer */
        $writer = $this->container['writer'];
        /** @var array{tables: array<int, array<string,mixed>>} $params */
        $params = $this->container['parameters'];
        $writer->process($params['tables']);

        return ['status' => 'ok'];
    }

    /** @return array<string,mixed> */
    protected function getSpreadsheetAction(): array
    {
        /** @var Writer $writer */
        $writer = $this->container['writer'];
        /** @var array{tables: array<int, array{fileId:string}>} $params */
        $params = $this->container['parameters'];
        $res = $writer->getSpreadsheet((string) $params['tables'][0]['fileId']);

        return ['status' => 'ok', 'spreadsheet' => $res];
    }

    /** @return array<string,mixed> */
    protected function createSpreadsheetAction(): array
    {
        /** @var Writer $writer */
        $writer = $this->container['writer'];
        /** @var array{tables: array<int, array<string,mixed>>} $params */
        $params = $this->container['parameters'];
        $res = $writer->createSpreadsheet($params['tables'][0]);

        return ['status' => 'ok', 'spreadsheet' => $res];
    }

    /** @return array<string,mixed> */
    protected function addSheetAction(): array
    {
        /** @var Writer $writer */
        $writer = $this->container['writer'];
        /** @var array{tables: array<int, array<string,mixed>>} $params */
        $params = $this->container['parameters'];
        $res = $writer->addSheet($params['tables'][0]);

        return ['status' => 'ok', 'sheet' => $res];
    }

    /** @return array<string,mixed> */
    protected function deleteSheetAction(): array
    {
        /** @var Writer $writer */
        $writer = $this->container['writer'];
        /** @var array{tables: array<int, array<string,mixed>>} $params */
        $params = $this->container['parameters'];
        $writer->deleteSheet($params['tables'][0]);

        return ['status' => 'ok'];
    }

    /** @param array<string,mixed> $parameters
     *  @return array<string,mixed>
     */
    private function validateParameters(array $parameters): array
    {
        try {
            $processor = new Processor();
            /** @var array<string,mixed> $out */
            $out = $processor->processConfiguration(
                new ConfigDefinition(),
                [$parameters]
            );
            return $out;
        } catch (InvalidConfigurationException $e) {
            throw new UserException($e->getMessage(), 400, $e);
        }
    }
}
