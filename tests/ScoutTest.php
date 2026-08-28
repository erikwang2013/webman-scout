<?php

namespace Erikwang2013\WebmanScout\Tests;

use Erikwang2013\WebmanScout\Engines\NullEngine;
use Erikwang2013\WebmanScout\Scout;
use Erikwang2013\WebmanScout\ScoutConfig;
use PHPUnit\Framework\TestCase;

class ScoutTest extends TestCase
{
    protected function tearDown(): void
    {
        ScoutConfig::setSource(null);
        ScoutConfig::resetResolvedBase();
    }

    public function testVersionConstant(): void
    {
        $this->assertSame('10.23.0', Scout::VERSION);
    }

    public function testEngineReturnsEngineFromManager(): void
    {
        $engine = Scout::engine('null');

        $this->assertInstanceOf(NullEngine::class, $engine);
    }

    public function testEngineThrowsForUnknownDriver(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Scout::engine('no_such_driver');
    }
}
