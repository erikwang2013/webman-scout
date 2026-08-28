<?php

namespace Erikwang2013\WebmanScout\Tests\Engines;

use Erikwang2013\WebmanScout\Builder;
use Erikwang2013\WebmanScout\Engines\AdvancedMeilisearchEngine;
use Erikwang2013\WebmanScout\Tests\Fixtures\Post;
use Erikwang2013\WebmanScout\Tests\TestCase;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Meilisearch\Client as MeilisearchClient;
use Meilisearch\Endpoints\Indexes;
use Mockery;

class AdvancedMeilisearchEngineTest extends TestCase
{
    /**
     * Expose the protected parameter builders for direct assertions.
     */
    protected function exposedEngine($client = null)
    {
        return new class($client ?? Mockery::mock(MeilisearchClient::class)) extends AdvancedMeilisearchEngine {
            public function buildSearchParamsPub(Builder $builder): array
            {
                return $this->buildSearchParams($builder);
            }

            public function buildFiltersPub(Builder $builder): string
            {
                return $this->buildFilters($builder);
            }

            public function buildMeilisearchFilterPub(array $condition): string
            {
                return $this->buildMeilisearchFilter($condition);
            }

            public function buildMeilisearchSortsPub(array $sorts): array
            {
                return $this->buildMeilisearchSorts($sorts);
            }

            public function addMeilisearchVectorSearchPub(array $params, array $vectorSearch): array
            {
                return $this->addMeilisearchVectorSearch($params, $vectorSearch);
            }

            public function processMeilisearchResultsPub(array $result, Builder $builder): array
            {
                return $this->processMeilisearchResults($result, $builder);
            }
        };
    }

    public function testSearchBuildsParamsAndCallsRawSearch(): void
    {
        $client = Mockery::mock(MeilisearchClient::class);
        $index = Mockery::mock(Indexes::class);
        $client->shouldReceive('index')->with('posts')->andReturn($index);
        $index->shouldReceive('rawSearch')->once()->with('php', Mockery::on(function ($params) {
            $this->assertSame(20, $params['limit']);
            $this->assertSame(0, $params['offset']);
            $this->assertSame(['id', '*'], $params['attributesToRetrieve']);
            // search() unsets 'query' before performSearch (query goes as first arg)
            $this->assertArrayNotHasKey('query', $params);
            $this->assertSame('status = 1', $params['filter']);

            return true;
        }))->andReturn([
            'hits' => [['id' => 2, '_rankingScore' => 0.9]],
            'totalHits' => 1,
            'query' => 'php',
        ]);

        $engine = $this->exposedEngine($client);
        $builder = (new Builder(new Post, 'php'))->where('status', 1);

        $results = $engine->search($builder);

        // search() returns the raw client response (processed shape is separate)
        $this->assertSame(1, $results['totalHits']);
        $this->assertSame(2, $results['hits'][0]['id']);
    }

    public function testPaginateUsesHitsPerPageAndPage(): void
    {
        $client = Mockery::mock(MeilisearchClient::class);
        $index = Mockery::mock(Indexes::class);
        $client->shouldReceive('index')->with('posts')->andReturn($index);
        $index->shouldReceive('rawSearch')->once()->with('php', Mockery::on(function ($params) {
            $this->assertSame(10, $params['hitsPerPage']);
            $this->assertSame(3, $params['page']);
            $this->assertArrayNotHasKey('limit', $params);
            $this->assertArrayNotHasKey('offset', $params);

            return true;
        }))->andReturn(['hits' => [], 'totalHits' => 0]);

        $this->exposedEngine($client)->paginate((new Builder(new Post, 'php')), 10, 3);
    }

    public function testBuildSearchParamsWithOptionsFacetsAndSorts(): void
    {
        $engine = $this->exposedEngine();

        $builder = (new Builder(new Post, 'php'))
            ->options([
                'crop' => ['body'],
                'crop_length' => 50,
                'highlight_pre_tag' => '<b>',
                'show_matches_position' => true,
                'show_ranking_score' => true,
            ])
            ->facet('category')
            ->orderBy('created_at', 'desc');

        $params = $engine->buildSearchParamsPub($builder);

        $this->assertSame(['body'], $params['attributesToCrop']);
        $this->assertSame(50, $params['cropLength']);
        $this->assertSame('<b>', $params['highlightPreTag']);
        $this->assertTrue($params['showMatchesPosition']);
        $this->assertTrue($params['showRankingScore']);
        $this->assertSame(['category'], $params['facets']);
        $this->assertSame(['created_at:desc'], $params['sort']);
        $this->assertSame('last', $params['matchingStrategy']);
    }

    public function testBuildFiltersCombinesWheresAdvancedAndIns(): void
    {
        $engine = $this->exposedEngine();

        $builder = (new Builder(new Post, ''))
            ->where('status', 1)
            ->whereIn('id', [1, 2])
            ->whereNotIn('tag', ['a'])
            ->where('title', 'say "hi"')
            ->whereAdvanced('price', 'range', ['range' => [10, 20]])
            ->whereAdvanced('name', 'starts_with', 'jo');

        $filter = $engine->buildFiltersPub($builder);

        $this->assertSame(
            'status = 1 AND title = "say \"hi\"" AND price >= 10 AND price <= 20 AND name STARTS WITH "jo" AND id IN [1, 2] AND tag NOT IN ["a"]',
            $filter
        );
    }

    public function testBuildMeilisearchFilterSupportsAllOperators(): void
    {
        $engine = $this->exposedEngine();

        $cases = [
            [['field' => 'a', 'operator' => '>', 'value' => 5], 'a > 5'],
            [['field' => 'a', 'operator' => '>=', 'value' => 5], 'a >= 5'],
            [['field' => 'a', 'operator' => '<', 'value' => 5], 'a < 5'],
            [['field' => 'a', 'operator' => '<=', 'value' => 5], 'a <= 5'],
            [['field' => 'a', 'operator' => '!=', 'value' => 'x'], 'a != "x"'],
            [['field' => 'a', 'operator' => 'geo_radius', 'value' => ['lat' => 1, 'lng' => 2, 'radius' => 3]], '_geoRadius(1, 2, 3)'],
            [['field' => 'a', 'operator' => 'exists', 'value' => null], 'a EXISTS'],
            [['field' => 'a', 'operator' => 'missing', 'value' => null], 'a NOT EXISTS'],
            [['field' => 'a', 'operator' => 'contains', 'value' => 'ph'], 'a CONTAINS "ph"'],
            [['field' => 'a', 'operator' => 'ends_with', 'value' => 'hp'], 'a ENDS WITH "hp"'],
            [['field' => 'a', 'operator' => 'in', 'value' => [1, 2]], 'a IN [1, 2]'],
            [['field' => 'a', 'operator' => 'not_in', 'value' => [1]], 'a NOT IN [1]'],
            [['field' => 'a', 'operator' => 'null', 'value' => null], 'a IS NULL'],
            [['field' => 'a', 'operator' => 'not_null', 'value' => null], 'a IS NOT NULL'],
            [['field' => 'a', 'operator' => 'empty', 'value' => null], 'a IS EMPTY'],
            [['field' => 'a', 'operator' => 'not_empty', 'value' => null], 'a IS NOT EMPTY'],
            [['field' => 'a', 'operator' => 'unknown', 'value' => 1], 'a = 1'],
        ];

        foreach ($cases as [$condition, $expected]) {
            $this->assertSame($expected, $engine->buildMeilisearchFilterPub($condition));
        }
    }

    public function testBuildMeilisearchSortsHandlesGeoRandomAndRelevance(): void
    {
        $engine = $this->exposedEngine();

        $sorts = [
            ['field' => 'title', 'direction' => 'asc'],
            ['type' => 'geo_distance', 'location' => ['lat' => 10, 'lng' => 20], 'direction' => 'desc'],
            ['type' => 'random'],
            ['field' => '_relevance'],
            ['field' => '_distance'],
        ];

        $this->assertSame([
            'title:asc',
            '_geoPoint(10, 20):desc',
            '_random:asc',
            '_relevance:desc',
            '_distance:asc',
        ], $engine->buildMeilisearchSortsPub($sorts));
    }

    public function testAddMeilisearchVectorSearchBuildsHybridParams(): void
    {
        $engine = $this->exposedEngine();

        $params = $engine->addMeilisearchVectorSearchPub([], [
            'vector' => [0.1, 0.2],
            'options' => ['hybrid' => ['embedder' => 'custom'], 'semantic_ratio' => 0.5, 'similarity_threshold' => 0.7],
        ]);

        $this->assertSame([0.1, 0.2], $params['vector']);
        $this->assertSame(['embedder' => 'custom', 'semanticRatio' => 0.5], $params['hybrid']);
        $this->assertSame(0.7, $params['similarityThreshold']);

        // no vector -> untouched
        $this->assertSame(['x' => 1], $engine->addMeilisearchVectorSearchPub(['x' => 1], ['vector' => null]));
    }

    public function testProcessResultsMapsScoresAndHighlights(): void
    {
        $engine = $this->exposedEngine();

        $result = $engine->processMeilisearchResultsPub([
            'hits' => [
                ['id' => 1, '_rankingScore' => 0.8, '_formatted' => ['title' => '<mark>php</mark>'], '_vectorDistance' => 0.1],
            ],
            'totalHits' => 5,
            'facetDistribution' => ['category' => ['a' => 2]],
            'query' => 'php',
            'processingTimeMs' => 3,
        ], new Builder(new Post, ''));

        $this->assertSame(5, $result['total']);
        $this->assertSame(1, $result['hits'][0]['_id']);
        $this->assertSame(0.8, $result['hits'][0]['_score']);
        $this->assertSame(0.1, $result['hits'][0]['_vector_distance']);
        $this->assertSame(['title' => '<mark>php</mark>'], $result['hits'][0]['_highlight']);
        $this->assertSame(['category' => ['a' => 2]], $result['facets']);
    }

    public function testProcessResultsAppliesResultProcessors(): void
    {
        $engine = $this->exposedEngine();

        $builder = new Builder(new Post, '');
        $builder->addResultProcessor(function ($results) {
            $results['total'] += 100;

            return $results;
        });

        $processed = $engine->processMeilisearchResultsPub(['hits' => [], 'totalHits' => 1], $builder);

        $this->assertSame(101, $processed['total']);
    }

    public function testGetAggregationsReturnsFacetDistribution(): void
    {
        $client = Mockery::mock(MeilisearchClient::class);
        $index = Mockery::mock(Indexes::class);
        $client->shouldReceive('index')->with('posts')->andReturn($index);
        $index->shouldReceive('search')->once()->with('php', Mockery::on(function ($params) {
            $this->assertSame(['category'], $params['facets']);

            return true;
        }))->andReturn([
            'facetDistribution' => ['category' => ['a' => 2]],
            'totalHits' => 4,
        ]);

        $builder = (new Builder(new Post, 'php'))->aggregate('category', 'count', 'category');
        $result = $this->exposedEngine($client)->getAggregations($builder);

        $this->assertSame(['category' => ['a' => 2]], $result['facets']);
        $this->assertSame(4, $result['total']);
    }

    public function testUpdateVectorsAddsDocumentsWithEmbeddings(): void
    {
        $client = Mockery::mock(MeilisearchClient::class);
        $index = Mockery::mock(Indexes::class);
        $client->shouldReceive('index')->with('posts')->andReturn($index);
        $index->shouldReceive('updateDocuments')->once()->with(Mockery::on(function ($documents) {
            $this->assertSame(1, $documents[0]['id']);
            $this->assertSame(['vector' => [0.5, 0.5]], $documents[0]['_vectors']);
            $this->assertSame(['vector' => [0.5, 0.5]], $documents[0]['embedding']);

            return true;
        }))->andReturn(['taskUid' => 1]);
        $index->shouldReceive('getTask')->with(1)->andReturn(['status' => 'succeeded']);

        $engine = $this->exposedEngine($client);
        $engine->updateVectors(new EloquentCollection([new Post(['id' => 1, 'title' => 'x'])]), [['vector' => [0.5, 0.5]]]);
    }
}
