<?php

declare(strict_types=1);

namespace Keboola\GoogleSheetsWriter\Logger;

use Gelf\Publisher;
use Gelf\Transport\TcpTransport;
use Monolog\Handler\GelfHandler;

class Logger extends \Monolog\Logger
{
    public function __construct(string $name = '')
    {
        $transport = new TcpTransport(getenv('KBC_LOGGER_ADDR'), getenv('KBC_LOGGER_PORT'));
        $gelfHandler = new GelfHandler(new Publisher($transport));
        $gelfHandler->setFormatter(new Formatter());

        parent::__construct($name, [$gelfHandler]);
    }
}
