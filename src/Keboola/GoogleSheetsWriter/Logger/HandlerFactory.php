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
    public static function getGelfHandlers(): array
    {
        $transport = new TcpTransport(getenv('KBC_LOGGER_ADDR'), getenv('KBC_LOGGER_PORT'));
        $gelfHandler = new GelfHandler(new Publisher($transport, new MessageValidator()));
        $gelfHandler->setFormatter(new GelfFormatter());
        return [$gelfHandler];
    }

    public static function getStderrHandlers(): array
    {
        return [
            self::getCriticalHandler(),
            self::getErrorHandler(),
            self::getInfoHandler(),
        ];
    }

    public static function getErrorHandler(): StreamHandler
    {
        $errorHandler = new StreamHandler('php://stderr');
        $errorHandler->setBubble(false);
        $errorHandler->setLevel(Logger::WARNING);
        $errorHandler->setFormatter(new LineFormatter("%message%\n"));
        return $errorHandler;
    }
    public static function getInfoHandler(): StreamHandler
    {
        $logHandler = new StreamHandler('php://stdout');
        $logHandler->setBubble(false);
        $logHandler->setLevel(Logger::INFO);
        $logHandler->setFormatter(new LineFormatter("%message%\n"));
        return $logHandler;
    }
    public static function getCriticalHandler(): StreamHandler
    {
        $handler = new StreamHandler('php://stderr');
        $handler->setBubble(false);
        $handler->setLevel(Logger::CRITICAL);
        $handler->setFormatter(new LineFormatter("[%datetime%] %level_name%: %message% %context% %extra%\n"));
        return $handler;
    }
}
