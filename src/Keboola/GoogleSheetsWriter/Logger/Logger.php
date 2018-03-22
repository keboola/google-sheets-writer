<?php

declare(strict_types=1);

namespace Keboola\GoogleSheetsWriter\Logger;

use Monolog\Handler\StreamHandler;
use Monolog\Handler\SyslogUdpHandler;

class Logger extends \Monolog\Logger
{
    public function __construct(string $name = '')
    {
        $debugHandler = new SyslogUdpHandler("logs6.papertrailapp.com", 40897);
        $debugHandler->setFormatter(new LineFormatter());

        $errHandler = new StreamHandler('php://stderr', Logger::NOTICE, false);

        $infoHandler = new StreamHandler('php://stdout', Logger::INFO, false);
        $infoHandler->setFormatter(new LineFormatter("%message%\n"));

        parent::__construct($name, [$debugHandler, $errHandler, $infoHandler]);
    }
}