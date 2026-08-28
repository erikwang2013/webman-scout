<?php

namespace Erikwang2013\WebmanScout\Tests\Engines;

require_once __DIR__.'/../ClientStubs.php';

use Erikwang2013\WebmanScout\Builder;
use Erikwang2013\WebmanScout\Engines\AdvancedOpenSearchEngine;
use Erikwang2013\WebmanScout\Tests\Fixtures\Post;
use Erikwang2013\WebmanScout\Tests\TestCase;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\Log;
use OpenSearch\Client as OpenSearchClient;
use Mockery;

class AdvancedOpenSearchEngineTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Log::swap(Mockery::mock(\Psr\Log\LoggerInterface::class)->shouldIgnoreMissing());
    }

    protected function tearDown(): void
    {
        Log::clearResolvedInstance('log');
        parent::tearDown();
    }

    protected function exposedEngine($client = null)
    {
        return new class($client ?? Mockery::mock(OpenSearchClient::class)) extends AdvancedOpenSearchEngine {
            public function buildAdvancedSearchParamsPub(Builder $builder): array
            {
                return $this->buildAdvancedSearchParams($builder);
            }

            public function buildAdvancedQueryPub(Builder $builder): array
            {
                return $this->buildAdvancedQuery($builder);
            }

            public function buildAdvancedConditionPub(array $condition): ?array
            {
                return $this->buildAdvancedCondition($condition);
            }

            public function coerceTermValuePub($value)
            {
                return $this->coerceTermValue($value);
            }

            public function buildAdvancedSortsPub(array $sorts): array
            {
                return $this->buildAdvancedSorts($sorts);
            }

            public function buildAggregationsPub(array $aggregations): array
            {
                return $this->buildAggregations($aggregations);
            }

            public function buildFacetsPub(array $facets): array
            {
                return $this->buildFacets($facets);
            }

            public function buildHighlightPub(Builder $builder): array
            {
                return $this->buildHighlight($builder);
            }

            public function buildSuggestPub(Builder $builder): array
            {
                return $this->buildSuggest($builder);
            }

            public function processAdvancedResultsPub(array $result, Builder $builder): array
            {
                return $this->processAdvancedResults($result, $builder);
            }
        };
    }

    public function testKnnSearchSendsQueryAndMapsResults(): void
    {
        $client = Mockery::mock(OpenSearchClient::class);
        $client->shouldReceive('search')->once()->with([
            'index' => 'posts',
            'body' => [
                'query' => ['knn' => ['vector' => ['vector' => [0.1, 0.2], 'k' => 10]]],
                'size' => 10,
            ],
        ])->andReturn([
            'hits' => ['hits' => [
                ['_id' => '1', '_score' => 0.9, '_source' => ['id' => 1]],
            ]],
        ]);

        $results = $this->exposedEngine($client)->knnSearch('posts', [0.1, 0.2]);

        $this->assertSame([['id' => '1', 'score' => 0.9, 'source' => ['id' => 1]]], $results);
    }

    public function testKnnSearchWithFilterAndCustomK(): void
    {
        $client = Mockery::mock(OpenSearchClient::class);
        $client->shouldReceive('search')->once()->with(Mockery::on(function ($params) {
            $this->assertSame(5, $params['body']['size']);
            $this->assertSame(['term' => ['status' => 1]], $params['body']['query']['knn']['vector']['filter']);
            $this->assertSame([0.5], $params['body']['query']['knn']['vector']['vector']);

            return true;
        }))->andReturn(['hits' => ['hits' => []]]);

        $this->exposedEngine($client)->knnSearch('posts', [0.5], 5, ['term' => ['status' => 1]]);
    }

    public function testHybridSearchWeightsKeywordAndVector(): void
    {
        $client = Mockery::mock(OpenSearchClient::class);
        $client->shouldReceive('search')->once()->with(Mockery::on(function ($params) {
            $this->assertSame('posts', $params['index']);
            $this->assertSame(10, $params['body']['size']);
            $should = $params['body']['query']['bool']['should'];
            $this->assertSame(0.5, $should[0]['function_score']['weight']);
            $this->assertSame(0.5, $should[1]['function_score']['weight']);
            $this->assertSame('php', $should[0]['function_score']['query']['multi_match']['query']);
            $this->assertSame([0.1], $should[1]['function_score']['query']['knn']['vector']['vector']);
            $this->assertSame(1, $params['body']['query']['bool']['minimum_should_match']);

            return true;
        }))->andReturn(['hits' => ['hits' => []]]);

        $this->exposedEngine($client)->hybridSearch('posts', 'php', [0.1], 0.5, 10);
    }

    public function testAdvancedSearchBuildsFullParamsAndProcessesResults(): void
    {
        $client = Mockery::mock(OpenSearchClient::class);
        $client->shouldReceive('search')->once()->with(Mockery::on(function ($params) {
            $must = $params['body']['query']['bool']['must'];
            $this->assertSame('php', $must[0]['multi_match']['query']);
            $this->assertSame(['title', 'body'], $must[0]['multi_match']['fields']);
            $this->assertSame([['term' => ['status' => 1]]], $params['body']['query']['bool']['filter']);
            $this->assertSame(['title' => ['order' => 'desc', 'missing' => '_last', 'mode' => 'min']], $params['body']['sort']);
            $this->assertSame(10, $params['body']['size']);
            $this->assertSame(['id', 'title'], $params['body']['_source']);
            $this->assertArrayHasKey('title', $params['body']['highlight']['fields']);
            $this->assertSame(['facet_category'], array_keys($params['body']['aggs']));

            return true;
        }))->andReturn([
            'hits' => [
                'total' => ['value' => 2],
                'max_score' => 1.0,
                'hits' => [
                    ['_id' => '1', '_score' => 0.9, '_source' => ['id' => 1], 'highlight' => ['title' => ['<mark>php</mark>']]],
                ],
            ],
            'aggregations' => ['category' => ['buckets' => []]],
            'took' => 5,
        ]);

        $builder = (new Builder(new Post, 'php'))
            ->where('status', 1)
            ->orderBy('title', 'desc')
            ->facet('category')
            ->options(['fields' => 'title,body', '_source' => 'id,title'])
            ->take(10);

        $processed = $this->exposedEngine($client)->advancedSearch($builder);

        $this->assertSame(2, $processed['total']);
        $this->assertSame('1', $processed['hits'][0]['_id']);
        $this->assertSame(0.9, $processed['hits'][0]['_score']);
        $this->assertSame(['title' => ['<mark>php</mark>']], $processed['hits'][0]['_highlight']);
        $this->assertSame(['category' => ['buckets' => []]], $processed['aggregations']);
        $this->assertSame(5, $processed['took']);
    }

    public function testAdvancedSearchInvokesCallback(): void
    {
        $client = Mockery::mock(OpenSearchClient::class);
        $client->shouldNotReceive('search');

        $engine = $this->exposedEngine($client);
        $builder = new Builder(new Post, 'php');
        $callbackRan = false;
        $builder->callback = function ($clientArg, $builderArg, $params) use ($client, $builder, &$callbackRan) {
            $this->assertSame($client, $clientArg);
            $this->assertSame($builder, $builderArg);
            $this->assertSame('posts', $params['index']);
            $callbackRan = true;

            return ['hits' => [], 'total' => 0];
        };

        $engine->advancedSearch($builder);
        $this->assertTrue($callbackRan);
    }

    public function testBuildAdvancedQueryEmptyAndCoercedWheres(): void
    {
        $engine = $this->exposedEngine();

        $this->assertInstanceOf(\stdClass::class, $engine->buildAdvancedQueryPub(new Builder(new Post, ''))['match_all']);

        $builder = (new Builder(new Post, 'php'))
            ->where('active', 'false')
            ->where('enabled', true)
            ->where('count', '12.5')
            ->where('name', 'php')
            ->where('tags', ['a', 'b']);

        $query = $engine->buildAdvancedQueryPub($builder);

        $this->assertSame(0, $query['bool']['filter'][0]['term']['active']);
        $this->assertSame(1, $query['bool']['filter'][1]['term']['enabled']);
        $this->assertSame(12.5, $query['bool']['filter'][2]['term']['count']);
        $this->assertSame('php', $query['bool']['filter'][3]['term']['name']);
        $this->assertSame(['a', 'b'], $query['bool']['filter'][4]['terms']['tags']);
        $this->assertSame(0, $engine->coerceTermValuePub('false'));
        $this->assertSame(1, $engine->coerceTermValuePub(true));
        $this->assertSame(7, $engine->coerceTermValuePub('7'));
        $this->assertSame('x', $engine->coerceTermValuePub('x'));
        $this->assertNull($engine->coerceTermValuePub(null));
    }

    public function testBuildAdvancedQueryMapsAdvancedWheres(): void
    {
        $engine = $this->exposedEngine();

        $builder = (new Builder(new Post, ''))
            ->whereAdvanced('price', 'range', ['range' => [10, 20]])
            ->whereAdvanced('name', 'prefix', 'jo', 'or')
            ->whereAdvanced('deleted', 'exists', null, 'not')
            ->whereIn('id', [1, 2])
            ->whereNotIn('tag', ['x']);

        $query = $engine->buildAdvancedQueryPub($builder);

        $this->assertSame(['gte' => 10, 'lte' => 20], $query['bool']['filter'][0]['range']['price']);
        $this->assertSame('jo', $query['bool']['should'][0]['prefix']['name']['value']);
        $this->assertSame('deleted', $query['bool']['must_not'][0]['exists']['field']);
        $this->assertSame([1, 2], $query['bool']['filter'][1]['terms']['id']);
        $this->assertSame(['x'], $query['bool']['must_not'][1]['terms']['tag']);
    }

    public function testBuildAdvancedConditionSupportsAllOperators(): void
    {
        $engine = $this->exposedEngine();

        $this->assertSame(['gt' => 5], $engine->buildAdvancedConditionPub(['field' => 'a', 'operator' => '>', 'value' => 5])['range']['a']);
        $this->assertSame(['gte' => 5], $engine->buildAdvancedConditionPub(['field' => 'a', 'operator' => '>=', 'value' => 5])['range']['a']);
        $this->assertSame(['lt' => 5], $engine->buildAdvancedConditionPub(['field' => 'a', 'operator' => '<', 'value' => 5])['range']['a']);
        $this->assertSame(['lte' => 5], $engine->buildAdvancedConditionPub(['field' => 'a', 'operator' => '<=', 'value' => 5])['range']['a']);

        $dateRange = $engine->buildAdvancedConditionPub(['field' => 'a', 'operator' => 'date_range', 'value' => ['range' => ['2024-01-01', '2024-12-31']]]);
        $this->assertSame('2024-01-01', $dateRange['range']['a']['gte']);
        $this->assertSame('yyyy-MM-dd HH:mm:ss', $dateRange['range']['a']['format']);

        $geo = $engine->buildAdvancedConditionPub(['field' => 'location', 'operator' => 'geo_distance', 'value' => ['lat' => 1, 'lng' => 2, 'radius' => 3]]);
        $this->assertSame('3km', $geo['geo_distance']['distance']);
        $this->assertSame(['lat' => 1, 'lon' => 2], $geo['geo_distance']['location']);

        $bbox = $engine->buildAdvancedConditionPub(['field' => 'location', 'operator' => 'geo_bounding_box', 'value' => ['top_left' => 'a', 'bottom_right' => 'b']]);
        $this->assertSame(['top_left' => 'a', 'bottom_right' => 'b'], $bbox['geo_bounding_box']['location']);

        $this->assertSame('a', $engine->buildAdvancedConditionPub(['field' => 'a', 'operator' => 'exists', 'value' => null])['exists']['field']);
        $this->assertSame('a', $engine->buildAdvancedConditionPub(['field' => 'a', 'operator' => 'missing', 'value' => null])['bool']['must_not'][0]['exists']['field']);

        $this->assertSame('ph*', $engine->buildAdvancedConditionPub(['field' => 'a', 'operator' => 'wildcard', 'value' => 'ph*'])['wildcard']['a']['value']);
        $this->assertSame('^a.*', $engine->buildAdvancedConditionPub(['field' => 'a', 'operator' => 'regexp', 'value' => '^a.*'])['regexp']['a']['value']);
        $this->assertSame('jo', $engine->buildAdvancedConditionPub(['field' => 'a', 'operator' => 'prefix', 'value' => 'jo'])['prefix']['a']['value']);

        $match = $engine->buildAdvancedConditionPub(['field' => 'a', 'operator' => 'match', 'value' => 'php', 'options' => ['operator' => 'and']]);
        $this->assertSame('and', $match['match']['a']['operator']);
        $this->assertSame('php', $engine->buildAdvancedConditionPub(['field' => 'a', 'operator' => 'match_phrase', 'value' => 'php'])['match_phrase']['a']['query']);

        $default = $engine->buildAdvancedConditionPub(['field' => 'a', 'operator' => 'eq', 'value' => 1]);
        $this->assertSame(['value' => 1, 'boost' => 1.0], $default['term']['a']);

        // Invalid range returns null (skipped)
        $this->assertNull($engine->buildAdvancedConditionPub(['field' => 'a', 'operator' => 'range', 'value' => ['range' => [null, null]]]));
        $this->assertNull($engine->buildAdvancedConditionPub(['field' => 'a', 'operator' => 'range', 'value' => ['range' => [false, 'false']]]));
    }

    public function testBuildAdvancedSortsHandlesAllTypes(): void
    {
        $engine = $this->exposedEngine();

        $sorts = [
            ['field' => 'title', 'direction' => 'desc'],
            ['type' => 'geo_distance', 'field' => 'location', 'location' => ['lat' => 10, 'lng' => 20], 'direction' => 'desc'],
            ['type' => 'random'],
            ['type' => 'vector_similarity'],
        ];

        $this->assertSame([
            'title' => ['order' => 'desc', 'missing' => '_last', 'mode' => 'min'],
            ['_geo_distance' => ['location' => ['lat' => 10, 'lon' => 20], 'order' => 'desc', 'unit' => 'km', 'distance_type' => 'plane']],
            ['_script' => ['type' => 'number', 'script' => 'Math.random()', 'order' => 'asc']],
            ['_score' => ['order' => 'desc']],
        ], $engine->buildAdvancedSortsPub($sorts));
    }

    public function testBuildAggregationsAndFacets(): void
    {
        $engine = $this->exposedEngine();

        $aggs = $engine->buildAggregationsPub([
            'by_category' => ['type' => 'terms', 'field' => 'category', 'options' => ['size' => 5]],
            'price_stats' => ['type' => 'stats', 'field' => 'price'],
            'cardinality' => ['type' => 'cardinality', 'field' => 'id'],
            'unknown' => ['type' => 'nope', 'field' => 'x'],
        ]);

        $this->assertSame(5, $aggs['by_category']['terms']['size']);
        $this->assertSame('price', $aggs['price_stats']['stats']['field']);
        $this->assertSame('id', $aggs['cardinality']['cardinality']['field']);
        $this->assertArrayNotHasKey('unknown', $aggs);

        $facets = $engine->buildFacetsPub(['category' => ['size' => 3]]);
        $this->assertSame('category', $facets['facet_category']['terms']['field']);
        $this->assertSame(3, $facets['facet_category']['terms']['size']);
    }

    public function testBuildHighlightAndSuggest(): void
    {
        $engine = $this->exposedEngine();

        $highlight = $engine->buildHighlightPub((new Builder(new Post, 'php'))->options(['fields' => 'title,body']));
        $this->assertArrayHasKey('title', $highlight['fields']);
        $this->assertSame(['<mark>'], $highlight['pre_tags']);

        $suggest = $engine->buildSuggestPub((new Builder(new Post, 'php'))->options(['suggest_fields' => ['title'], 'suggest_size' => 3]));
        $this->assertSame('php', $suggest['suggest_title']['text']);
        $this->assertSame(3, $suggest['suggest_title']['term']['size']);
    }

    public function testSearchAndPaginateDispatchOnBuilderType(): void
    {
        $client = Mockery::mock(OpenSearchClient::class);
        $client->shouldReceive('search')->once()->with(Mockery::on(function ($params) {
            $this->assertSame(20, $params['body']['size']);
            $this->assertSame(40, $params['body']['from']);

            return true;
        }))->andReturn(['hits' => ['total' => ['value' => 0], 'hits' => []]]);

        $engine = $this->exposedEngine($client);

        // paginate() maps perPage/page to limit/offset (from = 40, size = 20)
        $engine->paginate((new Builder(new Post, ''))->take(20), 20, 3);

        // search() uses builder limit; from defaults to 0 (offset is null)
        $client->shouldReceive('search')->once()->with(Mockery::on(function ($params) {
            $this->assertSame(10, $params['body']['size']);
            $this->assertSame(0, $params['body']['from']);

            return true;
        }))->andReturn(['hits' => ['total' => ['value' => 1], 'hits' => [['_id' => '1']]]]);

        $engine->search((new Builder(new Post, 'foo'))->take(10));
    }

    public function testGetTotalCountPrefersProcessedTotal(): void
    {
        $engine = $this->exposedEngine();

        $this->assertSame(7, $engine->getTotalCount(['total' => 7, 'hits' => ['total' => ['value' => 99]]]));
        $this->assertSame(4, $engine->getTotalCount(['hits' => ['total' => ['value' => 4]]]));
    }

    public function testGetAggregationsUnsetsSizeAndFrom(): void
    {
        $client = Mockery::mock(OpenSearchClient::class);
        $client->shouldReceive('search')->twice()->with(Mockery::on(function ($params) {
            $this->assertArrayNotHasKey('size', $params['body']);
            $this->assertArrayNotHasKey('from', $params['body']);
            $this->assertArrayHasKey('aggs', $params['body']);

            return true;
        }))->andReturn(['aggregations' => ['category' => ['buckets' => []]]]);

        $builder = (new Builder(new Post, ''))->aggregate('by_category', 'terms', 'category');
        $this->assertSame(['category' => ['buckets' => []]], $this->exposedEngine($client)->getAggregations($builder));
        $this->assertSame(['category' => ['buckets' => []]], $this->exposedEngine($client)->getFacets($builder));
    }

    public function testUpdateVectorsSendsBulkUpdateWithTimestamp(): void
    {
        $client = Mockery::mock(OpenSearchClient::class);
        $client->shouldReceive('bulk')->once()->with(Mockery::on(function ($params) {
            $this->assertSame('update', $params['body'][0]['update']['_index'] === 'posts' ? 'update' : 'update');
            $this->assertSame(['update' => ['_index' => 'posts', '_id' => 1]], $params['body'][0]);
            $this->assertSame([0.5, 0.5], $params['body'][1]['doc']['vector']);
            $this->assertTrue($params['body'][1]['doc_as_upsert']);

            return true;
        }))->andReturn(['errors' => false]);

        $this->exposedEngine($client)->updateVectors(new EloquentCollection([new Post(['id' => 1])]), [[0.5, 0.5]]);
    }

    public function testCreateVectorIndexMergesSettingsAndMappings(): void
    {
        $client = Mockery::mock(OpenSearchClient::class);
        $indices = Mockery::mock();
        $client->shouldReceive('indices')->times(2)->andReturn($indices);
        $indices->shouldReceive('exists')->once()->with(['index' => 'posts'])->andReturn(Mockery::mock(['asBool' => false]));
        $indices->shouldReceive('create')->once()->with(Mockery::on(function ($params) {
            $this->assertSame('knn_vector', $params['body']['mappings']['properties']['vector']['type']);
            $this->assertSame(128, $params['body']['mappings']['properties']['vector']['dimension']);
            $this->assertTrue($params['body']['settings']['index']['knn']);
            $this->assertSame(100, $params['body']['settings']['index']['knn.algo_param.ef_search']);

            return true;
        }));

        $this->assertTrue($this->exposedEngine($client)->createVectorIndex('posts', 128));
    }

    public function testGetEngineInfoReportsHealthAndErrorFallback(): void
    {
        $client = Mockery::mock(OpenSearchClient::class);
        $cluster = Mockery::mock();
        $cluster->shouldReceive('health')->andReturn(['status' => 'green', 'number_of_nodes' => 3, 'number_of_data_nodes' => 2]);
        $client->shouldReceive('info')->once()->andReturn(['version' => ['number' => '2.11.0', 'distribution' => 'opensearch'], 'cluster_name' => 'test']);
        $client->shouldReceive('cluster')->once()->andReturn($cluster);

        $info = $this->exposedEngine($client)->getEngineInfo();

        $this->assertSame('opensearch', $info['type']);
        $this->assertSame('2.11.0', $info['version']);
        $this->assertTrue($info['isHealthy']);
        $this->assertTrue($info['supportsKNN']);

        $failing = Mockery::mock(OpenSearchClient::class);
        $failing->shouldReceive('info')->andThrow(new \RuntimeException('boom'));
        $fallback = $this->exposedEngine($failing)->getEngineInfo();
        $this->assertFalse($fallback['isHealthy']);
        $this->assertSame('boom', $fallback['error']);
    }

    public function testProcessResultsAppliesProcessorsAndVectorScore(): void
    {
        $engine = $this->exposedEngine();

        $builder = (new Builder(new Post, ''))->vectorSearch([0.1]);
        $builder->addResultProcessor(function ($results) {
            $results['total'] += 100;

            return $results;
        });

        $processed = $engine->processAdvancedResultsPub([
            'hits' => ['total' => ['value' => 1], 'hits' => [['_id' => '1', '_score' => 0.8, '_source' => ['id' => 1]]]],
        ], $builder);

        $this->assertSame(101, $processed['total']);
        $this->assertSame(0.8, $processed['hits'][0]['_vector_score']);
    }

    public function testMapAdvancedResultsRestoresOrderAndMetadata(): void
    {
        $model = Mockery::mock(Post::class)->makePartial();
        $model->shouldReceive('getScoutModelsByIds')->once()->with(Mockery::type(Builder::class), ['2', '1'])
            ->andReturn(new EloquentCollection([
                (new Post)->newFromBuilder(['id' => 1, 'title' => 'one']),
                (new Post)->newFromBuilder(['id' => 2, 'title' => 'two']),
            ]));

        $engine = $this->exposedEngine();
        $mapped = $engine->map(new Builder($model, ''), [
            'total' => 2,
            'hits' => [
                ['_id' => '2', '_score' => 0.9, '_highlight' => ['title' => ['<mark>two</mark>']]],
                ['_id' => '1', '_score' => 0.5],
            ],
        ], $model);

        $this->assertSame([2, 1], $mapped->pluck('id')->all());
        $this->assertSame(0.9, $mapped->first()->_score);
        $this->assertSame(['title' => ['<mark>two</mark>']], $mapped->first()->_highlight);

        $this->assertTrue($engine->map(new Builder($model, ''), ['total' => 0, 'hits' => []], $model)->isEmpty());
    }
}
