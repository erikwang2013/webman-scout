<?php

namespace Erikwang2013\WebmanScout\Tests\Support;

use Erikwang2013\WebmanScout\Support\Log;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use Mockery;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class LogTest extends TestCase
{
    protected function setUp(): void
    {
        Facade::setFacadeApplication(Container::getInstance());
        Facade::clearResolvedInstance('log');
    }

    protected function tearDown(): void
    {
        Log::setLoggerResolver(null);
        Container::getInstance()->forgetInstance('log');
        Facade::clearResolvedInstance('log');
        Mockery::close();
    }

    public function testDelegatesToResolverLogger(): void
    {
        $logger = Mockery::mock(LoggerInterface::class);
        $logger->shouldReceive('info')->once()->with('msg', ['ctx' => 1]);
        Log::setLoggerResolver(static fn () => $logger);

        $this->assertNull(Log::info('msg', ['ctx' => 1]));
    }

    public function testDelegatesWarningLevel(): void
    {
        $logger = Mockery::mock(LoggerInterface::class);
        $logger->shouldReceive('warning')->once()->with('warn');
        Log::setLoggerResolver(static fn () => $logger);

        $this->assertNull(Log::warning('warn'));
    }

    public function testDelegatesErrorLevel(): void
    {
        $logger = Mockery::mock(LoggerInterface::class);
        $logger->shouldReceive('error')->once()->with('err');
        Log::setLoggerResolver(static fn () => $logger);

        $this->assertNull(Log::error('err'));
    }

    public function testResolverReturningNonLoggerFallsBackToFacade(): void
    {
        $log = Mockery::mock(\Illuminate\Log\LogManager::class);
        $log->shouldReceive('info')->once()->with('msg');
        Container::getInstance()->instance('log', $log);
        Log::setLoggerResolver(static fn () => new \stdClass());

        $this->assertNull(Log::info('msg'));
    }

    public function testNoResolverFallsBackToFacade(): void
    {
        $log = Mockery::mock(\Psr\Log\LoggerInterface::class);
        $log->shouldReceive('warning')->once()->with('warn');
        Container::getInstance()->instance('log', $log);

        $this->assertNull(Log::warning('warn'));
    }
}
