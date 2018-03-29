<?php

declare(strict_types=1);

namespace Keboola\GoogleSheetsWriter\Logger;

use Gelf\MessageValidator;
use Gelf\Publisher;
use Gelf\Transport\TcpTransport;
use Monolog\Handler\GelfHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;

class HandlerFactory
{
    public static function getGelfHandlers() : array
    {
        $transport = new TcpTransport(getenv('KBC_LOGGER_ADDR'), getenv('KBC_LOGGER_PORT'));
        $gelfHandler = new GelfHandler(new Publisher($transport, new MessageValidator()));
        $gelfHandler->setFormatter(new GelfFormatter());
        return [$gelfHandler];
    }

    public static function getStderrHandlers() : array
    {
        $errHandler = new StreamHandler('php://stderr', Logger::NOTICE, false);
        $infoHandler = new StreamHandler('php://stdout', Logger::INFO, false);
        $infoHandler->setFormatter(new LineFormatter("%message%\n"));
        return [$errHandler, $infoHandler];
    }
}
