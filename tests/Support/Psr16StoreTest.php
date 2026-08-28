<?php

namespace Erikwang2013\WebmanScout\Tests\Support;

use Erikwang2013\WebmanScout\Support\Psr16Store;
use Mockery;
use PHPUnit\Framework\TestCase;
use Psr\SimpleCache\CacheInterface;

class Psr16StoreTest extends TestCase
{
    private $psr16;
    private $store;

    protected function setUp(): void
    {
        $this->psr16 = Mockery::mock(CacheInterface::class);
        $this->store = new Psr16Store($this->psr16);
    }

    protected function tearDown(): void
    {
        Mockery::close();
    }

    public function testGetDelegates(): void
    {
        $this->psr16->shouldReceive('get')->once()->with('k')->andReturn('v');

        $this->assertSame('v', $this->store->get('k'));
    }

    public function testManyGetsEachKey(): void
    {
        $this->psr16->shouldReceive('get')->with('a')->andReturn(1);
        $this->psr16->shouldReceive('get')->with('b')->andReturn(2);

        $this->assertSame(['a' => 1, 'b' => 2], $this->store->many(['a', 'b']));
    }

    public function testPutDelegates(): void
    {
        $this->psr16->shouldReceive('set')->once()->with('k', 'v', 60)->andReturn(true);

        $this->assertTrue($this->store->put('k', 'v', 60));
    }

    public function testPutManyPutsEachValue(): void
    {
        $this->psr16->shouldReceive('set')->with('a', 1, 60);
        $this->psr16->shouldReceive('set')->with('b', 2, 60);

        $this->assertTrue($this->store->putMany(['a' => 1, 'b' => 2], 60));
    }

    public function testIncrementAddsToCurrent(): void
    {
        $this->psr16->shouldReceive('get')->once()->with('k')->andReturn(5);
        $this->psr16->shouldReceive('set')->once()->with('k', 6)->andReturn(true);

        $this->assertSame(6, $this->store->increment('k'));
    }

    public function testIncrementReturnsFalseWhenSetFails(): void
    {
        $this->psr16->shouldReceive('get')->once()->with('k')->andReturn(5);
        $this->psr16->shouldReceive('set')->once()->with('k', 6)->andReturn(false);

        $this->assertFalse($this->store->increment('k'));
    }

    public function testDecrementSubtracts(): void
    {
        $this->psr16->shouldReceive('get')->once()->with('k')->andReturn(5);
        $this->psr16->shouldReceive('set')->once()->with('k', 4)->andReturn(true);

        $this->assertSame(4, $this->store->decrement('k'));
    }

    public function testForeverSetsWithNullTtl(): void
    {
        $this->psr16->shouldReceive('set')->once()->with('k', 'v', null)->andReturn(true);

        $this->assertTrue($this->store->forever('k', 'v'));
    }

    public function testForgetDeletes(): void
    {
        $this->psr16->shouldReceive('delete')->once()->with('k')->andReturn(true);

        $this->assertTrue($this->store->forget('k'));
    }

    public function testFlushClears(): void
    {
        $this->psr16->shouldReceive('clear')->once()->andReturn(true);

        $this->assertTrue($this->store->flush());
    }

    public function testGetPrefixIsEmpty(): void
    {
        $this->assertSame('', $this->store->getPrefix());
    }
}
