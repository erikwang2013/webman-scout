<?php

namespace Erikwang2013\WebmanScout\Tests\Yii;

require_once __DIR__ . '/../Support/WebmanStubs.php';
require_once __DIR__ . '/../Support/Fixtures.php';

use Erikwang2013\WebmanScout\EngineManager;
use Erikwang2013\WebmanScout\Engines\Engine;
use Erikwang2013\WebmanScout\Events\ModelsImported;
use Erikwang2013\WebmanScout\ScoutConfig;
use Erikwang2013\WebmanScout\Tests\Support\TestSearchableModel;
use Erikwang2013\WebmanScout\Yii\ScoutController;
use Illuminate\Container\Container;
use Illuminate\Events\Dispatcher;
use Mockery;
use PHPUnit\Framework\TestCase;

use function Erikwang2013\WebmanScout\Tests\Support\scoutSource;

class ScoutControllerTest extends TestCase
{
    protected function setUp(): void
    {
        TestSearchableModel::resetHooks();
        ScoutConfig::setSource(scoutSource([
            'driver' => 'meilisearch',
            'prefix' => 'scout_',
            'soft_delete' => false,
        ]));
    }

    protected function tearDown(): void
    {
        ScoutConfig::setSource(null);
        ScoutConfig::resetResolvedBase();
        Container::getInstance()->flush();
        Mockery::close();
    }

    public function testActionImportRunsSymfonyCommand(): void
    {
        $dispatcher = Mockery::mock(Dispatcher::class);
        $dispatcher->shouldReceive('listen')->once()->with(ModelsImported::class, Mockery::type(\Closure::class));
        $dispatcher->shouldReceive('forget')->once()->with(ModelsImported::class);
        Container::getInstance()->instance(Dispatcher::class, $dispatcher);

        $code = (new ScoutController())->actionImport(TestSearchableModel::class, 100, true);

        $this->assertSame(0, $code);
        $this->assertTrue(TestSearchableModel::$flushed);
        $this->assertSame(100, TestSearchableModel::$lastChunk);
    }

    public function testActionIndexRunsSymfonyCommand(): void
    {
        $engine = Mockery::mock(Engine::class);
        $engine->shouldReceive('createIndex')->once()->with('scout_posts', ['primaryKey' => 'id']);
        $manager = Mockery::mock(EngineManager::class);
        $manager->shouldReceive('engine')->once()->andReturn($engine);
        Container::getInstance()->instance(EngineManager::class, $manager);

        $code = (new ScoutController())->actionIndex('posts', 'id');

        $this->assertSame(0, $code);
    }

    public function testActionDeleteAllIndexesPropagatesFailure(): void
    {
        $manager = Mockery::mock(EngineManager::class);
        $manager->shouldReceive('engine')->once()->andReturn(Mockery::mock(Engine::class));
        Container::getInstance()->instance(EngineManager::class, $manager);

        $code = (new ScoutController())->actionDeleteAllIndexes();

        $this->assertSame(1, $code);
    }

    public function testActionFlushRunsSymfonyCommand(): void
    {
        $code = (new ScoutController())->actionFlush(TestSearchableModel::class);

        $this->assertSame(0, $code);
        $this->assertTrue(TestSearchableModel::$flushed);
    }
}
