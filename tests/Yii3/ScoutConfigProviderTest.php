<?php

namespace Erikwang2013\WebmanScout\Tests\Yii3;

use Erikwang2013\WebmanScout\ScoutConfig;
use Erikwang2013\WebmanScout\Support\Cache;
use Erikwang2013\WebmanScout\Support\Log;
use Erikwang2013\WebmanScout\Support\Psr16Store;
use Erikwang2013\WebmanScout\Yii3\ScoutConfigProvider;
use Illuminate\Container\Container;
use Mockery;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;
use Yiisoft\Config\ConfigInterface;

class ScoutConfigProviderTest extends TestCase
{
    private string $base;

    private ?Container $originalContainer;

    protected function setUp(): void
    {
        // params.php requires the webman plugin config, which calls base_path()
        // (src/config/plugin/erikwang2013/webman-scout/app.php:133); point the
        // Laravel container at a temp base path so that resolves.
        $this->base = sys_get_temp_dir() . '/webman-scout-test-' . uniqid();
        mkdir($this->base, 0777, true);
        putenv('WEBMAN_SCOUT_TEST_BASE=' . $this->base);

        $this->originalContainer = Container::getInstance();
        $app = Mockery::mock(Container::class)->makePartial();
        $app->shouldReceive('basePath')->andReturn($this->base);
        Container::setInstance($app);
    }

    protected function tearDown(): void
    {
        Container::setInstance($this->originalContainer);
        putenv('WEBMAN_SCOUT_TEST_BASE');
        if (is_dir($this->base)) {
            remove_dir($this->base);
        }
        ScoutConfig::setSource(null);
        ScoutConfig::resetResolvedBase();
        Cache::setPsr16Resolver(null);
        Log::setLoggerResolver(null);
        Mockery::close();
    }

    public function testGetParamsContainsScoutDefaults(): void
    {
        $provider = new ScoutConfigProvider();

        $params = $provider->getParams();
        $this->assertArrayHasKey('scout', $params);
        $this->assertIsArray($params['scout']);
        $this->assertArrayHasKey('driver', $params['scout']);
    }

    public function testBundledParamsDriveScoutConfig(): void
    {
        // At runtime the config plugin merges the provider's getParams() output,
        // so the container sees params wrapped under the 'scout' key.
        $defaults = require __DIR__ . '/../../src/Yii3/config/params.php';
        $config = Mockery::mock(ConfigInterface::class);
        $config->shouldReceive('get')->with('params')->andReturn(['scout' => $defaults]);

        $container = Mockery::mock(ContainerInterface::class);
        $container->shouldReceive('has')->with(ConfigInterface::class)->andReturn(true);
        $container->shouldReceive('get')->with(ConfigInterface::class)->andReturn($config);

        new ScoutConfigProvider($container);

        $this->assertSame($defaults['driver'], ScoutConfig::get('driver'));
        $this->assertSame($defaults['prefix'], ScoutConfig::get('prefix'));
    }

    public function testContainerParamsOverrideDefaults(): void
    {
        $config = Mockery::mock(ConfigInterface::class);
        // baseKey() probes multiple keys, so the params are re-read several times.
        $config->shouldReceive('get')->with('params')->andReturn([
            'scout' => ['driver' => 'typesense', 'prefix' => 'ts_'],
        ]);

        $container = Mockery::mock(ContainerInterface::class);
        $container->shouldReceive('has')->with(ConfigInterface::class)->andReturn(true);
        $container->shouldReceive('get')->with(ConfigInterface::class)->andReturn($config);

        new ScoutConfigProvider($container);

        $this->assertSame('typesense', ScoutConfig::get('driver'));
        $this->assertSame('ts_', ScoutConfig::get('prefix'));
    }

    public function testNonArrayContainerParamsFallBackToDefaults(): void
    {
        $config = Mockery::mock(ConfigInterface::class);
        $config->shouldReceive('get')->with('params')->andReturn('not-an-array');

        $container = Mockery::mock(ContainerInterface::class);
        $container->shouldReceive('has')->with(ConfigInterface::class)->andReturn(true);
        $container->shouldReceive('get')->with(ConfigInterface::class)->andReturn($config);

        new ScoutConfigProvider($container);

        // 非数组 params 回退到包内置默认值（包裹在 'scout' 键下）
        $this->assertSame('opensearch', ScoutConfig::get('driver'));
    }

    public function testThrowingConfigFallsBackToDefaults(): void
    {
        $config = Mockery::mock(ConfigInterface::class);
        $config->shouldReceive('get')->andThrow(new \RuntimeException('config error'));

        $container = Mockery::mock(ContainerInterface::class);
        $container->shouldReceive('has')->with(ConfigInterface::class)->andReturn(true);
        $container->shouldReceive('get')->with(ConfigInterface::class)->andReturn($config);

        new ScoutConfigProvider($container);

        // 读取失败的 params 同样回退到包内置默认值
        $this->assertSame('opensearch', ScoutConfig::get('driver'));
    }

    public function testWiresPsr16CacheResolver(): void
    {
        $psr16 = Mockery::mock(CacheInterface::class);
        $psr16->shouldReceive('get')->once()->with('k')->andReturn('v');

        $container = Mockery::mock(ContainerInterface::class);
        $container->shouldReceive('has')->with(CacheInterface::class)->andReturn(true);
        $container->shouldReceive('get')->with(CacheInterface::class)->andReturn($psr16);

        new ScoutConfigProvider($container);

        $this->assertInstanceOf(Psr16Store::class, Cache::store());
        $this->assertSame('v', Cache::get('k'));
    }

    public function testWiresPsr3LoggerResolver(): void
    {
        $logger = Mockery::mock(LoggerInterface::class);
        $logger->shouldReceive('info')->once()->with('hi');

        $container = Mockery::mock(ContainerInterface::class);
        $container->shouldReceive('has')->with(LoggerInterface::class)->andReturn(true);
        $container->shouldReceive('get')->with(LoggerInterface::class)->andReturn($logger);

        new ScoutConfigProvider($container);

        $this->assertNull(Log::info('hi'));
    }

    public function testConfigPluginMethodsReturnEmpty(): void
    {
        $provider = new ScoutConfigProvider();

        $this->assertSame([], $provider->getDefinitions());
        $this->assertSame([], $provider->getExtensions());
        $this->assertSame([], $provider->getConfigFiles());
        $this->assertSame([], $provider->getMetadata());
    }
}
