<?php

namespace Erikwang2013\WebmanScout\Tests\Support;

require_once __DIR__ . '/WebmanStubs.php';

use Erikwang2013\WebmanScout\Support\YiiCacheStore;
use Mockery;
use PHPUnit\Framework\TestCase;
use yii\caching\Cache;

class YiiCacheStoreTest extends TestCase
{
    private $yiiCache;
    private $store;

    protected function setUp(): void
    {
        $this->yiiCache = Mockery::mock(Cache::class);
        $this->store = new YiiCacheStore($this->yiiCache);
    }

    protected function tearDown(): void
    {
        Mockery::close();
    }

    public function testGetConvertsFalseToNull(): void
    {
        $this->yiiCache->shouldReceive('get')->once()->with('k')->andReturn(false);

        $this->assertNull($this->store->get('k'));
    }

    public function testGetReturnsValue(): void
    {
        $this->yiiCache->shouldReceive('get')->once()->with('k')->andReturn('v');

        $this->assertSame('v', $this->store->get('k'));
    }

    public function testManyGetsEachKey(): void
    {
        $this->yiiCache->shouldReceive('get')->with('a')->andReturn('x');
        $this->yiiCache->shouldReceive('get')->with('b')->andReturn(false);

        $this->assertSame(['a' => 'x', 'b' => null], $this->store->many(['a', 'b']));
    }

    public function testPutWithTtl(): void
    {
        $this->yiiCache->shouldReceive('set')->once()->with('k', 'v', 60)->andReturn(true);

        $this->assertTrue($this->store->put('k', 'v', 60));
    }

    public function testPutWithNullTtlUsesZeroForForever(): void
    {
        $this->yiiCache->shouldReceive('set')->once()->with('k', 'v', 0)->andReturn(true);

        $this->assertTrue($this->store->put('k', 'v', null));
    }

    public function testPutNegativeTtlClampedToZero(): void
    {
        $this->yiiCache->shouldReceive('set')->once()->with('k', 'v', 0)->andReturn(true);

        $this->assertTrue($this->store->put('k', 'v', -10));
    }

    public function testPutManyPutsEachValue(): void
    {
        $this->yiiCache->shouldReceive('set')->with('a', 1, 60)->andReturn(true);
        $this->yiiCache->shouldReceive('set')->with('b', 2, 60)->andReturn(true);

        $this->assertTrue($this->store->putMany(['a' => 1, 'b' => 2], 60));
    }

    public function testIncrementAddsToCurrent(): void
    {
        $this->yiiCache->shouldReceive('get')->once()->with('k')->andReturn(5);
        $this->yiiCache->shouldReceive('set')->once()->with('k', 6)->andReturn(true);

        $this->assertSame(6, $this->store->increment('k'));
    }

    public function testIncrementReturnsFalseWhenSetFails(): void
    {
        $this->yiiCache->shouldReceive('get')->once()->with('k')->andReturn(5);
        $this->yiiCache->shouldReceive('set')->once()->with('k', 6)->andReturn(false);

        $this->assertFalse($this->store->increment('k'));
    }

    public function testDecrementSubtracts(): void
    {
        $this->yiiCache->shouldReceive('get')->once()->with('k')->andReturn(5);
        $this->yiiCache->shouldReceive('set')->once()->with('k', 4)->andReturn(true);

        $this->assertSame(4, $this->store->decrement('k'));
    }

    public function testForeverSetsZeroDuration(): void
    {
        $this->yiiCache->shouldReceive('set')->once()->with('k', 'v', 0)->andReturn(true);

        $this->assertTrue($this->store->forever('k', 'v'));
    }

    public function testForgetDeletes(): void
    {
        $this->yiiCache->shouldReceive('delete')->once()->with('k')->andReturn(true);

        $this->assertTrue($this->store->forget('k'));
    }

    public function testFlushFlushes(): void
    {
        $this->yiiCache->shouldReceive('flush')->once()->andReturn(true);

        $this->assertTrue($this->store->flush());
    }

    public function testGetPrefixIsEmpty(): void
    {
        $this->assertSame('', $this->store->getPrefix());
    }
}
