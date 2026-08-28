<?php

namespace Erikwang2013\WebmanScout\Tests;

use Erikwang2013\WebmanScout\Manager;
use Illuminate\Container\Container;
use InvalidArgumentException;
use Mockery;
use PHPUnit\Framework\TestCase;

class DummyManagerDriver
{
    public $calls = 0;

    public function ping()
    {
        return ++$this->calls;
    }
}

class DummyManager extends Manager
{
    public function getDefaultDriver()
    {
        return 'default';
    }

    public function createDefaultDriver()
    {
        return new DummyManagerDriver();
    }

    public function createNamedDriver()
    {
        return new \ArrayObject(['name' => 'named']);
    }
}

class NullDefaultManager extends Manager
{
    public function getDefaultDriver()
    {
        return null;
    }
}

class ManagerTest extends TestCase
{
    protected $container;

    protected function setUp(): void
    {
        $this->container = Container::getInstance();
    }

    protected function tearDown(): void
    {
        Mockery::close();
    }

    public function testDriverUsesDefaultDriver(): void
    {
        $manager = new DummyManager($this->container);

        $this->assertInstanceOf(DummyManagerDriver::class, $manager->driver());
    }

    public function testDriverResolvesByName(): void
    {
        $manager = new DummyManager($this->container);

        $this->assertSame(['name' => 'named'], $manager->driver('named')->getArrayCopy());
    }

    public function testDriverInstancesAreCached(): void
    {
        $manager = new DummyManager($this->container);

        $this->assertSame($manager->driver(), $manager->driver());
        $this->assertSame($manager->driver('named'), $manager->driver('named'));
        $this->assertCount(2, $manager->getDrivers());
    }

    public function testNullDriverThrows(): void
    {
        $manager = new NullDefaultManager($this->container);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unable to resolve NULL driver for [Erikwang2013\WebmanScout\Tests\NullDefaultManager].');
        $manager->driver();
    }

    public function testUnknownDriverThrows(): void
    {
        $manager = new DummyManager($this->container);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Driver [unknown] not supported.');
        $manager->driver('unknown');
    }

    public function testDriverNamedDriverIsRejectedToAvoidConflictWithCreateDriver(): void
    {
        $manager = new DummyManager($this->container);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Driver [driver] not supported.');
        $manager->driver('driver');
    }

    public function testExtendRegistersCustomCreator(): void
    {
        $manager = new DummyManager($this->container);
        $sentinel = new \stdClass();

        $this->assertSame($manager, $manager->extend('custom', function ($container) use ($sentinel) {
            $this->assertInstanceOf(Container::class, $container);

            return $sentinel;
        }));

        $this->assertSame($sentinel, $manager->driver('custom'));
        $this->assertSame($sentinel, $manager->driver('custom')); // cached
    }

    public function testForgetDriversClearsCache(): void
    {
        $manager = new DummyManager($this->container);
        $first = $manager->driver();

        $manager->forgetDrivers();
        $this->assertSame([], $manager->getDrivers());
        $this->assertNotSame($first, $manager->driver());
    }

    public function testGetAndSetContainer(): void
    {
        $manager = new DummyManager($this->container);

        $this->assertSame($this->container, $manager->getContainer());

        $other = Container::setInstance(new Container());
        try {
            $manager->setContainer($other);
            $this->assertSame($other, $manager->getContainer());
        } finally {
            Container::setInstance($this->container);
        }
    }

    public function testCallForwardsToDefaultDriver(): void
    {
        $manager = new DummyManager($this->container);

        $this->assertSame(1, $manager->ping());
        $this->assertSame(2, $manager->ping()); // same cached driver instance
    }
}
