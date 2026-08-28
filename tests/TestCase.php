<?php

namespace Erikwang2013\WebmanScout\Tests;

use Erikwang2013\WebmanScout\ScoutConfig;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use MockeryPHPUnitIntegration;

    protected function tearDown(): void
    {
        ScoutConfig::setSource(null);
        ScoutConfig::resetResolvedBase();

        parent::tearDown();
    }
}
