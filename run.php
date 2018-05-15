<?php

declare(strict_types=1);

use Keboola\GoogleSheetsWriter\Application;
use Keboola\GoogleSheetsWriter\Exception\ApplicationException;
use Keboola\GoogleSheetsWriter\Exception\UserException;
use Keboola\GoogleSheetsWriter\Logger\HandlerFactory;
use Monolog\Logger;

require_once(dirname(__FILE__) . "/bootstrap.php");

const APP_NAME = 'wr-google-drive';
// initialize logger
$logger = new Logger(APP_NAME, HandlerFactory::getGelfHandlers());
try {
    // verify that logger is functional
    $logger->debug("Starting up");
} catch (\Throwable $e) {
    // fallback to stderr logger
    $logger->setHandlers(HandlerFactory::getStderrHandlers());
    $logger->debug("Starting up");
    $logger->error($e->getMessage(), ['exception' => $e]);
}

// initialize application
try {
    $arguments = getopt("d::", ["data::"]);
    if (!isset($arguments["data"])) {
        throw new UserException('Data folder not set.');
    }
    $config = json_decode(file_get_contents($arguments["data"] . "/config.json"), true);
    $config['parameters']['data_dir'] = $arguments['data'];
    $config['app_name'] = APP_NAME;

    $app = new Application($config, $logger);
    $result = $app->run();

    if (isset($config['action']) && $config['action'] !== 'run') {
        echo json_encode($result);
        exit(0);
    }
} catch (UserException $e) {
    if (isset($config['action']) && $config['action'] != 'run') {
        echo json_encode([
            'status' => 'error',
            'error' => 'User Error',
            'message' => $e->getMessage(),
        ]);
    } else {
        $logger->log('error', $e->getMessage(), (array) $e->getData());
    }
    exit(1);
} catch (ApplicationException $e) {
    $logger->log('error', $e->getMessage(), (array) $e->getData());
    exit(2);
} catch (\Throwable $e) {
    $logger->log('error', $e->getMessage(), [
        'errFile' => $e->getFile(),
        'errLine' => $e->getLine(),
        'trace' => $e->getTrace(),
    ]);
    exit(2);
}

$logger->log('info', "Writer finished successfully.");
exit(0);
