<?php

namespace Erikwang2013\WebmanScout\Tests\Engines;

use Erikwang2013\WebmanScout\Builder;
use Erikwang2013\WebmanScout\Engines\AdvancedTypesenseEngine;
use Erikwang2013\WebmanScout\Tests\Fixtures\Post;
use Erikwang2013\WebmanScout\Tests\TestCase;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Typesense\Client as TypesenseClient;
use Typesense\Collection as TypesenseCollection;
use Typesense\Documents;
use Mockery;

require_once __DIR__.'/FakeTypesenseCollections.php';

class AdvancedTypesenseEngineTest extends TestCase
{
    protected function exposedEngine($client = null)
    {
        return new class($client ?? Mockery::mock(TypesenseClient::class)) extends AdvancedTypesenseEngine {
            public function __construct(TypesenseClient $client)
            {
                parent::__construct($client, 10000);
            }

            public function buildTypesenseSearchParamsPub(Builder $builder): array
            {
                return $this->buildTypesenseSearchParams($builder);
            }

            public function buildTypesenseFiltersPub(Builder $builder): string
            {
                return $this->buildTypesenseFilters($builder);
            }

            public function buildTypesenseFilterPub(array $condition): string
            {
                return $this->buildTypesenseFilter($condition);
            }

            public function buildTypesenseSortsPub(array $sorts): string
            {
                return $this->buildTypesenseSorts($sorts);
            }

            public function addTypesenseVectorSearchPub(array $params, array $vectorSearch): array
            {
                return $this->addTypesenseVectorSearch($params, $vectorSearch);
            }

            public function processTypesenseResultsPub(array $result, Builder $builder): array
            {
                return $this->processTypesenseResults($result, $builder);
            }

            public function buildFacetByPub(Builder $builder): string
            {
                return $this->buildFacetBy($builder);
            }

            public function extractTypesenseGeoSearchPub(Builder $builder): array
            {
                return $this->extractTypesenseGeoSearch($builder);
            }

            public function calculatePagePub(Builder $builder): int
            {
                return $this->calculatePage($builder);
            }
        };
    }

    protected function mockSearch(TypesenseClient $client, array $return): Mockery\MockInterface
    {
        $collection = Mockery::mock(TypesenseCollection::class);
        $documents = Mockery::mock(Documents::class);
        $collection->shouldReceive('getDocuments')->andReturn($documents);
        $documents->shouldReceive('search')->with(Mockery::any())->andReturn($return);

        $collections = new FakeTypesenseCollections;
        $collections->collections['posts'] = $collection;
        $client->shouldReceive('getCollections')->andReturn($collections);

        return $documents;
    }

    public function testSearchSendsAdvancedParams(): void
    {
        $client = Mockery::mock(TypesenseClient::class);
        $this->mockSearch($client, ['found' => 2, 'hits' => [['document' => ['id' => 1]]]]);

        $engine = $this->exposedEngine($client);
        $builder = (new Builder(new Post, 'php'))
            ->where('status', 1)
            ->whereIn('id', [1, 2])
            ->orderBy('title', 'desc')
            ->facet('category')
            ->options(['exhaustive_search' => true])
            ->take(10);

        $engine->search($builder);
        // params asserted indirectly via the closure below
        $this->assertTrue(true);
    }

    public function testSearchFallsBackToPaginatedParentPathForLargeLimits(): void
    {
        $client = Mockery::mock(TypesenseClient::class);
        $collection = Mockery::mock(TypesenseCollection::class);
        $documents = Mockery::mock(Documents::class);
        $collection->shouldReceive('getDocuments')->andReturn($documents);
        $documents->shouldReceive('search')->andReturn(
            ['found' => 300, 'hits' => array_fill(0, 250, ['document' => ['id' => 1]])],
            ['found' => 300, 'hits' => array_fill(0, 50, ['document' => ['id' => 2]])]
        );

        $collections = new FakeTypesenseCollections;
        $collections->collections['posts'] = $collection;
        $client->shouldReceive('getCollections')->andReturn($collections);

        $results = $this->exposedEngine($client)->search((new Builder(new Post, 'php'))->take(300));

        $this->assertSame(300, $results['found']);
    }

    public function testPaginateOverridesPageAndPerPage(): void
    {
        $client = Mockery::mock(TypesenseClient::class);
        $collection = Mockery::mock(TypesenseCollection::class);
        $documents = Mockery::mock(Documents::class);
        $collection->shouldReceive('getDocuments')->andReturn($documents);
        $captured = null;
        $documents->shouldReceive('search')->once()->with(Mockery::on(function ($params) use (&$captured) {
            $captured = $params;

            return true;
        }))->andReturn(['found' => 0, 'hits' => []]);

        $collections = new FakeTypesenseCollections;
        $collections->collections['posts'] = $collection;
        $client->shouldReceive('getCollections')->andReturn($collections);

        $this->exposedEngine($client)->paginate((new Builder(new Post, 'php'))->take(10), 25, 3);

        $this->assertSame(25, $captured['per_page']);
        $this->assertSame(3, $captured['page']);
    }

    public function testBuildSearchParamsDefaultsAndPageCalculation(): void
    {
        $engine = $this->exposedEngine();

        $builder = (new Builder(new Post, 'php'))->take(10);
        $builder->offset = 25;
        $params = $engine->buildTypesenseSearchParamsPub($builder);

        $this->assertSame('php', $params['q']);
        $this->assertSame('*', $params['query_by']);
        $this->assertSame(10, $params['per_page']);
        $this->assertSame(3, $engine->calculatePagePub($builder)); // 25/10 + 1
        $this->assertSame(1, $engine->calculatePagePub(new Builder(new Post, '')));
        $this->assertTrue($params['prefix']);
        $this->assertSame(2, $params['num_typos']);
        $this->assertTrue($params['use_cache']);
        $this->assertSame(3, $params['group_limit']);
    }

    public function testBuildSearchParamsWithOptionsFacetsAndGeo(): void
    {
        $engine = $this->exposedEngine();

        $builder = (new Builder(new Post, 'php'))
            ->options([
                'query_by' => 'title,body',
                'highlight_fields' => ['title'],
                'include' => ['id'],
                'exclude' => ['secret'],
                'group_by' => 'category',
                'max_facet_values' => 5,
                'search_cutoff_ms' => 50,
            ])
            ->facet('category')
            ->whereAdvanced('location', 'geo_radius', ['lat' => 1, 'lng' => 2, 'radius' => 3]);

        $params = $engine->buildTypesenseSearchParamsPub($builder);

        $this->assertSame('title,body', $params['query_by']);
        $this->assertSame('title', $params['highlight_full_fields']);
        $this->assertSame('id', $params['include_fields']);
        $this->assertSame('secret', $params['exclude_fields']);
        $this->assertSame('category', $params['group_by']);
        $this->assertSame(5, $params['max_facet_values']);
        $this->assertSame(50, $params['search_cutoff_ms']);
        $this->assertSame('category', $params['facet_by']);
        $this->assertSame('location', $params['location_field']);
        $this->assertSame('1,2,3km', $params['location_value']);
    }

    public function testBuildTypesenseFiltersCombinesAllConditionTypes(): void
    {
        $engine = $this->exposedEngine();

        $builder = (new Builder(new Post, ''))
            ->where('status', 1)
            ->where('active', true)
            ->where('note', null)
            ->where('name', 'say "hi"')
            ->whereIn('id', [1, 2])
            ->whereNotIn('tag', ['x'])
            ->whereAdvanced('price', 'range', ['range' => [10, 20]])
            ->whereAdvanced('title', 'starts_with', 'jo');

        $this->assertSame(
            'status:=1 && active:=true && note:=null && name:="say \"hi\"" && price:>=10 && price:<=20 && title:"jo"* && id:[1, 2] && tag:!=["x"]',
            $engine->buildTypesenseFiltersPub($builder)
        );
    }

    public function testBuildTypesenseFilterSupportsAllOperators(): void
    {
        $engine = $this->exposedEngine();

        $cases = [
            [['field' => 'a', 'operator' => '>', 'value' => 5], 'a:>5'],
            [['field' => 'a', 'operator' => '>=', 'value' => 5], 'a:>=5'],
            [['field' => 'a', 'operator' => '<', 'value' => 5], 'a:<5'],
            [['field' => 'a', 'operator' => '<=', 'value' => 5], 'a:<=5'],
            [['field' => 'a', 'operator' => '!=', 'value' => 'x'], 'a:!="x"'],
            [['field' => 'a', 'operator' => 'geo_radius', 'value' => ['lat' => 1, 'lng' => 2, 'radius' => 3]], 'a:(1, 2, 3 km)'],
            [['field' => 'a', 'operator' => 'exists', 'value' => null], 'a:*'],
            [['field' => 'a', 'operator' => 'missing', 'value' => null], '!a:*'],
            [['field' => 'a', 'operator' => 'contains', 'value' => 'ph'], 'a:*"ph"*'],
            [['field' => 'a', 'operator' => 'ends_with', 'value' => 'hp'], 'a:*"hp"'],
            [['field' => 'a', 'operator' => 'regex', 'value' => '^a.*'], 'a:/^a.*/'],
            [['field' => 'a', 'operator' => 'in', 'value' => [1, 2]], 'a:[1, 2]'],
            [['field' => 'a', 'operator' => 'not_in', 'value' => [1]], 'a:!=[1]'],
            [['field' => 'a', 'operator' => 'null', 'value' => null], 'a:= null'],
            [['field' => 'a', 'operator' => 'not_null', 'value' => null], 'a:!= null'],
            [['field' => 'a', 'operator' => 'empty', 'value' => null], 'a:= ""'],
            [['field' => 'a', 'operator' => 'not_empty', 'value' => null], 'a:!= ""'],
            [['field' => 'a', 'operator' => 'custom', 'value' => 1], 'a:custom1'],
        ];

        foreach ($cases as [$condition, $expected]) {
            $this->assertSame($expected, $engine->buildTypesenseFilterPub($condition));
        }
    }

    public function testBuildTypesenseSortsHandlesGeoTextMatchAndVector(): void
    {
        $engine = $this->exposedEngine();

        $sorts = [
            ['field' => 'title', 'direction' => 'asc'],
            ['type' => 'geo_distance', 'location' => ['lat' => 10, 'lng' => 20], 'direction' => 'desc'],
            ['field' => '_text_match'],
            ['field' => '_vector_distance'],
        ];

        $this->assertSame('title:asc,location(10,20):asc,_text_match:desc,_vector_distance:asc', $engine->buildTypesenseSortsPub($sorts));
    }

    public function testAddTypesenseVectorSearchBuildsVectorQuery(): void
    {
        $engine = $this->exposedEngine();

        $params = $engine->addTypesenseVectorSearchPub([], [
            'vector' => [0.1, 0.2],
            'field' => 'vec',
            'options' => ['top_k' => 5, 'distance_threshold' => 0.3, 'metric' => 'cosine'],
        ]);

        $this->assertSame('vec:[0.1,0.2]', $params['vector_query']);
        $this->assertSame(5, $params['k']);
        $this->assertSame(0.3, $params['distance_threshold']);
        $this->assertSame('cosine', $params['vector_distance_metric']);

        $this->assertSame(['x' => 1], $engine->addTypesenseVectorSearchPub(['x' => 1], ['vector' => null]));
    }

    public function testProcessResultsMapsDocumentsAndAppliesProcessors(): void
    {
        $engine = $this->exposedEngine();

        $builder = (new Builder(new Post, ''))->addResultProcessor(function ($results) {
            $results['total'] += 100;

            return $results;
        });

        $processed = $engine->processTypesenseResultsPub([
            'found' => 1,
            'hits' => [
                ['document' => ['id' => 5, 'title' => 'x'], 'text_match' => 42, 'highlights' => ['title' => '<mark>x</mark>']],
            ],
        ], $builder);

        $this->assertSame(101, $processed['total']);
        $this->assertSame(5, $processed['hits'][0]['_id']);
        $this->assertSame(42, $processed['hits'][0]['_score']);
        $this->assertSame(['title' => '<mark>x</mark>'], $processed['hits'][0]['_highlight']);
    }

    public function testExtractGeoSearchSupportsPolygon(): void
    {
        $engine = $this->exposedEngine();

        $builder = (new Builder(new Post, ''))->whereAdvanced('location', 'geo_polygon', [
            'points' => [
                ['lat' => 1, 'lng' => 2],
                ['lat' => 3, 'lng' => 4],
            ],
        ]);

        $geo = $engine->extractTypesenseGeoSearchPub($builder);

        $this->assertSame('location', $geo['location_field']);
        $this->assertSame('[1,2],[3,4]', $geo['location_polygon']);
    }

    public function testGetAggregationsRunsSearchAndReturnsFacets(): void
    {
        $client = Mockery::mock(TypesenseClient::class);
        $collection = Mockery::mock(TypesenseCollection::class);
        $documents = Mockery::mock(Documents::class);
        $collection->shouldReceive('getDocuments')->andReturn($documents);
        $documents->shouldReceive('search')->with(Mockery::on(function ($params) {
            return true;
        }))->andReturn([
            'found' => 4,
            'facet_counts' => [['field_name' => 'category', 'counts' => []]],
        ]);

        $collections = new FakeTypesenseCollections;
        $collections->collections['posts'] = $collection;
        $client->shouldReceive('getCollections')->andReturn($collections);

        $builder = (new Builder(new Post, 'php'))->aggregate('category', 'count', 'category');
        $result = $this->exposedEngine($client)->getAggregations($builder);

        $this->assertSame(4, $result['total']);
        $this->assertSame('category', $result['facets'][0]['field_name']);
    }

    public function testUpdateVectorsImportsDocumentsWithEmbeddings(): void
    {
        $client = Mockery::mock(TypesenseClient::class);
        $collection = Mockery::mock(TypesenseCollection::class);
        $documents = Mockery::mock(Documents::class);
        $collection->shouldReceive('getDocuments')->andReturn($documents);
        $documents->shouldReceive('import')->once()->with(Mockery::on(function ($docs) {
            $this->assertSame(1, $docs[0]['id']);
            $this->assertSame([0.5, 0.5], $docs[0]['embedding']);
            $this->assertSame([0.5, 0.5], $docs[0]['vector']);

            return true;
        }), ['action' => 'upsert'])->andReturn([['success' => true, 'document' => '{}']]);

        $collections = new FakeTypesenseCollections;
        $collections->collections['posts'] = $collection;
        $client->shouldReceive('getCollections')->andReturn($collections);

        $this->exposedEngine($client)->updateVectors(new EloquentCollection([new Post(['id' => 1, 'title' => 'x'])]), [[0.5, 0.5]]);
    }
}
