<?php

/**
 * Created by PhpStorm.
 * User: miroslavcillik
 * Date: 10/08/16
 * Time: 15:45
 */

namespace Keboola\GoogleSheetsWriter;

use GuzzleHttp\Exception\RequestException;
use Keboola\Google\ClientBundle\Google\RestApi;
use Keboola\GoogleSheetsWriter\Configuration\ConfigDefinition;
use Keboola\GoogleSheetsWriter\Exception\ApplicationException;
use Keboola\GoogleSheetsWriter\Exception\UserException;
use Keboola\GoogleSheetsWriter\Input\TableFactory;
use Keboola\GoogleSheetsClient\Client;
use Keboola\GoogleSheetsWriter\Logger\KbcInfoProcessor;
use Keboola\GoogleSheetsWriter\Logger\Logger;
use Pimple\Container;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;

class Application
{
    private $container;

    public function __construct($config)
    {
        $container = new Container();
        $container['action'] = isset($config['action'])?$config['action']:'run';
        $container['logger'] = function () use ($config) {
            return (new Logger($config['app_name']))->pushProcessor(new KbcInfoProcessor());
        };
        $container['parameters'] = $this->validateParameters($config['parameters']);
        if (empty($config['authorization'])) {
            throw new UserException('Missing authorization data');
        }
        $tokenData = json_decode($config['authorization']['oauth_api']['credentials']['#data'], true);
        $container['google_client'] = function ($container) use ($config, $tokenData) {
            return new RestApi(
                $config['authorization']['oauth_api']['credentials']['appKey'],
                $config['authorization']['oauth_api']['credentials']['#appSecret'],
                $tokenData['access_token'],
                $tokenData['refresh_token'],
                $container['logger']
            );
        };
        $container['google_sheets_client'] = function ($c) {
            $client = new Client($c['google_client']);
            $client->setTeamDriveSupport(true);
            return $client;
        };
        $container['input'] = function ($c) {
            return new TableFactory($c['parameters']['data_dir']);
        };
        $container['writer'] = function ($c) {
            return new Writer(
                $c['google_sheets_client'],
                $c['input'],
                $c['logger']
            );
        };

        $this->container = $container;
    }

    public function run()
    {
        $actionMethod = $this->container['action'] . 'Action';
        if (!method_exists($this, $actionMethod)) {
            throw new UserException(sprintf("Action '%s' does not exist.", $this['action']));
        }

        try {
            return $this->$actionMethod();
        } catch (RequestException $e) {
            if ($e->getCode() == 401) {
                throw new UserException("Expired or wrong credentials, please reauthorize.", $e->getCode(), $e);
            }
            if ($e->getCode() == 403) {
                if (strtolower($e->getResponse()->getReasonPhrase()) == 'forbidden') {
                    $this->container['logger']->warning("You don't have access to Google Drive resource.");
                    return [];
                }
                throw new UserException("Reason: " . $e->getResponse()->getReasonPhrase(), $e->getCode(), $e);
            }
            if ($e->getCode() == 400) {
                throw new UserException($e->getMessage());
            }
            if ($e->getCode() >= 500 && $e->getCode() < 600) {
                throw new UserException("Google API error: " . $e->getMessage(), $e->getCode(), $e);
            }
            throw new ApplicationException($e->getMessage(), 500, $e, [
                'response' => $e->getResponse()->getBody()->getContents()
            ]);
        }
    }

    protected function runAction()
    {
        /** @var Writer $writer */
        $writer = $this->container['writer'];
        $writer->process($this->container['parameters']['tables']);

        return [
            'status' => 'ok'
        ];
    }

    protected function getSpreadsheetAction()
    {
        /** @var Writer $writer */
        $writer = $this->container['writer'];
        $res = $writer->getSpreadsheet($this->container['parameters']['tables'][0]['fileId']);

        return [
            'status' => 'ok',
            'spreadsheet' => $res
        ];
    }

    protected function createSpreadsheetAction()
    {
        /** @var Writer $writer */
        $writer = $this->container['writer'];
        $res = $writer->createSpreadsheet($this->container['parameters']['tables'][0]);

        return [
            'status' => 'ok',
            'spreadsheet' => $res
        ];
    }

    protected function addSheetAction()
    {
        /** @var Writer $writer */
        $writer = $this->container['writer'];
        $res = $writer->addSheet($this->container['parameters']['tables'][0]);

        return [
            'status' => 'ok',
            'sheet' => $res
        ];
    }

    protected function deleteSheetAction()
    {
        /** @var Writer $writer */
        $writer = $this->container['writer'];
        $writer->deleteSheet($this->container['parameters']['tables'][0]);

        return [
            'status' => 'ok'
        ];
    }

    private function validateParameters($parameters)
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
