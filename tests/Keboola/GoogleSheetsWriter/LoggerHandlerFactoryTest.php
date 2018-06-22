<?php

declare(strict_types=1);

namespace Keboola\GoogleSheetsWriter;

use Keboola\GoogleSheetsWriter\Logger\HandlerFactory;
use PHPUnit\Framework\TestCase;

class LoggerHandlerFactoryTest extends TestCase
{
    public function testGelfHandlers() : void
    {
        $handlers = HandlerFactory::getGelfHandlers();
        $this->assertContainsOnlyInstancesOf('Monolog\Handler\GelfHandler', $handlers);
        $this->assertCount(1, $handlers);
    }

    public function testStdoutHandlers() : void
    {
        $handlers = HandlerFactory::getStderrHandlers();
        $this->assertContainsOnlyInstancesOf('Monolog\Handler\StreamHandler', $handlers);
        $this->assertCount(3, $handlers);
    }
}
