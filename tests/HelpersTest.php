<?php

namespace Erikwang2013\WebmanScout\Tests;

require_once __DIR__ . '/Support/Fixtures.php';

use Erikwang2013\WebmanScout\EngineManager;
use Erikwang2013\WebmanScout\ScoutConfig;
use Illuminate\Container\Container;
use Illuminate\Events\Dispatcher;
use Mockery;
use PHPUnit\Framework\TestCase;

use function Erikwang2013\WebmanScout\Tests\Support\scoutSource;

/**
 * Covers the helpers.php polyfills not exercised by SmokeTest:
 * scout_config()/config()/app()/event().
 */
class HelpersTest extends TestCase
{
    protected function tearDown(): void
    {
        ScoutConfig::setSource(null);
        ScoutConfig::resetResolvedBase();
        Container::getInstance()->flush();
        Mockery::close();
    }

    public function testScoutConfigDelegatesToScoutConfig(): void
    {
        ScoutConfig::setSource(scoutSource([
            'driver' => 'meilisearch',
            'prefix' => 'posts_',
        ]));

        $this->assertSame('meilisearch', scout_config('driver'));
        $this->assertSame('posts_', scout_config('prefix'));
        $this->assertNull(scout_config('missing'));
        $this->assertSame('fb', scout_config('missing', 'fb'));
    }

    public function testConfigPolyfillDelegatesToScoutSource(): void
    {
        if (function_exists('config') && realpath((string) (new \ReflectionFunction('config'))->getFileName()) !== realpath(__DIR__ . '/../helpers.php')) {
            $this->markTestSkipped('Host framework provides its own config(); polyfill branch not exercised.');
        }

        ScoutConfig::setSource(scoutSource([
            'driver' => 'typesense',
            'host' => 'http://127.0.0.1:8108',
        ]));

        $this->assertSame('typesense', config('scout.driver'));
        $this->assertSame('http://127.0.0.1:8108', config('scout.host'));
        $this->assertNull(config('scout.missing'));
        $this->assertSame('d', config('scout.missing', 'd'));
    }

    public function testAppReturnsContainer(): void
    {
        $this->assertSame(Container::getInstance(), app());
    }

    public function testAppResolvesAbstract(): void
    {
        $this->assertInstanceOf(EngineManager::class, app(EngineManager::class));
    }

    public function testEventDispatchesViaContainerDispatcher(): void
    {
        $dispatcher = Mockery::mock(Dispatcher::class);
        $dispatcher->shouldReceive('dispatch')->once()->with('ModelSaved', ['id' => 1])->andReturn(['handled']);
        Container::getInstance()->instance('events', $dispatcher);

        $this->assertSame(['handled'], event('ModelSaved', ['id' => 1]));
    }
}
