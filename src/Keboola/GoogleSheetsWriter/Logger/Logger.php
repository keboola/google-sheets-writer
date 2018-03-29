<?php

declare(strict_types=1);

namespace Keboola\GoogleSheetsWriter\Logger;

use Gelf\Publisher;
use Gelf\Transport\TcpTransport;
use Monolog\Handler\GelfHandler;
use Monolog\Handler\StreamHandler;

class Logger extends \Monolog\Logger
{
    public function __construct(string $name = '')
    {
        parent::__construct($name, $this->initHandlers());
    }

    private function initHandlers() : array
    {
        // use GELF logger if Gelf server is available
        if (getenv('KBC_LOGGER_ADDR') !== false) {
            $transport = new TcpTransport(getenv('KBC_LOGGER_ADDR'), getenv('KBC_LOGGER_PORT'));
            $gelfHandler = new GelfHandler(new Publisher($transport));
            $gelfHandler->setFormatter(new GelfFormatter());
            return [$gelfHandler];
        }

        // fallback to STDOUT handler otherwise
        $errHandler = new StreamHandler('php://stderr', Logger::NOTICE, false);
        $infoHandler = new StreamHandler('php://stdout', Logger::INFO, false);
        $infoHandler->setFormatter(new LineFormatter("%message%\n"));

        return [$errHandler, $infoHandler];
    }
}
