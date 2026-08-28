<?php

namespace Erikwang2013\WebmanScout\Tests;

use BadMethodCallException;
use Erikwang2013\WebmanScout\Builder;
use Erikwang2013\WebmanScout\Engines\AdvancedMeilisearchEngine;
use Erikwang2013\WebmanScout\Engines\Engine;
use Erikwang2013\WebmanScout\ScoutConfig;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\LazyCollection;
use Mockery;
use PHPUnit\Framework\TestCase;

class BuilderTest extends TestCase
{
    /** @var \Mockery\MockInterface|Engine */
    protected $engine;

    protected function tearDown(): void
    {
        Mockery::close();
        Builder::flushMacros();
        ScoutConfig::setSource(null);
        ScoutConfig::resetResolvedBase();
    }

    /**
     * @return \Mockery\MockInterface|Model
     */
    protected function baseModelMock()
    {
        $model = Mockery::mock(Model::class);
        $model->shouldReceive('searchableUsing')->andReturn($this->engine);
        $model->shouldReceive('getPerPage')->andReturn(15);
        $model->shouldReceive('newCollection')->andReturnUsing(function ($items = []) {
            return new EloquentCollection($items);
        });

        return $model;
    }

    protected function makeBuilder($query = 'foo', $softDelete = false)
    {
        return new Builder($this->baseModelMock(), $query, null, $softDelete);
    }

    public function testConstructorDefaults(): void
    {
        $builder = new Builder(new \stdClass(), 'hello');

        $this->assertSame('hello', $builder->query);
        $this->assertSame([], $builder->wheres);
        $this->assertSame([], $builder->whereIns);
        $this->assertSame([], $builder->whereNotIns);
        $this->assertNull($builder->index);
        $this->assertNull($builder->limit);
        $this->assertNull($builder->callback);
        $this->assertNull($builder->queryCallback);
        $this->assertNull($builder->afterRawSearchCallback);
    }

    public function testConstructorSoftDeleteAddsWhere(): void
    {
        $builder = new Builder(new \stdClass(), 'x', null, true);

        $this->assertSame(['__soft_deleted' => 0], $builder->wheres);
    }

    public function testWithinSetsIndex(): void
    {
        $builder = $this->makeBuilder();

        $this->assertSame($builder, $builder->within('posts_index'));
        $this->assertSame('posts_index', $builder->index);
    }

    public function testWhereStoresConstraint(): void
    {
        $builder = $this->makeBuilder();
        $builder->where('status', 1)->where('user_id', 42);

        $this->assertSame(['status' => 1, 'user_id' => 42], $builder->wheres);

        // overwriting an existing field
        $builder->where('status', 2);
        $this->assertSame(['status' => 2, 'user_id' => 42], $builder->wheres);
    }

    public function testWhereInWithArrayAndArrayable(): void
    {
        $builder = $this->makeBuilder();
        $builder->whereIn('ids', [1, 2, 3]);
        $this->assertSame(['ids' => [1, 2, 3]], $builder->whereIns);

        $builder->whereIn('cats', new SupportCollection([4, 5]));
        $this->assertSame(['ids' => [1, 2, 3], 'cats' => [4, 5]], $builder->whereIns);
    }

    public function testWhereNotInWithArrayAndArrayable(): void
    {
        $builder = $this->makeBuilder();
        $builder->whereNotIn('ids', [1, 2]);
        $this->assertSame(['ids' => [1, 2]], $builder->whereNotIns);

        $builder->whereNotIn('cats', new SupportCollection([3]));
        $this->assertSame(['ids' => [1, 2], 'cats' => [3]], $builder->whereNotIns);
    }

    public function testWithTrashedAndOnlyTrashed(): void
    {
        $builder = new Builder(new \stdClass(), 'x', null, true);
        $this->assertSame(['__soft_deleted' => 0], $builder->wheres);

        $builder->withTrashed();
        $this->assertArrayNotHasKey('__soft_deleted', $builder->wheres);

        $builder->onlyTrashed();
        $this->assertSame(['__soft_deleted' => 1], $builder->wheres);
    }

    public function testTakeSetsLimit(): void
    {
        $builder = $this->makeBuilder();
        $this->assertSame($builder, $builder->take(10));
        $this->assertSame(10, $builder->limit);
    }

    public function testOrderByNormalizesDirectionAndFillsOrdersAndSorts(): void
    {
        $builder = $this->makeBuilder();
        $builder->orderBy('title', 'asc');
        $builder->orderBy('created_at', 'DESC');
        $builder->orderBy('rating', 'sideways'); // unknown direction defaults to desc

        $this->assertSame([
            ['column' => 'title', 'direction' => 'asc'],
            ['column' => 'created_at', 'direction' => 'desc'],
            ['column' => 'rating', 'direction' => 'desc'],
        ], $builder->orders);

        $this->assertSame('title', $builder->getSorts()[0]['field']);
        $this->assertSame('created_at', $builder->getSorts()[1]['field']);
        $this->assertSame(['options' => []], array_intersect_key($builder->getSorts()[0], ['options' => 1]));
    }

    public function testOrderByDesc(): void
    {
        $builder = $this->makeBuilder();
        $builder->orderByDesc('published_at');

        $this->assertSame([['column' => 'published_at', 'direction' => 'desc']], $builder->orders);
    }

    public function testLatestUsesModelCreatedAtColumnByDefault(): void
    {
        $model = $this->baseModelMock();
        $model->shouldReceive('getCreatedAtColumn')->once()->andReturn('created_at');
        $builder = new Builder($model, 'foo');

        $builder->latest();
        $this->assertSame([['column' => 'created_at', 'direction' => 'desc']], $builder->orders);

        $builder->latest('published_at');
        $this->assertSame('published_at', $builder->orders[1]['column']);
    }

    public function testOldest(): void
    {
        $model = $this->baseModelMock();
        $model->shouldReceive('getCreatedAtColumn')->once()->andReturn('created_at');
        $builder = new Builder($model, 'foo');

        $builder->oldest();
        $this->assertSame([['column' => 'created_at', 'direction' => 'asc']], $builder->orders);
    }

    public function testOptionsSetters(): void
    {
        $builder = $this->makeBuilder();
        $builder->options(['filters' => 'x']);
        $this->assertSame(['filters' => 'x'], $builder->getOptions());

        $builder->setOption('limit', 5);
        $this->assertSame(['filters' => 'x', 'limit' => 5], $builder->getOptions());

        $builder->setOptions(['filters' => 'y', 'extra' => 1]);
        $this->assertSame(['filters' => 'y', 'limit' => 5, 'extra' => 1], $builder->getOptions());
    }

    public function testQuerySetsQueryCallback(): void
    {
        $callback = function () {
            return null;
        };
        $builder = $this->makeBuilder();

        $this->assertSame($builder, $builder->query($callback));
        $this->assertSame($callback, $builder->queryCallback);
    }

    public function testWithRawResultsAndApplyCallback(): void
    {
        $builder = $this->makeBuilder();
        $builder->withRawResults(function ($results) {
            $results['extra'] = true;

            return $results;
        });

        $this->assertInstanceOf(\Closure::class, $builder->afterRawSearchCallback);
        $this->assertSame(['extra' => true], $builder->applyAfterRawSearchCallback([]));

        // callback returning null keeps the original results
        $builder2 = $this->makeBuilder();
        $builder2->withRawResults(function ($results) {
            return null;
        });
        $this->assertSame(['keep' => 1], $builder2->applyAfterRawSearchCallback(['keep' => 1]));
    }

    public function testKeysDelegatesToEngine(): void
    {
        $this->engine = Mockery::mock(Engine::class);
        $this->engine->shouldReceive('keys')->once()->andReturn(SupportCollection::make([1, 2]));
        $builder = $this->makeBuilder();

        $this->assertSame([1, 2], $builder->keys()->all());
    }

    public function testFirstReturnsFirstModelFromGet(): void
    {
        $this->engine = Mockery::mock(Engine::class);
        $m1 = Mockery::mock(Model::class);
        $this->engine->shouldReceive('get')->once()->withArgs(function ($b) {
            return $b instanceof Builder && $b->query === 'foo';
        })->andReturn(EloquentCollection::make([$m1]));

        $this->assertSame($m1, $this->makeBuilder()->first());
    }

    public function testCursorDelegatesToEngine(): void
    {
        $this->engine = Mockery::mock(Engine::class);
        $this->engine->shouldReceive('cursor')->once()->andReturn(LazyCollection::make([1, 2, 3]));
        $builder = $this->makeBuilder();

        $cursor = $builder->cursor();
        $this->assertInstanceOf(LazyCollection::class, $cursor);
        $this->assertSame([1, 2, 3], $cursor->all());
    }

    public function testGetDelegatesToEngine(): void
    {
        $this->engine = Mockery::mock(Engine::class);
        $m1 = Mockery::mock(Model::class);
        $this->engine->shouldReceive('get')->once()->withArgs(function ($b) {
            return $b instanceof Builder && $b->query === 'foo';
        })->andReturn(EloquentCollection::make([$m1]));
        $builder = $this->makeBuilder();

        $this->assertSame([$m1], $builder->get()->all());
    }

    public function testGetUsesAdvancedSearchWhenEngineSupportsIt(): void
    {
        $this->engine = Mockery::mock(\Erikwang2013\WebmanScout\Engines\AdvancedXunSearchEngine::class);
        $this->engine->shouldReceive('advancedSearch')->once()->andReturn(['hits' => [
            ['_id' => 1, '_score' => 9.9, '_highlight' => ['title' => ['<em>x</em>']]],
        ]]);

        $attributes = [];
        $m1 = Mockery::mock(Model::class);
        $m1->shouldReceive('getScoutKey')->andReturn(1);
        $m1->shouldReceive('syncOriginalAttribute')->withAnyArgs();
        $m1->shouldReceive('setAttribute')->withArgs(function ($key, $value) use (&$attributes) {
            $attributes[$key] = $value;

            return true;
        });
        $m1->shouldReceive('getAttribute')->andReturnUsing(function ($key) use (&$attributes) {
            return $attributes[$key] ?? null;
        });

        $model = $this->baseModelMock();
        $model->shouldReceive('getScoutModelsByIds')->once()->withArgs(function (Builder $b, array $ids) {
            return $ids === [1];
        })->andReturn(EloquentCollection::make([$m1]));

        $builder = new Builder($model, 'foo');
        $results = $builder->get();

        $this->assertCount(1, $results);
        $this->assertSame(9.9, $attributes['_score']);
        $this->assertSame(['title' => ['<em>x</em>']], $attributes['_highlight']);
    }

    public function testGetAppliesResultProcessorsInAdvancedMode(): void
    {
        $this->engine = Mockery::mock(\Erikwang2013\WebmanScout\Engines\AdvancedXunSearchEngine::class);
        $this->engine->shouldReceive('advancedSearch')->once()->andReturn(['hits' => []]);

        $model = $this->baseModelMock();
        $model->shouldReceive('getScoutModelsByIds')->never();
        $builder = new Builder($model, 'foo');
        $called = false;
        $builder->addResultProcessor(function ($results) use (&$called) {
            $called = true;

            return $results;
        });

        $builder->get();
        $this->assertTrue($called);
    }

    public function testRawDelegatesToAdvancedSearchOrSearch(): void
    {
        $this->engine = Mockery::mock(Engine::class);
        $this->engine->shouldReceive('search')->once()->andReturn(['results' => []]);
        $this->assertSame(['results' => []], $this->makeBuilder()->raw());

        $advanced = Mockery::mock(\Erikwang2013\WebmanScout\Engines\AdvancedXunSearchEngine::class);
        $advanced->shouldReceive('advancedSearch')->once()->andReturn(['hits' => []]);
        $model = Mockery::mock(Model::class);
        $model->shouldReceive('searchableUsing')->andReturn($advanced);
        $this->assertSame(['hits' => []], (new Builder($model, 'foo'))->raw());
    }

    public function testGetAggregationsAndFacetsFallbackToEmpty(): void
    {
        $this->engine = Mockery::mock(Engine::class);
        $builder = $this->makeBuilder();

        $this->assertSame([], $builder->getAggregations());
        $this->assertSame([], $builder->getFacets());
    }

    public function testGetAggregationsAndFacetsDelegateToAdvancedEngine(): void
    {
        $this->engine = Mockery::mock(AdvancedMeilisearchEngine::class);
        $this->engine->shouldReceive('getAggregations')->once()->andReturn(['price' => ['max' => 10]]);
        $this->engine->shouldReceive('getFacets')->once()->andReturn(['category' => ['a' => 2]]);
        $builder = $this->makeBuilder();

        $this->assertSame(['price' => ['max' => 10]], $builder->getAggregations());
        $this->assertSame(['category' => ['a' => 2]], $builder->getFacets());
    }

    public function testPaginateBuildsLengthAwarePaginator(): void
    {
        $this->engine = Mockery::mock(Engine::class);
        $raw = ['hits' => ['total' => 42], 'nbHits' => 42];
        $this->engine->shouldReceive('paginate')->once()->withArgs(function ($b, $perPage, $page) {
            return $b instanceof Builder && $perPage === 15 && $page === 1;
        })->andReturn($raw);
        $this->engine->shouldReceive('map')->once()->withAnyArgs()->andReturn(EloquentCollection::make([
            Mockery::mock(Model::class),
            Mockery::mock(Model::class),
        ]));
        $this->engine->shouldReceive('getTotalCount')->once()->with($raw)->andReturn(42);

        $paginator = $this->makeBuilder()->paginate();

        $this->assertInstanceOf(\Illuminate\Contracts\Pagination\LengthAwarePaginator::class, $paginator);
        $this->assertSame(42, $paginator->total());
        $this->assertSame(15, $paginator->perPage());
        $this->assertSame(1, $paginator->currentPage());
        $this->assertStringContainsString('query=foo', $paginator->url(1));
    }

    public function testPaginateHonoursPerPagePageAndPageName(): void
    {
        $this->engine = Mockery::mock(Engine::class);
        $raw = ['hits' => []];
        $this->engine->shouldReceive('paginate')->once()->withArgs(function ($b, $perPage, $page) {
            return $perPage === 10 && $page === 3;
        })->andReturn($raw);
        $this->engine->shouldReceive('map')->once()->withAnyArgs()->andReturn(EloquentCollection::make());
        $this->engine->shouldReceive('getTotalCount')->once()->with($raw)->andReturn(25);

        $paginator = $this->makeBuilder()->paginate(10, 'p', 3);

        $this->assertSame(10, $paginator->perPage());
        $this->assertSame(3, $paginator->currentPage());
        $this->assertSame(25, $paginator->total());
        $this->assertStringContainsString('query=foo', $paginator->url(3));
    }

    public function testSimplePaginateHasMorePagesWhenTotalExceedsWindow(): void
    {
        $this->engine = Mockery::mock(Engine::class);
        $raw = ['hits' => []];
        $this->engine->shouldReceive('paginate')->once()->andReturn($raw);
        $this->engine->shouldReceive('map')->once()->withAnyArgs()->andReturn(EloquentCollection::make());
        $this->engine->shouldReceive('getTotalCount')->once()->with($raw)->andReturn(42);

        $paginator = $this->makeBuilder()->simplePaginate();
        $this->assertTrue($paginator->hasMorePages());
        $this->assertStringContainsString('query=foo', $paginator->url(2));
    }

    public function testSimplePaginateHasNoMorePagesWhenTotalFitsWindow(): void
    {
        $this->engine = Mockery::mock(Engine::class);
        $raw = ['hits' => []];
        $this->engine->shouldReceive('paginate')->once()->andReturn($raw);
        $this->engine->shouldReceive('map')->once()->withAnyArgs()->andReturn(EloquentCollection::make());
        $this->engine->shouldReceive('getTotalCount')->once()->with($raw)->andReturn(10);

        $this->assertFalse($this->makeBuilder()->simplePaginate()->hasMorePages());
    }

    public function testPaginateTotalFallsBackToDatabaseCountWhenQueryCallbackSet(): void
    {
        $this->engine = Mockery::mock(Engine::class);
        $raw = ['hits' => []];
        $this->engine->shouldReceive('paginate')->once()->andReturn($raw);
        $this->engine->shouldReceive('map')->once()->withAnyArgs()->andReturn(EloquentCollection::make());
        $this->engine->shouldReceive('getTotalCount')->once()->with($raw)->andReturn(42);
        $this->engine->shouldReceive('mapIdsFrom')->once()->withArgs(function ($r, $key) {
            return $key === 'id';
        })->andReturn(SupportCollection::make([1, 2, 3]));
        $this->engine->shouldReceive('keys')->once()->withArgs(function (Builder $b) {
            return $b->limit === 42; // take(min(limit, total)) with limit null -> total
        })->andReturn(SupportCollection::make(range(1, 42)));

        $model = $this->baseModelMock();
        $model->shouldReceive('getScoutKeyName')->andReturn('id');

        $countQuery = Mockery::mock();
        $countQuery->shouldReceive('getCountForPagination')->once()->andReturn(30);
        $query = Mockery::mock();
        $query->shouldReceive('toBase')->once()->andReturn($countQuery);
        $model->shouldReceive('queryScoutModelsByIds')->once()->andReturn($query);

        $builder = new Builder($model, 'foo');
        $builder->query(function () {
            return null;
        });

        $paginator = $builder->paginate();
        $this->assertSame(30, $paginator->total());
    }

    public function testVectorSearchWithArrayVectorMergesDefaults(): void
    {
        $builder = $this->makeBuilder();
        $builder->vectorSearch([1.0, 2.0], 'embedding', ['threshold' => 0.9]);

        $this->assertSame([
            'vector' => [1.0, 2.0],
            'field' => 'embedding',
            'options' => ['metric' => 'cosine', 'top_k' => 10, 'threshold' => 0.9],
        ], $builder->getVectorSearch());
    }

    public function testVectorSearchWithFieldName(): void
    {
        $builder = $this->makeBuilder();
        $builder->vectorSearch('embedding', null, ['metric' => 'euclidean']);

        $this->assertSame([
            'field' => 'embedding',
            'options' => ['metric' => 'euclidean'],
        ], $builder->getVectorSearch());
    }

    public function testOrderByVectorSimilarityAppendsSort(): void
    {
        $builder = $this->makeBuilder();
        $builder->orderByVectorSimilarity([1.0], 'vec');

        $this->assertSame([
            ['type' => 'vector_similarity', 'vector' => [1.0], 'field' => 'vec'],
        ], $builder->getSorts());
    }

    public function testOrderByGeoDistanceAppendsSort(): void
    {
        $builder = $this->makeBuilder();
        $builder->orderByGeoDistance('location', 39.9, 116.4, 'desc');

        $this->assertSame([
            ['type' => 'geo_distance', 'field' => 'location', 'location' => ['lat' => 39.9, 'lng' => 116.4], 'direction' => 'desc'],
        ], $builder->getSorts());
    }

    public function testWhereAdvancedAppendsCondition(): void
    {
        $builder = $this->makeBuilder();
        $builder->whereAdvanced('title', 'contains', 'php', 'or', true);

        $this->assertSame([[
            'field' => 'title',
            'operator' => 'contains',
            'value' => 'php',
            'boolean' => 'or',
            'nested' => true,
        ]], $builder->getAdvancedWheres());
    }

    public function testWhereRangeAndGeoDistanceUseAdvanced(): void
    {
        $builder = $this->makeBuilder();
        $builder->whereRange('price', [1, 100], false);
        $builder->whereGeoDistance('location', 39.9, 116.4, 5000.0);

        $wheres = $builder->getAdvancedWheres();
        $this->assertSame('range', $wheres[0]['operator']);
        $this->assertSame(['range' => [1, 100], 'inclusive' => false], $wheres[0]['value']);
        $this->assertSame('geo_distance', $wheres[1]['operator']);
        $this->assertSame(['lat' => 39.9, 'lng' => 116.4, 'radius' => 5000.0], $wheres[1]['value']);
    }

    public function testFulltextSearchBuildsCondition(): void
    {
        $builder = $this->makeBuilder();
        $builder->fulltextSearch('hello world', ['title', 'body'], ['fuzziness' => '1']);

        $condition = $builder->getAdvancedWheres()[0];
        $this->assertSame('fulltext', $condition['type']);
        $this->assertSame('hello world', $condition['query']);
        $this->assertSame(['title', 'body'], $condition['fields']);
        $this->assertSame(['operator' => 'and', 'fuzziness' => '1', 'boost' => 1.0], $condition['options']);
    }

    public function testFulltextSearchDefaultsToEmptyFieldsWithoutSearchableFieldsMethod(): void
    {
        $builder = $this->makeBuilder();
        $builder->fulltextSearch('x');

        $this->assertSame([], $builder->getAdvancedWheres()[0]['fields']);
    }

    public function testAggregateAndFacetConfig(): void
    {
        $builder = $this->makeBuilder();
        $builder->aggregate('max_price', 'max', 'price', ['missing' => 0]);
        $builder->facet('category', ['size' => 10]);

        $this->assertSame(['max_price' => ['type' => 'max', 'field' => 'price', 'options' => ['missing' => 0]]], $builder->getAggregationConfig());
        $this->assertSame(['category' => ['size' => 10]], $builder->getFacetConfig());
    }

    public function testClearAdvancedConditionsResetsEverything(): void
    {
        $builder = $this->makeBuilder();
        $builder->vectorSearch([1.0], 'vec')
            ->whereAdvanced('a', '=', 1)
            ->orderBy('b')
            ->aggregate('c', 'sum', 'c')
            ->facet('d')
            ->addResultProcessor(function ($r) {
                return $r;
            });

        $this->assertSame($builder, $builder->clearAdvancedConditions());
        $this->assertSame([], $builder->getVectorSearch());
        $this->assertSame([], $builder->getAdvancedWheres());
        $this->assertSame([], $builder->getSorts());
        $this->assertSame([], $builder->getAggregationConfig());
        $this->assertSame([], $builder->getFacetConfig());
        $this->assertSame([], $builder->getResultProcessors());
    }

    public function testModelConnectionType(): void
    {
        $connection = Mockery::mock();
        $connection->shouldReceive('getDriverName')->once()->andReturn('mysql');
        $model = $this->baseModelMock();
        $model->shouldReceive('getConnection')->once()->andReturn($connection);

        $this->assertSame('mysql', (new Builder($model, 'foo'))->modelConnectionType());
    }

    public function testMacroableCallForwardsToRegisteredMacro(): void
    {
        Builder::macro('multiply', function (int $n) {
            return $n * 2;
        });

        $this->assertSame(42, $this->makeBuilder()->multiply(21));
    }

    public function testUnknownCallThrowsBadMethodCallException(): void
    {
        $this->expectException(BadMethodCallException::class);
        $this->makeBuilder()->thisMethodDoesNotExist();
    }

    public function testConditionableWhenAppliesCallback(): void
    {
        $builder = $this->makeBuilder();
        $builder->when(true, function (Builder $b) {
            return $b->take(5);
        });
        $this->assertSame(5, $builder->limit);

        $builder->when(false, function (Builder $b) {
            return $b->take(99);
        });
        $this->assertSame(5, $builder->limit);
    }

    public function testDebugReturnsState(): void
    {
        $builder = $this->makeBuilder();
        $builder->where('status', 1)->take(3)->within('idx');

        $debug = $builder->debug();
        $this->assertSame('foo', $debug['query']);
        $this->assertSame('idx', $debug['index']);
        $this->assertSame(['status' => 1], $debug['wheres']);
        $this->assertSame(3, $debug['limit']);
        $this->assertArrayHasKey('sorts', $debug);
        $this->assertArrayHasKey('vectorSearch', $debug);
        $this->assertArrayHasKey('options', $debug);
    }
}
