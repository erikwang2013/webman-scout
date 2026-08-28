<?php

namespace Erikwang2013\WebmanScout\Tests;

require_once __DIR__ . '/Support/WebmanStubs.php';

use Erikwang2013\WebmanScout\Builder;
use Erikwang2013\WebmanScout\EngineManager;
use Erikwang2013\WebmanScout\Engines\NullEngine;
use Erikwang2013\WebmanScout\ModelObserver;
use Erikwang2013\WebmanScout\ScoutConfig;
use Erikwang2013\WebmanScout\Tests\Fixtures\SearchableStubModel;
use Illuminate\Container\Container;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Mockery;
use PDO;
use PHPUnit\Framework\TestCase;

class SearchableTest extends TestCase
{
    protected $container;

    protected function setUp(): void
    {
        $this->container = Container::getInstance();
        \Illuminate\Support\Facades\Facade::setFacadeApplication($this->container);
    }

    protected function tearDown(): void
    {
        $this->container->forgetInstance(EngineManager::class);
        $this->container->forgetInstance('events');
        $this->container->forgetInstance(Dispatcher::class);
        $this->container->forgetInstance('log');
        $this->container->forgetInstance('config');
        Model::unsetConnectionResolver();
        Model::unsetEventDispatcher();
        Mockery::close();
        ScoutConfig::setSource(null);
        ScoutConfig::resetResolvedBase();
        ModelObserver::enableSyncingFor(SearchableStubModel::class);
        $this->addToAssertionCount(1); // count Mockery expectation verifications
    }

    protected function setConfig(array $params): void
    {
        ScoutConfig::setSource(static function (string $key, $default = null) use ($params) {
            foreach (explode('.', $key) as $segment) {
                if (! is_array($params) || ! array_key_exists($segment, $params)) {
                    return $default;
                }
                $params = $params[$segment];
            }

            return $params;
        });
    }

    protected function defaultConfig(): void
    {
        $this->setConfig([
            'scout' => [
                'driver' => 'null',
                'prefix' => 'test_',
                'soft_delete' => false,
                'queue' => false,
                'after_commit' => false,
                'chunk' => ['searchable' => 500, 'unsearchable' => 500],
            ],
        ]);
    }

    protected function sqliteConnection(): Connection
    {
        $connection = new Connection(new PDO('sqlite::memory:'), 'default', '', ['driver' => 'sqlite']);
        $connection->setSchemaGrammar(new \Illuminate\Database\Schema\Grammars\SQLiteGrammar($connection));

        return $connection;
    }

    protected function sqliteSetup(): array
    {
        $connection = $this->sqliteConnection();
        $connection->getSchemaBuilder()->create('scout_stub_models', function ($table) {
            $table->increments('id');
            $table->string('title');
            $table->timestamp('deleted_at')->nullable();
        });
        $connection->table('scout_stub_models')->insert([
            ['title' => 'first'],
            ['title' => 'second'],
            ['title' => 'third'],
        ]);

        return [$connection, $connection->table('scout_stub_models')->count()];
    }

    public function testSearchReturnsBuilder(): void
    {
        $this->defaultConfig();

        $callback = function () {
            return null;
        };
        $builder = SearchableStubModel::search('hello', $callback);

        $this->assertInstanceOf(Builder::class, $builder);
        $this->assertInstanceOf(SearchableStubModel::class, $builder->model);
        $this->assertSame('hello', $builder->query);
        $this->assertSame($callback, $builder->callback);
        $this->assertSame([], $builder->wheres);
    }

    public function testSearchAsAndIndexableAs(): void
    {
        $this->defaultConfig();

        $model = new SearchableStubModel();
        $this->assertSame('test_scout_stub_models', $model->searchableAs());
        $this->assertSame($model->searchableAs(), $model->indexableAs());
    }

    public function testToSearchableArray(): void
    {
        $this->defaultConfig();

        $model = new SearchableStubModel();
        $model->setRawAttributes(['id' => 1, 'title' => 'hello']);

        $this->assertSame(['id' => 1, 'title' => 'hello'], $model->toSearchableArray());
    }

    public function testScoutKeyAccessors(): void
    {
        $this->defaultConfig();

        $model = new SearchableStubModel();
        $model->setRawAttributes(['id' => 5]);

        $this->assertSame(5, $model->getScoutKey());
        $this->assertSame('id', $model->getScoutKeyName());
        $this->assertSame('int', $model->getScoutKeyType());
    }

    public function testDefaultSearchablePredicates(): void
    {
        $this->defaultConfig();

        $model = new SearchableStubModel();
        $this->assertTrue($model->shouldBeSearchable());
        $this->assertTrue($model->searchIndexShouldBeUpdated());
        $this->assertTrue($model->wasSearchableBeforeUpdate());
        $this->assertTrue($model->wasSearchableBeforeDelete());
        $this->assertSame($model, $model->makeSearchableUsing(EloquentCollection::make([$model]))->first());
    }

    public function testSearchableUsingResolvesEngineFromContainer(): void
    {
        $this->defaultConfig();

        $model = new SearchableStubModel();
        $this->assertInstanceOf(NullEngine::class, $model->searchableUsing());
    }

    public function testSearchableSyncsToEngine(): void
    {
        $this->defaultConfig();
        [$manager, $engine] = $this->bindMockEngine();
        $engine->shouldReceive('update')->once()->with(Mockery::type(EloquentCollection::class));

        $model = new SearchableStubModel();
        $model->setRawAttributes(['id' => 1]);
        $model->searchable();
    }

    public function testSearchableSyncMethod(): void
    {
        $this->defaultConfig();
        [, $engine] = $this->bindMockEngine();
        $engine->shouldReceive('update')->once()->with(Mockery::type(EloquentCollection::class));

        (new SearchableStubModel())->searchableSync();
    }

    public function testUnsearchableSyncsToEngine(): void
    {
        $this->defaultConfig();
        [, $engine] = $this->bindMockEngine();
        $engine->shouldReceive('delete')->once()->with(Mockery::type(EloquentCollection::class));

        $model = new SearchableStubModel();
        $model->setRawAttributes(['id' => 1]);
        $model->unsearchable();
    }

    public function testUnsearchableSyncMethod(): void
    {
        $this->defaultConfig();
        [, $engine] = $this->bindMockEngine();
        $engine->shouldReceive('delete')->once()->with(Mockery::type(EloquentCollection::class));

        (new SearchableStubModel())->unsearchableSync();
    }

    public function testQueueMakeSearchableSkipsEmptyCollections(): void
    {
        $this->defaultConfig();
        [, $engine] = $this->bindMockEngine();
        $engine->shouldReceive('update')->never();

        $model = new SearchableStubModel();
        $model->queueMakeSearchable(EloquentCollection::make());
    }

    public function testQueueEnabledSendsToRedisQueue(): void
    {
        // WebmanStubs 定义了 Webman\RedisQueue\Redis 桩类，套件内 class_exists 恒为 true，
        // 队列路径是唯一可达分支；无 redis-queue 的回退分支仅能在未加载桩类的进程中测试。
        $this->setConfig([
            'scout' => [
                'driver' => 'null',
                'queue' => true,
                'after_commit' => false,
                'soft_delete' => false,
            ],
        ]);
        [, $engine] = $this->bindMockEngine();
        $engine->shouldReceive('update')->never();

        \Webman\RedisQueue\Redis::resetSent();
        $model = new SearchableStubModel();
        $model->setRawAttributes(['id' => 1]);
        $model->queueMakeSearchable(EloquentCollection::make([$model]));

        $this->assertCount(1, \Webman\RedisQueue\Redis::$sent);
        $this->assertSame('scout_make', \Webman\RedisQueue\Redis::$sent[0]['queue']);
    }

    public function testRemoveAllFromSearchFlushesEngine(): void
    {
        $this->defaultConfig();
        [, $engine] = $this->bindMockEngine();
        $engine->shouldReceive('flush')->once()->with(Mockery::type(SearchableStubModel::class));

        SearchableStubModel::removeAllFromSearch();
    }

    public function testMakeAllSearchableIndexesAllRows(): void
    {
        $this->defaultConfig();
        [$connection, $count] = $this->sqliteSetup();
        $this->assertSame(3, $count);

        $resolver = new ConnectionResolver(['default' => $connection]);
        $resolver->setDefaultConnection('default');
        Model::setConnectionResolver($resolver);
        $dispatcher = new Dispatcher($this->container);
        $this->container->instance('events', $dispatcher);
        $this->container->instance(Dispatcher::class, $dispatcher);
        $imported = 0;
        $dispatcher->listen(\Erikwang2013\WebmanScout\Events\ModelsImported::class, function () use (&$imported) {
            $imported++;
        });

        SearchableStubModel::makeAllSearchable(2);

        $this->assertSame(3, $connection->table('scout_stub_models')->count());
        $this->assertSame(2, $imported); // 3 rows, chunk 2 -> 2 chunks
    }

    public function testMakeAllSearchableQueryOrdersByScoutKey(): void
    {
        $this->defaultConfig();
        [$connection] = $this->sqliteSetup();
        $resolver = new ConnectionResolver(['default' => $connection]);
        $resolver->setDefaultConnection('default');
        Model::setConnectionResolver($resolver);

        $query = SearchableStubModel::makeAllSearchableQuery();

        $this->assertInstanceOf(\Illuminate\Database\Eloquent\Builder::class, $query);
        $this->assertCount(3, $query->get());
    }

    public function testEnableDisableSearchSyncing(): void
    {
        $this->defaultConfig();

        SearchableStubModel::disableSearchSyncing();
        $this->assertTrue(ModelObserver::syncingDisabledFor(SearchableStubModel::class));

        SearchableStubModel::enableSearchSyncing();
        $this->assertFalse(ModelObserver::syncingDisabledFor(SearchableStubModel::class));
    }

    public function testWithoutSyncingToSearchRestoresState(): void
    {
        $this->defaultConfig();

        $result = SearchableStubModel::withoutSyncingToSearch(function () {
            return ModelObserver::syncingDisabledFor(SearchableStubModel::class) ? 'disabled' : 'enabled';
        });

        $this->assertSame('disabled', $result);
        $this->assertFalse(ModelObserver::syncingDisabledFor(SearchableStubModel::class));
    }

    public function testScoutMetadata(): void
    {
        $this->defaultConfig();

        $model = new SearchableStubModel();
        $this->assertSame([], $model->scoutMetadata());

        $this->assertSame($model, $model->withScoutMetadata('__soft_deleted', 1));
        $this->assertSame(['__soft_deleted' => 1], $model->scoutMetadata());
    }

    public function testPushSoftDeleteMetadata(): void
    {
        $this->defaultConfig();
        $resolver = new ConnectionResolver(['default' => $this->sqliteConnection()]);
        $resolver->setDefaultConnection('default');
        Model::setConnectionResolver($resolver);

        $model = new SearchableStubModel();
        $model->exists = true;
        $model->setRawAttributes(['id' => 1, 'deleted_at' => '2024-01-01 00:00:00']);
        $model->pushSoftDeleteMetadata();
        $this->assertSame(1, $model->scoutMetadata()['__soft_deleted']);

        $model2 = new SearchableStubModel();
        $model2->exists = true;
        $model2->setRawAttributes(['id' => 2, 'deleted_at' => null]);
        $model2->pushSoftDeleteMetadata();
        $this->assertSame(0, $model2->scoutMetadata()['__soft_deleted']);
    }

    public function testSyncWithSearchUsing(): void
    {
        $this->setConfig([
            'scout' => [
                'driver' => 'null',
                'queue' => false,
                'queue' => ['connection' => 'redis', 'queue' => 'scout_queue'],
            ],
        ]);

        $model = new SearchableStubModel();
        $this->assertSame('redis', $model->syncWithSearchUsing());
        $this->assertSame('scout_queue', $model->syncWithSearchUsingQueue());
    }

    public function testSyncWithSearchUsingDefaultsToConfigQueueDefault(): void
    {
        $this->defaultConfig();
        $this->container->instance('config', new \Illuminate\Config\Repository(['queue' => ['default' => 'sync']]));

        $this->assertSame('sync', (new SearchableStubModel())->syncWithSearchUsing());
    }

    /**
     * Bind a mocked EngineManager into the container and return [$managerMock, $engineMock].
     *
     * @return array{0: \Mockery\MockInterface, 1: \Mockery\MockInterface}
     */
    protected function bindMockEngine(): array
    {
        $engine = Mockery::mock(\Erikwang2013\WebmanScout\Engines\Engine::class);
        $manager = Mockery::mock(EngineManager::class);
        $manager->shouldReceive('engine')->andReturn($engine);
        $this->container->instance(EngineManager::class, $manager);

        return [$manager, $engine];
    }
}
