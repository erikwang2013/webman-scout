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
        // 断言版本号格式而不是具体值：发布流程会自动 +1，具体值每次都要改
        $this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', Scout::VERSION);
        $this->assertSame(Scout::VERSION, (new \ReflectionClass(Scout::class))->getConstant('VERSION'));
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
