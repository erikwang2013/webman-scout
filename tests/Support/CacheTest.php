<?php

namespace Erikwang2013\WebmanScout\Tests\Support;

use Erikwang2013\WebmanScout\Support\Cache;
use Erikwang2013\WebmanScout\Support\Psr16Store;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use Mockery;
use PHPUnit\Framework\TestCase;
use Psr\SimpleCache\CacheInterface;

class CacheTest extends TestCase
{
    protected function setUp(): void
    {
        Facade::setFacadeApplication(Container::getInstance());
        Facade::clearResolvedInstance('cache');
    }

    protected function tearDown(): void
    {
        Cache::setPsr16Resolver(null);
        Container::getInstance()->forgetInstance('cache');
        Facade::clearResolvedInstance('cache');
        Mockery::close();
    }

    public function testStoreReturnsPsr16StoreFromResolver(): void
    {
        $psr16 = Mockery::mock(CacheInterface::class);
        Cache::setPsr16Resolver(static fn () => $psr16);

        $this->assertInstanceOf(Psr16Store::class, Cache::store());
    }

    public function testStaticCallDelegatesToResolverStore(): void
    {
        $psr16 = Mockery::mock(CacheInterface::class);
        $psr16->shouldReceive('get')->once()->with('posts')->andReturn('data');
        Cache::setPsr16Resolver(static fn () => $psr16);

        $this->assertSame('data', Cache::get('posts'));
    }

    public function testStaticCallDelegatesPutToResolverStore(): void
    {
        $psr16 = Mockery::mock(CacheInterface::class);
        $psr16->shouldReceive('set')->once()->with('k', 'v', 60)->andReturn(true);
        Cache::setPsr16Resolver(static fn () => $psr16);

        $this->assertTrue(Cache::put('k', 'v', 60));
    }

    public function testResolverReturningNonPsr16FallsBackToFacade(): void
    {
        $cache = Mockery::mock(\Illuminate\Cache\CacheManager::class);
        $cache->shouldReceive('store')->once()->andReturn('facade-store');
        Container::getInstance()->instance('cache', $cache);
        Cache::setPsr16Resolver(static fn () => new \stdClass());

        $this->assertSame('facade-store', Cache::store());
    }

    public function testStaticCallFallsBackToFacadeWithoutResolver(): void
    {
        $cache = Mockery::mock(\Illuminate\Contracts\Cache\Repository::class);
        $cache->shouldReceive('get')->once()->with('k')->andReturn('v');
        Container::getInstance()->instance('cache', $cache);

        $this->assertSame('v', Cache::get('k'));
    }

    public function testClearedResolverFallsBackToFacade(): void
    {
        $cache = Mockery::mock(\Illuminate\Cache\CacheManager::class);
        $cache->shouldReceive('store')->once()->andReturn('facade-store');
        Container::getInstance()->instance('cache', $cache);

        Cache::setPsr16Resolver(static fn () => Mockery::mock(CacheInterface::class));
        Cache::setPsr16Resolver(null);

        $this->assertSame('facade-store', Cache::store());
    }
}
