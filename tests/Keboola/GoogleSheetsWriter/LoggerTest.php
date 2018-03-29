<?php

declare(strict_types=1);

namespace Keboola\GoogleSheetsWriter;

use Keboola\GoogleSheetsWriter\Logger\Logger;
use PHPUnit\Framework\TestCase;

class LoggerTest extends TestCase
{
    /** @var string */
    private $appName = 'wr-google-drive-test';

    public function testGelfHandler() : void
    {
        $logger = new Logger($this->appName);
        $handlers = $logger->getHandlers();

        $this->assertContainsOnlyInstancesOf('Monolog\Handler\GelfHandler', $handlers);
        $this->assertCount(1, $handlers);
    }

    public function testStdoutHandler() : void
    {
        // unset env variable
        putenv('KBC_LOGGER_ADDR');
        $logger = new Logger($this->appName);
        $handlers = $logger->getHandlers();

        $this->assertContainsOnlyInstancesOf('Monolog\Handler\StreamHandler', $handlers);
        $this->assertCount(2, $handlers);
    }
}
