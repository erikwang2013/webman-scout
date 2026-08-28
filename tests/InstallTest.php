<?php

namespace Erikwang2013\WebmanScout\Tests;

require_once __DIR__ . '/Support/WebmanStubs.php';

use Erikwang2013\WebmanScout\Install;
use Illuminate\Container\Container;
use Mockery;
use PHPUnit\Framework\TestCase;

class InstallTest extends TestCase
{
    private string $base;

    private ?Container $originalContainer;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/webman-scout-test-' . uniqid();
        mkdir($this->base, 0777, true);
        putenv('WEBMAN_SCOUT_TEST_BASE=' . $this->base);

        // base_path() is provided by the host framework and calls
        // app()->basePath(); point the container at a temp base path.
        $this->originalContainer = Container::getInstance();
        $app = Mockery::mock(Container::class)->makePartial();
        $app->shouldReceive('basePath')->andReturn($this->base);
        Container::setInstance($app);
    }

    protected function tearDown(): void
    {
        Container::setInstance($this->originalContainer);
        Mockery::close();
        if (is_dir($this->base)) {
            remove_dir($this->base);
        }
        putenv('WEBMAN_SCOUT_TEST_BASE');
    }

    public function testPluginConstants(): void
    {
        $this->assertTrue(Install::WEBMAN_PLUGIN);
    }

    public function testInstallCopiesFiles(): void
    {
        Install::install();

        $this->assertDirectoryExists($this->base . '/config/plugin/erikwang2013/webman-scout');
        $this->assertDirectoryExists($this->base . '/app/queue/redis/search');
        $this->assertFileExists($this->base . '/config/plugin/erikwang2013/webman-scout/app.php');
        $this->assertFileExists($this->base . '/app/queue/redis/search/MakeSearchable.php');
    }

    public function testUninstallRemovesFiles(): void
    {
        Install::install();
        Install::uninstall();

        $this->assertDirectoryDoesNotExist($this->base . '/config/plugin/erikwang2013/webman-scout');
        $this->assertDirectoryDoesNotExist($this->base . '/app/queue/redis/search');
    }

    public function testUninstallWithoutInstallIsNoOp(): void
    {
        Install::uninstall();

        $this->assertDirectoryDoesNotExist($this->base . '/config/plugin/erikwang2013/webman-scout');
    }
}
