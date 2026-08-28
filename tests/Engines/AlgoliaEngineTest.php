<?php

namespace Erikwang2013\WebmanScout\Tests\Engines;

use Erikwang2013\WebmanScout\Builder;
use Erikwang2013\WebmanScout\Engines\AlgoliaEngine;
use Erikwang2013\WebmanScout\Exceptions\NotSupportedException;
use Erikwang2013\WebmanScout\Tests\Fixtures\Post;
use Erikwang2013\WebmanScout\Tests\Fixtures\PostSoft;
use Erikwang2013\WebmanScout\Tests\TestCase;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\LazyCollection;
use Mockery;

class AlgoliaEngineTest extends TestCase
{
    protected function makeEngine($algolia = null): AlgoliaEngine
    {
        return new class($algolia ?? Mockery::mock('AlgoliaClient')) extends AlgoliaEngine {
            protected function performSearch(Builder $builder, array $options = [])
            {
                return $options;
            }

            public function update($models)
            {
            }

            public function delete($models)
            {
            }

            public function deleteIndex($name)
            {
                return 'deleted';
            }

            public function flush($model)
            {
            }

            public function updateIndexSettings(string $name, array $settings = [])
            {
            }

            public function usesSoftDeletePub($model)
            {
                return $this->usesSoftDelete($model);
            }
        };
    }

    public function testSearchPassesFiltersAndLimitAsOptions(): void
    {
        $builder = (new Builder(new Post, 'foo'))->where('status', 1)->take(5);

        $this->assertSame([
            'numericFilters' => ['status=1'],
            'hitsPerPage' => 5,
        ], $this->makeEngine()->search($builder));
    }

    public function testPaginateUsesZeroBasedPage(): void
    {
        $options = $this->makeEngine()->paginate(new Builder(new Post, 'foo'), 20, 3);

        $this->assertSame(20, $options['hitsPerPage']);
        $this->assertSame(2, $options['page']);
    }

    public function testFiltersCombineWheresWhereInsAndWhereNotIns(): void
    {
        $builder = (new Builder(new Post, ''))
            ->where('status', 1)
            ->whereIn('id', [1, 2])
            ->whereIn('empty', [])
            ->whereNotIn('tag', ['x', 'y']);

        $filters = $this->makeEngine()->search($builder)['numericFilters'];

        $this->assertSame(['status=1', ['id=1', 'id=2'], '0=1', 'tag!=x', 'tag!=y'], $filters);
    }

    public function testMapIdsPlucksObjectIds(): void
    {
        $this->assertSame(
            ['1', '2'],
            $this->makeEngine()->mapIds(['hits' => [['objectID' => '1'], ['objectID' => '2']]])->all()
        );
        $this->assertTrue($this->makeEngine()->mapIds(['hits' => []])->isEmpty());
    }

    public function testMapRestoresModelsInResultOrderAndAppliesMetadata(): void
    {
        $model = Mockery::mock(Post::class)->makePartial();
        $model->shouldReceive('getScoutModelsByIds')->once()->with(Mockery::type(Builder::class), ['2', '1'])
            ->andReturn(new EloquentCollection([
                (new Post)->newFromBuilder(['id' => 1, 'title' => 'one']),
                (new Post)->newFromBuilder(['id' => 2, 'title' => 'two']),
            ]));

        $results = ['hits' => [
            ['objectID' => '2', '_rankingInfo' => ['nbTypos' => 1]],
            ['objectID' => '1'],
        ]];

        $mapped = $this->makeEngine()->map(new Builder($model, ''), $results, $model);

        $this->assertSame([2, 1], $mapped->pluck('id')->all());
        $this->assertSame(['nbTypos' => 1], $mapped->first()->scoutMetadata()['_rankingInfo']);

        $this->assertTrue($this->makeEngine()->map(new Builder($model, ''), ['hits' => []], $model)->isEmpty());
    }

    public function testLazyMapRestoresModelsInResultOrder(): void
    {
        $query = Mockery::mock(\Illuminate\Database\Eloquent\Builder::class)->makePartial();
        $query->shouldReceive('cursor')->andReturn(LazyCollection::empty());

        $model = Mockery::mock(Post::class)->makePartial();
        $model->shouldReceive('queryScoutModelsByIds')->once()->with(Mockery::type(Builder::class), ['2', '1'])
            ->andReturn($query);

        $lazy = $this->makeEngine()->lazyMap(new Builder($model, ''), [
            'hits' => [['objectID' => '2'], ['objectID' => '1']],
        ], $model);

        $this->assertInstanceOf(LazyCollection::class, $lazy);
        $this->assertTrue($lazy->isEmpty());

        $this->assertInstanceOf(LazyCollection::class, $this->makeEngine()->lazyMap(new Builder($model, ''), ['hits' => []], $model));
    }

    public function testGetTotalCountReadsNbHits(): void
    {
        $this->assertSame(7, $this->makeEngine()->getTotalCount(['nbHits' => 7]));
    }

    public function testCreateIndexThrowsNotSupported(): void
    {
        $this->expectException(NotSupportedException::class);

        $this->makeEngine()->createIndex('posts');
    }

    public function testConfigureSoftDeleteFilterAppendsFilterOnlyFacet(): void
    {
        $settings = $this->makeEngine()->configureSoftDeleteFilter(['attributesForFaceting' => ['status']]);

        $this->assertSame(['status', 'filterOnly(__soft_deleted)'], $settings['attributesForFaceting']);
    }

    public function testUsesSoftDeleteDetectsSoftDeletesTrait(): void
    {
        $engine = $this->makeEngine();

        $this->assertFalse($engine->usesSoftDeletePub(new Post));
        $this->assertTrue($engine->usesSoftDeletePub(new PostSoft));
    }

    public function testDynamicCallsAreForwardedToClient(): void
    {
        $algolia = Mockery::mock('AlgoliaClient');
        $algolia->shouldReceive('ping')->once()->with()->andReturn('pong');

        $this->assertSame('pong', $this->makeEngine($algolia)->ping());
    }
}
