<?php

namespace Erikwang2013\WebmanScout\Tests\Engines;

require_once __DIR__.'/../ClientStubs.php';

use Erikwang2013\WebmanScout\Builder;
use Erikwang2013\WebmanScout\Engines\AdvancedXunSearchEngine;
use Erikwang2013\WebmanScout\Tests\Fixtures\Post;
use Erikwang2013\WebmanScout\Tests\TestCase;
use Erikwang2013\WebmanScout\XunSearchClient;
use Illuminate\Support\Facades\Cache as CacheFacade;
use Illuminate\Support\Facades\Log;
use Mockery;

class AdvancedXunSearchEngineTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Log::swap(Mockery::mock(\Psr\Log\LoggerInterface::class)->shouldIgnoreMissing());
        $this->cacheStore = Mockery::mock(\Illuminate\Contracts\Cache\Repository::class);
        $cache = Mockery::mock();
        $cache->shouldReceive('store')->with('file')->andReturn($this->cacheStore);
        CacheFacade::swap($cache);
    }

    protected function tearDown(): void
    {
        Log::clearResolvedInstance('log');
        CacheFacade::clearResolvedInstance('cache');
        parent::tearDown();
    }

    protected function makeEngine($client = null): AdvancedXunSearchEngine
    {
        return new AdvancedXunSearchEngine($client ?? Mockery::mock(XunSearchClient::class));
    }

    protected function mockXs(Mockery\MockInterface $client, string $index = 'posts'): array
    {
        $xs = Mockery::mock('XS');
        $search = Mockery::mock('XSSearch');
        $client->shouldReceive('refresh')->with($index)->andReturn($xs);
        $client->shouldReceive('task')->with($index)->andReturn($xs);
        $xs->shouldReceive('getSearch')->andReturn($search);

        return [$xs, $search];
    }

    protected function mockSearchExpectations(Mockery\MockInterface $search, array $docs = [], int $total = 0): void
    {
        $search->shouldReceive('getQuery')->andReturn('');
        $search->shouldReceive('setQuery')->withAnyArgs();
        $search->shouldReceive('setFuzzy')->with(true);
        $search->shouldReceive('setAutoSynonyms')->withNoArgs();
        $search->shouldReceive('setLimit')->withAnyArgs()->andReturn($search); // setLimit 链式返回 $this
        $search->shouldReceive('search')->andReturn($docs);
        $search->shouldReceive('getLastCount')->andReturn($total);
        $search->shouldReceive('getLastTime')->andReturn(0.01);
        $search->shouldReceive('getLastCost')->andReturn(5);
    }

    public function testAdvancedSearchReturnsCachedResultsWithoutHittingEngine(): void
    {
        $client = Mockery::mock(XunSearchClient::class);
        $client->shouldNotReceive('refresh');
        $this->cacheStore->shouldReceive('get')->once()->with(Mockery::on(fn ($key) => str_starts_with($key, 'xunsearch_advanced:')))->andReturn(['hits' => ['cached']]);
        $this->cacheStore->shouldReceive('put')->never();

        $results = $this->makeEngine($client)->advancedSearch(new Builder(new Post, 'php'));

        $this->assertSame(['hits' => ['cached']], $results);
    }

    public function testAdvancedSearchExecutesSearchAndCachesResult(): void
    {
        $client = Mockery::mock(XunSearchClient::class);
        [, $search] = $this->mockXs($client);
        $this->mockSearchExpectations($search, [], 7);
        $this->cacheStore->shouldReceive('get')->once()->andReturn(null);
        $this->cacheStore->shouldReceive('put')->once()->withArgs(function ($key, $data, $ttl) {
            return str_starts_with($key, 'xunsearch_advanced:') && $data['total'] === 7 && $ttl === 60;
        });

        $builder = new Builder(new Post, 'php');
        $builder->options['cache_ttl'] = 60;

        $results = $this->makeEngine($client)->advancedSearch($builder);

        $this->assertSame(7, $results['total']);
        $this->assertSame([], $results['hits']);
    }

    public function testAdvancedSearchSkipsCacheWhenDisabled(): void
    {
        $client = Mockery::mock(XunSearchClient::class);
        [, $search] = $this->mockXs($client);
        $this->mockSearchExpectations($search, [], 3);
        $this->cacheStore->shouldNotReceive('get');

        $builder = new Builder(new Post, 'php');
        $builder->options['cache'] = false;

        $this->assertSame(3, $this->makeEngine($client)->advancedSearch($builder)['total']);
    }

    public function testAdvancedSearchAppliesResultProcessors(): void
    {
        $client = Mockery::mock(XunSearchClient::class);
        [, $search] = $this->mockXs($client);
        $this->mockSearchExpectations($search, [], 2);
        $this->cacheStore->shouldReceive('get')->once()->andReturn(null);
        $this->cacheStore->shouldReceive('put')->once();

        $builder = (new Builder(new Post, 'php'))->addResultProcessor(function ($results) {
            $results['processed'] = true;

            return $results;
        });

        $results = $this->makeEngine($client)->advancedSearch($builder);

        $this->assertTrue($results['processed']);
    }

    public function testBuildAdvancedQueryScopesFieldsAndWheres(): void
    {
        $client = Mockery::mock(XunSearchClient::class);
        [, $search] = $this->mockXs($client);
        $this->mockSearchExpectations($search, [], 1);

        $builder = (new Builder(new Post, 'php'))
            ->where('status', 1)
            ->where('views', [10, 20])
            ->where('tag', ['a', 'b', 'c']);
        $builder->options['fields'] = ['title', 'body'];
        $builder->options['cache'] = false;

        $this->makeEngine($client)->advancedSearch($builder);

        $search->shouldHaveReceived('setQuery')->with(Mockery::on(function ($q) {
            return str_contains($q, 'title,body:"php"')
                && str_contains($q, 'status:"1"')
                && str_contains($q, 'views:[10 TO 20]')
                && str_contains($q, '(tag:"a" OR tag:"b" OR tag:"c")');
        }));
    }

    public function testBuildAdvancedQueryPassesBooleanQueriesThroughAndDefaultsToWildcard(): void
    {
        $client = Mockery::mock(XunSearchClient::class);
        [, $search] = $this->mockXs($client);
        $this->mockSearchExpectations($search, [], 0);

        $engine = $this->makeEngine($client);
        $engine->advancedSearch((new Builder(new Post, 'php OR laravel'))->setOptions(['cache' => false]));
        $engine->advancedSearch((new Builder(new Post, ''))->setOptions(['cache' => false]));

        $search->shouldHaveReceived('setQuery')->with('php OR laravel')->once();
        $search->shouldHaveReceived('setQuery')->with('*')->once();
    }

    public function testBuildConditionQuerySupportsAllOperators(): void
    {
        $client = Mockery::mock(XunSearchClient::class);
        [, $search] = $this->mockXs($client);
        $this->mockSearchExpectations($search, [], 1);

        $builder = (new Builder(new Post, 'php'))
            ->whereAdvanced('price', '>=', 10)
            ->whereAdvanced('price', '<=', 100)
            ->whereAdvanced('name', 'like', 'laravel')
            ->whereAdvanced('name', 'starts_with', 'la')
            ->whereAdvanced('name', 'ends_with', 'vel')
            ->whereAdvanced('deleted', 'missing', null)
            ->whereAdvanced('title', 'fuzzy', 'php', 'and', false)
            ->whereAdvanced('body', 'proximity', 'hello world');
        $builder->options['cache'] = false;

        $this->makeEngine($client)->advancedSearch($builder);

        $search->shouldHaveReceived('setQuery')->with(Mockery::on(function ($q) {
            return str_contains($q, 'price:>="10"')
                && str_contains($q, 'price:<="100"')
                && str_contains($q, 'name:*"laravel"*')
                && str_contains($q, 'name:"la"*')
                && str_contains($q, 'name:*"vel"')
                && str_contains($q, 'NOT deleted:[* TO *]')
                && str_contains($q, 'title:php~1')
                && str_contains($q, '"body:hello world"~5');
        }));
    }

    public function testRangeAndInAdvancedConditions(): void
    {
        $client = Mockery::mock(XunSearchClient::class);
        [, $search] = $this->mockXs($client);
        $this->mockSearchExpectations($search, [], 1);

        $builder = (new Builder(new Post, 'php'))
            ->whereRange('price', [10, 100])
            ->whereAdvanced('id', 'in', [1, 2, 3])
            ->whereAdvanced('id', 'not_in', [9])
            ->whereAdvanced('date', 'date_range', ['range' => ['2024-01-01', '2024-12-31']]);
        $builder->options['cache'] = false;

        $this->makeEngine($client)->advancedSearch($builder);

        $search->shouldHaveReceived('setQuery')->with(Mockery::on(function ($q) {
            return str_contains($q, 'price:[10 TO 100]')
                && str_contains($q, '(id:"1" OR id:"2" OR id:"3")')
                && str_contains($q, 'NOT (id:"9")')
                && str_contains($q, 'date:[2024-01-01 TO 2024-12-31]');
        }));
    }

    public function testCustomConditionHandlerTakesPrecedence(): void
    {
        $client = Mockery::mock(XunSearchClient::class);
        [, $search] = $this->mockXs($client);
        $this->mockSearchExpectations($search, [], 1);

        $builder = (new Builder(new Post, 'php'))->whereAdvanced('score', 'custom', 5);
        $builder->options['cache'] = false;

        $this->makeEngine($client)
            ->registerConditionHandler('custom', fn ($field, $value) => "custom({$field}:{$value})")
            ->advancedSearch($builder);

        $search->shouldHaveReceived('setQuery')->with(Mockery::on(fn ($q) => str_contains($q, 'custom(score:5)')));
    }

    public function testApplySearchOptionsSetsRangeWeightsAndCollapse(): void
    {
        $client = Mockery::mock(XunSearchClient::class);
        [, $search] = $this->mockXs($client);
        $this->mockSearchExpectations($search, [], 1);
        $search->shouldReceive('setRange')->with('2024-01-01', '2024-12-31')->once();
        $search->shouldReceive('setWeight')->with('title', 2)->once();
        $search->shouldReceive('setCollapse')->with('pid')->once();

        $builder = new Builder(new Post, 'php');
        $builder->options['cache'] = false;
        $builder->options['fuzzy'] = false;
        $builder->options['auto_synonym'] = false;
        $builder->options['range'] = ['2024-01-01', '2024-12-31'];
        $builder->options['weights'] = ['title' => 2];
        $builder->options['collapse'] = 'pid';

        $this->makeEngine($client)->advancedSearch($builder);

        $search->shouldNotHaveReceived('setFuzzy');
    }

    public function testApplyAdvancedSortsSortsByField(): void
    {
        $client = Mockery::mock(XunSearchClient::class);
        [, $search] = $this->mockXs($client);
        $this->mockSearchExpectations($search, [], 1);
        $search->shouldReceive('setSort')->with('price', false)->once();

        // orderByGeoDistance 产生的 sort 无 type 键，src 按字段降级为普通排序
        $engine = $this->makeEngine($client);
        $engine->advancedSearch((new Builder(new Post, 'php'))->orderByGeoDistance('price', 30.0, 120.0, 'asc')->setOptions(['cache' => false]));
    }

    public function testProcessAdvancedResultsBuildsHits(): void
    {
        $doc = Mockery::mock(\XSDocument::class);
        $doc->shouldReceive('id')->andReturn(1);
        $doc->shouldReceive('score')->andReturn(0.8);
        $doc->shouldReceive('percent')->andReturn(80);
        $doc->shouldReceive('getFields')->andReturn(['id' => 1, 'title' => 'One']);
        $doc->shouldReceive('terms')->andReturn(['php']);
        $doc->shouldReceive('matched')->andReturn(true);
        // stub 已定义 highlight()/relevance()，Mockery mock 继承真实方法，需声明期望
        $doc->shouldReceive('highlight')->andReturn(['title' => '<em>php</em>']);
        $doc->shouldReceive('relevance')->andReturn(['score' => 0.8]);

        $client = Mockery::mock(XunSearchClient::class);
        [, $search] = $this->mockXs($client);
        $this->mockSearchExpectations($search, [$doc], 1);

        $builder = new Builder(new Post, 'php');
        $builder->options['cache'] = false;
        $builder->options['highlight'] = true;
        $builder->options['relevance'] = true;

        $results = $this->makeEngine($client)->advancedSearch($builder);
        $hit = $results['hits'][0];

        $this->assertSame(1, $hit['_id']);
        $this->assertSame(0.8, $hit['_score']);
        $this->assertSame(80, $hit['_percent']);
        $this->assertSame(['id' => 1, 'title' => 'One'], $hit['_doc']);
        $this->assertSame(['php'], $hit['_terms']);
        $this->assertTrue($hit['_matched']);
        $this->assertSame(['title' => '<em>php</em>'], $hit['_highlight']);
        $this->assertSame(['score' => 0.8], $hit['_relevance']);
    }

    public function testProcessAdvancedResultsAddsHighlightAndRelevanceOnRealDoc(): void
    {
        $doc = new \XSDocument;
        $doc->setFields(['id' => 2, 'title' => 'Two']);

        $client = Mockery::mock(XunSearchClient::class);
        [, $search] = $this->mockXs($client);
        $this->mockSearchExpectations($search, [$doc], 1);

        $builder = new Builder(new Post, 'php');
        $builder->options['cache'] = false;
        $builder->options['highlight'] = true;
        $builder->options['relevance'] = true;

        $hit = $this->makeEngine($client)->advancedSearch($builder)['hits'][0];

        $this->assertSame(2, $hit['_id']);
        $this->assertArrayHasKey('_highlight', $hit);
        $this->assertArrayHasKey('_relevance', $hit);
    }

    public function testRelatedQueriesAndSuggestionsAreFetchedOnDemand(): void
    {
        $client = Mockery::mock(XunSearchClient::class);
        [, $search] = $this->mockXs($client);
        $this->mockSearchExpectations($search, [], 2);
        $search->shouldReceive('getRelatedQuery')->with('php', 10)->andReturn(['php1']);
        $search->shouldReceive('getExpandedQuery')->with('php')->andReturn(['php8']);

        $builder = new Builder(new Post, 'php');
        $builder->options['cache'] = false;
        $builder->options['related'] = true;
        $builder->options['suggest'] = true;

        $results = $this->makeEngine($client)->advancedSearch($builder);

        $this->assertSame(['php1'], $results['related_queries']);
        $this->assertSame(['php8'], $results['suggestions']);
    }

    public function testFacetsAndAggregationsAreBuiltFromBuilderConfig(): void
    {
        $client = Mockery::mock(XunSearchClient::class);
        [, $search] = $this->mockXs($client);
        $this->mockSearchExpectations($search, [], 1);
        $search->shouldReceive('getFacets')->with('category', 10)->andReturn(['php' => 5]);
        $search->shouldReceive('getTerms')->with('price', 10)->andReturn(['10' => 3]);

        $builder = (new Builder(new Post, 'php'))
            ->facet('category')
            ->aggregate('price_terms', 'terms', 'price');
        $builder->options['cache'] = false;

        $results = $this->makeEngine($client)->advancedSearch($builder);

        $this->assertSame(['php' => 5], $results['facets']['category']);
        $this->assertSame(['10' => 3], $results['aggregations']['price_terms']);
    }

    public function testStatsAndRangeAggregationsAreCalculated(): void
    {
        $client = Mockery::mock(XunSearchClient::class);
        [, $search] = $this->mockXs($client);
        $this->mockSearchExpectations($search, [], 1);
        $search->shouldReceive('getTerms')->with('price', 1000)->andReturn(['10' => 2, '20' => 4, '30' => 6]);
        $search->shouldReceive('count')->with('price:[10 TO 20]')->andReturn(2);

        $builder = (new Builder(new Post, 'php'))
            ->aggregate('price_stats', 'stats', 'price')
            ->aggregate('price_range', 'range', 'price', ['ranges' => [['key' => 'low', 'from' => 10, 'to' => 20]]]);
        $builder->options['cache'] = false;

        $results = $this->makeEngine($client)->advancedSearch($builder);

        $this->assertSame(['count' => 3, 'min' => 2, 'max' => 6, 'avg' => 4, 'sum' => 12], $results['aggregations']['price_stats']);
        $this->assertSame(['low' => 2], $results['aggregations']['price_range']);
    }

    public function testSemanticAndPinyinSearchRunAndReturnResults(): void
    {
        $client = Mockery::mock(XunSearchClient::class);
        [, $search] = $this->mockXs($client);
        $this->mockSearchExpectations($search, [], 2);
        $search->shouldReceive('setSemantic')->with(true);
        $search->shouldReceive('setPinyin')->with(true);

        $engine = $this->makeEngine($client);

        $semantic = $engine->semanticSearch('posts', 'php');
        $this->assertSame(2, $semantic['total']);

        $pinyin = $engine->pinyinSearch('posts', 'php');
        $this->assertSame(2, $pinyin['total']);
    }

    public function testGetSearchAnalysisReadsDbStats(): void
    {
        $client = Mockery::mock(XunSearchClient::class);
        [, $search] = $this->mockXs($client);
        $search->shouldReceive('getDbTotal')->andReturn(100);
        $search->shouldReceive('getLastTime')->andReturn(0.02);
        $search->shouldReceive('getHotQuery')->with(10)->andReturn(['hot']);
        $search->shouldReceive('getRelatedQuery')->with('php', 10)->andReturn(['php1']);
        $search->shouldReceive('getSearchLog')->with(50)->andReturn(['log']);

        $analysis = $this->makeEngine($client)->getSearchAnalysis('posts', ['query' => 'php']);

        $this->assertSame(100, $analysis['total_searches']);
        $this->assertSame(0.02, $analysis['last_search_time']);
        $this->assertSame(['hot'], $analysis['hot_queries']);
        $this->assertSame(['php1'], $analysis['related_queries']);
        $this->assertSame(['log'], $analysis['search_logs']);
    }

    public function testGetSearchAnalysisReturnsEmptyOnFailure(): void
    {
        $client = Mockery::mock(XunSearchClient::class);
        [, $search] = $this->mockXs($client);
        $search->shouldReceive('getDbTotal')->andThrow(new \RuntimeException('boom'));

        $analysis = $this->makeEngine($client)->getSearchAnalysis('posts');

        $this->assertSame(0, $analysis['total_searches']);
        $this->assertSame([], $analysis['hot_queries']);
    }

    public function testRebuildIndexCleansAndFlushes(): void
    {
        $client = Mockery::mock(XunSearchClient::class);
        $xs = Mockery::mock('XS');
        $index = Mockery::mock('XSIndex');
        $client->shouldReceive('task')->with('posts')->andReturn($xs);
        $xs->shouldReceive('getIndex')->andReturn($index);
        $index->shouldReceive('clean')->once();
        $index->shouldReceive('flushIndex')->once();

        $this->assertTrue($this->makeEngine($client)->rebuildIndex('posts'));

        $failing = Mockery::mock(XunSearchClient::class);
        $xs2 = Mockery::mock('XS');
        $failing->shouldReceive('task')->with('posts')->andReturn($xs2);
        $xs2->shouldReceive('getIndex')->andThrow(new \RuntimeException('boom'));

        $this->assertFalse($this->makeEngine($failing)->rebuildIndex('posts'));
    }

    public function testOptimizeIndexUsesOptimizeWhenAvailable(): void
    {
        $client = Mockery::mock(XunSearchClient::class);
        $xs = Mockery::mock('XS');
        $index = new \XSIndex; // 真实 stub（含 optimize 方法），method_exists 守卫通过
        $client->shouldReceive('task')->with('posts')->andReturn($xs);
        $xs->shouldReceive('getIndex')->andReturn($index);

        $this->assertTrue($this->makeEngine($client)->optimizeIndex('posts'));

        // 失败时返回 false 且不抛出
        $failing = Mockery::mock(XunSearchClient::class);
        $xs2 = Mockery::mock('XS');
        $failing->shouldReceive('task')->with('posts')->andReturn($xs2);
        $xs2->shouldReceive('getIndex')->andThrow(new \RuntimeException('boom'));

        $this->assertFalse($this->makeEngine($failing)->optimizeIndex('posts'));
    }

    public function testGetEngineInfoReportsCapabilities(): void
    {
        $info = $this->makeEngine()->getEngineInfo();

        $this->assertSame('xunsearch', $info['type']);
        $this->assertTrue($info['supports_advanced']);
        $this->assertTrue($info['supports_highlight']);
        $this->assertTrue($info['supports_cache']);
        $this->assertArrayHasKey('supports_semantic', $info);
    }

    public function testSearchAndPaginateDelegateToAdvancedFlow(): void
    {
        $client = Mockery::mock(XunSearchClient::class);
        [, $search] = $this->mockXs($client);
        $this->mockSearchExpectations($search, [], 26);

        $engine = $this->makeEngine($client);
        $builder = (new Builder(new Post, 'php'))->setOptions(['cache' => false]);

        $results = $engine->search($builder);
        $this->assertSame(26, $results['total']);

        $paginated = $engine->paginate($builder, 10, 3);
        // 分页参数不写回 builder，避免污染后续 get()/keys()
        $this->assertNull($builder->limit);
        $this->assertNull($builder->offset);
        $this->assertSame(26, $paginated['total']);
    }

    public function testGetAggregationsAndFacetsEntryPoints(): void
    {
        $client = Mockery::mock(XunSearchClient::class);
        [, $search] = $this->mockXs($client);
        $search->shouldReceive('getQuery')->andReturn('php');
        $search->shouldReceive('setQuery')->withAnyArgs();
        $search->shouldReceive('getFacets')->with('category', 10)->andReturn(['php' => 5]);
        $search->shouldReceive('getTerms')->with('price', 10)->andReturn(['10' => 3]);

        $engine = $this->makeEngine($client);
        $builder = (new Builder(new Post, 'php'))->facet('category')->aggregate('price_terms', 'terms', 'price');

        $this->assertSame(['php' => 5], $engine->getFacets($builder)['category']);
        $this->assertSame(['10' => 3], $engine->getAggregations($builder)['price_terms']);
        $this->assertSame([], $engine->getFacets(new Builder(new Post, '')));
    }
}
