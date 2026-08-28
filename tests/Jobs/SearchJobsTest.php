<?php

namespace Erikwang2013\WebmanScout\Tests\Jobs;

require_once __DIR__ . '/../Support/WebmanStubs.php';
require_once __DIR__ . '/../Support/Fixtures.php';
require_once __DIR__ . '/../../src/Jobs/search/MakeSearchable.php';
require_once __DIR__ . '/../../src/Jobs/search/MakeRangeSearchable.php';
require_once __DIR__ . '/../../src/Jobs/search/RemoveFromSearch.php';

use app\queue\redis\search\MakeRangeSearchable;
use app\queue\redis\search\MakeSearchable;
use app\queue\redis\search\RemoveFromSearch;
use Erikwang2013\WebmanScout\EngineManager;
use Erikwang2013\WebmanScout\Engines\Engine;
use Erikwang2013\WebmanScout\ScoutConfig;
use Erikwang2013\WebmanScout\Tests\Support\TestSearchableModel;
use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Mockery;
use PHPUnit\Framework\TestCase;
use Webman\RedisQueue\Redis as QueueRedis;

use function Erikwang2013\WebmanScout\Tests\Support\scoutSource;

class SearchJobsTest extends TestCase
{
    protected function setUp(): void
    {
        TestSearchableModel::resetHooks();
        QueueRedis::resetSent();
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

    private function bindEngine(): Engine
    {
        $engine = Mockery::mock(Engine::class);
        $manager = Mockery::mock(EngineManager::class);
        $manager->shouldReceive('engine')->once()->andReturn($engine);
        Container::getInstance()->instance(EngineManager::class, $manager);

        return $engine;
    }

    private function collection(int ...$ids): Collection
    {
        $models = array_map(function (int $id) {
            $model = new TestSearchableModel;
            $model->id = $id;

            return $model;
        }, $ids);

        return new Collection($models);
    }

    public function testMakeSearchableUpdatesEngine(): void
    {
        $models = $this->collection(1, 2);
        $engine = $this->bindEngine();
        $engine->shouldReceive('update')->once()->withArgs(function ($arg) use ($models) {
            return $arg instanceof Collection && $arg->count() === 2;
        });

        (new MakeSearchable)->consume(serialize($models));

        $this->assertSame([], QueueRedis::$sent);
    }

    public function testMakeSearchableThrowsOnInvalidPayload(): void
    {
        $this->expectException(\RuntimeException::class);

        (new MakeSearchable)->consume(serialize('not a collection'));
    }

    public function testMakeSearchableThrowsOnEmptyCollection(): void
    {
        $this->expectException(\RuntimeException::class);

        (new MakeSearchable)->consume(serialize(new Collection()));
    }

    public function testMakeRangeSearchableUpdatesEngine(): void
    {
        $query = Mockery::mock(Builder::class);
        $query->shouldReceive('whereBetween')->once()->with('id', [1, 3])->andReturnSelf();
        $query->shouldReceive('get')->once()->andReturn($this->collection(1, 2, 3));
        TestSearchableModel::$query = $query;

        $engine = $this->bindEngine();
        $engine->shouldReceive('update')->once()->withArgs(fn ($arg) => $arg instanceof Collection && $arg->count() === 3);

        (new MakeRangeSearchable)->consume(['model' => TestSearchableModel::class, 'start' => 1, 'end' => 3]);

        $this->assertSame([], QueueRedis::$sent);
    }

    public function testMakeRangeSearchableThrowsOnMissingPayload(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new MakeRangeSearchable)->consume(['model' => TestSearchableModel::class]);
    }

    public function testMakeRangeSearchableRetriesAndRethrows(): void
    {
        $query = Mockery::mock(Builder::class);
        $query->shouldReceive('whereBetween')->once()->andReturnSelf();
        $query->shouldReceive('get')->once()->andThrow(new \RuntimeException('db down'));
        TestSearchableModel::$query = $query;

        try {
            (new MakeRangeSearchable)->consume(['model' => TestSearchableModel::class, 'start' => 1, 'end' => 3]);
            $this->fail('Expected RuntimeException was not thrown.');
        } catch (\RuntimeException $e) {
            $this->assertSame('db down', $e->getMessage());
        }

        $this->assertCount(1, QueueRedis::$sent);
        $this->assertSame('scout_make_range', QueueRedis::$sent[0]['queue']);
        $this->assertSame(5, QueueRedis::$sent[0]['delay']);
        $this->assertSame(2, QueueRedis::$sent[0]['data']['attempts']);
    }

    public function testMakeRangeSearchableStopsRetryingAfterFiveAttempts(): void
    {
        $query = Mockery::mock(Builder::class);
        $query->shouldReceive('whereBetween')->once()->andReturnSelf();
        $query->shouldReceive('get')->once()->andThrow(new \RuntimeException('db down'));
        TestSearchableModel::$query = $query;

        try {
            (new MakeRangeSearchable)->consume([
                'model' => TestSearchableModel::class,
                'start' => 1,
                'end' => 3,
                'attempts' => 5,
            ]);
            $this->fail('Expected RuntimeException was not thrown.');
        } catch (\RuntimeException $e) {
            $this->assertSame('db down', $e->getMessage());
        }

        $this->assertSame([], QueueRedis::$sent);
    }

    public function testRemoveFromSearchDeletesFromEngine(): void
    {
        $models = $this->collection(1, 2);
        $engine = $this->bindEngine();
        $engine->shouldReceive('delete')->once()->withArgs(function ($arg) use ($models) {
            return $arg instanceof Collection && $arg->count() === 2;
        });

        (new RemoveFromSearch)->consume(serialize($models));

        $this->assertSame([], QueueRedis::$sent);
    }

    public function testRemoveFromSearchThrowsOnInvalidPayload(): void
    {
        $this->expectException(\RuntimeException::class);

        (new RemoveFromSearch)->consume(serialize('nope'));
    }

    public function testRemoveFromSearchThrowsOnEmptyCollection(): void
    {
        $this->expectException(\RuntimeException::class);

        (new RemoveFromSearch)->consume(serialize(new Collection()));
    }
}
