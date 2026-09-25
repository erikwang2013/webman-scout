<?php

namespace Erikwang2013\WebmanScout\Tests;

require_once __DIR__ . '/Support/WebmanStubs.php';

use Erikwang2013\WebmanScout\EngineManager;
use Erikwang2013\WebmanScout\Scout;
use Erikwang2013\WebmanScout\ScoutConfig;
use Erikwang2013\WebmanScout\Support\ArrayStore;
use Erikwang2013\WebmanScout\Support\Cache;
use Erikwang2013\WebmanScout\Support\Log;
use Illuminate\Container\Container;
use Mockery;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * 原生 PHP（无框架）路径：配置来源、缓存 / 日志兜底、路径解析。
 */
class NativePhpTest extends TestCase
{
    protected function setUp(): void
    {
        Container::getInstance()->flush();

        // 其它测试可能 swap 过 Log / Cache facade，这里清干净，模拟"宿主什么都没接"的原生 PHP
        \Illuminate\Support\Facades\Facade::clearResolvedInstances();
        \Illuminate\Support\Facades\Facade::setFacadeApplication(null);
    }

    protected function tearDown(): void
    {
        putenv('WEBMAN_SCOUT_TEST_BASE');
        ScoutConfig::setSource(null);
        ScoutConfig::resetResolvedBase();
        Log::setLoggerResolver(null);
        Cache::setPsr16Resolver(null);
        ArrayStore::instance()->flush();
        Container::getInstance()->flush();
        Mockery::close();
    }

    public function testConfigureArrayFeedsAllConfigEntryPoints(): void
    {
        Scout::configure([
            'driver' => 'opensearch',
            'prefix' => 'app_',
            'opensearch' => ['host' => 'https://os:9200'],
        ]);

        $this->assertSame('scout', ScoutConfig::baseKey());
        $this->assertSame('opensearch', scout_config('driver'));
        $this->assertSame('app_', scout_config('prefix'));
        $this->assertSame('https://os:9200', scout_config('opensearch.host'));
        $this->assertSame(['host' => 'https://os:9200'], scout_config('opensearch'));
        $this->assertNull(scout_config('nope'));
        $this->assertSame('fallback', scout_config('nope', 'fallback'));

        // 这套测试跑在装了 laravel/framework 的环境里时，"config" 是 Laravel 的实现，
        // 只有在包自带 polyfill 生效（原生 PHP / Yii）时才验证等价性。
        if (str_ends_with((string) (new \ReflectionFunction('config'))->getFileName(), 'webman-scout/helpers.php')) {
            $this->assertSame('opensearch', config('scout.driver'));
        }
    }

    public function testConfigurePinsRootEvenWithoutDriverOrPrefix(): void
    {
        Scout::configure(['opensearch' => ['host' => 'https://os:9200']]);

        $this->assertSame('scout', ScoutConfig::baseKey());
        $this->assertSame('https://os:9200', scout_config('opensearch.host'));
        $this->assertSame('null', (new EngineManager(Container::getInstance()))->getDefaultDriver());
    }

    public function testConfigureAcceptsAPathToAPhpFile(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'scout-config').'.php';
        file_put_contents($file, '<?php return ["driver" => "database", "prefix" => "t_"];');

        try {
            Scout::configure($file);

            $this->assertSame('database', scout_config('driver'));
            $this->assertSame('t_', scout_config('prefix'));
        } finally {
            unlink($file);
        }
    }

    public function testConfigureWithMissingFileLeavesDefaultsUntouched(): void
    {
        Scout::configure(sys_get_temp_dir().'/definitely-not-here-'.uniqid().'.php');

        $this->assertNull(scout_config('driver'));
        $this->assertSame('null', (new EngineManager(Container::getInstance()))->getDefaultDriver());
    }

    public function testConfigureAcceptsCustomRoot(): void
    {
        Scout::configure(['driver' => 'collection'], 'my-scout');

        $this->assertSame('my-scout', ScoutConfig::baseKey());
        $this->assertSame('collection', scout_config('driver'));
    }

    public function testCacheWithoutHostComponentUsesPerProcessArrayStore(): void
    {
        $store = Cache::store();

        $this->assertInstanceOf(ArrayStore::class, $store);

        $store->put('native-key', 'native-value', 60);
        $this->assertSame('native-value', $store->get('native-key'));
        $this->assertSame('native-value', Cache::store('file')->get('native-key'), 'same array store for every store name');

        Cache::put('native-via-callstatic', 42, 60);
        $this->assertSame(42, Cache::store()->get('native-via-callstatic'));
        $this->assertSame(43, Cache::store()->increment('native-via-callstatic'));

        $this->assertTrue(Cache::store()->forget('native-key'));
        $this->assertNull(Cache::store()->get('native-key'));
    }

    public function testArrayStoreExpiresEntries(): void
    {
        $store = ArrayStore::instance();
        $store->put('ttl', 'v', 1);

        $this->assertSame('v', $store->get('ttl'));
        $this->assertSame(['ttl' => 'v'], $store->many(['ttl']));
        $store->forever('forever', 'v');
        $this->assertSame('v', $store->get('forever'));
        $this->assertSame('webman-scout-array:', $store->getPrefix());
        $store->flush();
        $this->assertNull($store->get('forever'));
    }

    public function testLogWithoutLoggerWritesToErrorLog(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'scout-log');
        $previous = ini_get('error_log');
        ini_set('error_log', $file);

        try {
            Log::warning('redis queue missing', ['queue' => 'scout_make']);
        } finally {
            ini_set('error_log', $previous);
        }

        $contents = (string) file_get_contents($file);
        unlink($file);

        $this->assertStringContainsString(
            '[webman-scout] warning: redis queue missing {"queue":"scout_make"}',
            $contents
        );
    }

    public function testLogUsesRegisteredPsr3Logger(): void
    {
        $logger = Mockery::mock(LoggerInterface::class);
        $logger->shouldReceive('error')->once()->with('boom', ['ctx' => 1]);

        Log::setLoggerResolver(fn () => $logger);

        $this->assertNull(Log::error('boom', ['ctx' => 1]));
    }

    public function testResolvePathKeepsAbsolutePathsAndExpandsRelativeOnes(): void
    {
        $manager = new EngineManager(Container::getInstance());
        $resolve = new \ReflectionMethod($manager, 'resolvePath');
        $resolve->setAccessible(true);

        $this->assertSame('/etc/certs/x.pem', $resolve->invoke($manager, '/etc/certs/x.pem'));
        $this->assertSame('C:\\certs\\x.pem', $resolve->invoke($manager, 'C:\\certs\\x.pem'));

        // 相对路径：宿主可能提供 base_path()（Webman / Laravel / 测试桩），也可能没有
        // （原生 PHP）——两种情况下都必须是绝对路径且带分隔符，不出现 "appstorage/x.pem"。
        $joined = $resolve->invoke($manager, 'storage/x.pem');
        $this->assertStringStartsWith('/', $joined);
        $this->assertStringEndsWith('/storage/x.pem', $joined);
    }
}
