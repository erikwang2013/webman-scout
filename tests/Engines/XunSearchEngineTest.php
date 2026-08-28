<?php

namespace Erikwang2013\WebmanScout\Tests\Engines;

require_once __DIR__.'/../ClientStubs.php';

use Erikwang2013\WebmanScout\Builder;
use Erikwang2013\WebmanScout\Engines\XunSearchEngine;
use Erikwang2013\WebmanScout\Tests\Fixtures\Post;
use Erikwang2013\WebmanScout\Tests\TestCase;
use Erikwang2013\WebmanScout\XunSearchClient;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\LazyCollection;
use Mockery;

class XunSearchEngineTest extends TestCase
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

    protected function makeEngine($client = null, bool $softDelete = false): XunSearchEngine
    {
        return new XunSearchEngine($client ?? Mockery::mock(XunSearchClient::class), $softDelete);
    }

    protected function mockXs(Mockery\MockInterface $client): array
    {
        $xs = Mockery::mock('XS');
        $index = Mockery::mock('XSIndex');
        $search = Mockery::mock('XSSearch');
        $client->shouldReceive('task')->with('posts')->andReturn($xs);
        $client->shouldReceive('refresh')->with('posts')->andReturn($xs);
        $client->shouldReceive('getSearch')->andReturn($search);
        $xs->shouldReceive('getIndex')->andReturn($index);
        $xs->shouldReceive('getSearch')->andReturn($search);

        return [$xs, $index, $search];
    }

    public function testUpdateAddsDocumentsAndFlushesIndex(): void
    {
        $client = Mockery::mock(XunSearchClient::class);
        [, $index] = $this->mockXs($client);

        $index->shouldReceive('update')->once()->with(Mockery::on(function ($docs) {
            $this->assertCount(2, $docs);
            $this->assertInstanceOf(\XSDocument::class, $docs[0]);
            $this->assertSame(1, $docs[0]->getFields()['id']);
            $this->assertSame('One', $docs[0]->getFields()['title']);

            return true;
        }));
        $index->shouldReceive('flushIndex')->once();

        $this->makeEngine($client)->update(new EloquentCollection([
            new Post(['id' => 1, 'title' => 'One', 'body' => 'a', 'status' => 0]),
            new Post(['id' => 2, 'title' => 'Two', 'body' => 'b', 'status' => 0]),
        ]));
    }

    public function testUpdateSkipsEmptySearchableArraysAndEmptyCollections(): void
    {
        $hidden = new class extends Post {
            public function toSearchableArray(): array
            {
                return [];
            }
        };

        $client = Mockery::mock(XunSearchClient::class);
        [, $index] = $this->mockXs($client);
        $index->shouldReceive('update')->never();
        $index->shouldReceive('flushIndex')->once(); // auto_flush 默认开启，跳过空文档后仍刷新

        $this->makeEngine($client)->update(new EloquentCollection([new $hidden(['id' => 1])]));

        $noOpClient = Mockery::mock(XunSearchClient::class);
        $noOpClient->shouldNotReceive('task');
        $this->makeEngine($noOpClient)->update(new EloquentCollection);
    }

    public function testUpdateAddsPrimaryKeyWhenMissing(): void
    {
        $model = Mockery::mock(Post::class)->makePartial();
        $model->shouldReceive('toSearchableArray')->andReturn(['title' => 'x']);
        $model->setRawAttributes(['id' => 5]);

        $client = Mockery::mock(XunSearchClient::class);
        [, $index] = $this->mockXs($client);
        $index->shouldReceive('update')->once()->with(Mockery::on(function ($docs) {
            $this->assertSame(5, $docs[0]->getFields()['id']);

            return true;
        }));
        $index->shouldReceive('flushIndex')->once();

        $this->makeEngine($client)->update(new EloquentCollection([$model]));
    }

    public function testUpdateBatchesByBatchSize(): void
    {
        $client = Mockery::mock(XunSearchClient::class);
        [, $index] = $this->mockXs($client);

        $counts = [];
        $index->shouldReceive('update')->twice()->with(Mockery::on(function ($docs) use (&$counts) {
            $counts[] = count($docs);

            return true;
        }));
        $index->shouldReceive('flushIndex')->once();

        $models = collect(range(1, 150))->map(fn ($i) => new Post(['id' => $i, 'title' => "t{$i}"]))->all();
        $this->makeEngine($client)->setBatchSize(100)->update(new EloquentCollection($models));

        $this->assertSame([100, 50], $counts);
    }

    public function testDeleteRemovesDocumentsByIds(): void
    {
        $client = Mockery::mock(XunSearchClient::class);
        [, $index] = $this->mockXs($client);
        $index->shouldReceive('del')->twice()->with(Mockery::any());
        $index->shouldReceive('flushIndex')->once();

        $this->makeEngine($client)->delete(new EloquentCollection([
            new Post(['id' => 1]),
            new Post(['id' => 2]),
        ]));

        $index->shouldHaveReceived('del')->with(1)->once();
        $index->shouldHaveReceived('del')->with(2)->once();
    }

    public function testSearchSendsQueryFiltersAndOptions(): void
    {
        $client = Mockery::mock(XunSearchClient::class);
        [, , $search] = $this->mockXs($client);

        // 模拟真实 XSSearch：getQuery 返回当前已构建的查询，条件逐步追加（AND 组合）
        $current = '';
        $search->shouldReceive('getQuery')->andReturnUsing(function () use (&$current) {
            return $current;
        });
        $search->shouldReceive('setQuery')->withAnyArgs()->andReturnUsing(function ($q) use (&$current) {
            $current = $q;
        });
        $search->shouldReceive('setFuzzy')->with(true)->once();
        $search->shouldReceive('setSort')->with('created_at', true)->once();
        $search->shouldReceive('setLimit')->with(5, 0)->once()->andReturn($search); // 链式返回 $this

        $doc = Mockery::mock(\XSDocument::class);
        $doc->shouldReceive('getFields')->andReturn(['id' => 1, 'title' => 'One']);
        $doc->shouldReceive('score')->andReturn(0.9);
        $doc->shouldReceive('percent')->andReturn(90);
        $doc->shouldReceive('terms')->andReturn(['php']);
        $doc->shouldReceive('matched')->andReturn(true);

        $search->shouldReceive('search')->once()->andReturn([$doc]);
        $search->shouldReceive('getLastCount')->andReturn(2);
        $search->shouldReceive('getLastTime')->andReturn(0.01);
        $search->shouldReceive('getLastCost')->andReturn(10);

        $engine = $this->makeEngine($client);
        $builder = (new Builder(new Post, 'php'))
            ->where('status', 1)
            ->whereIn('id', [1, 2])
            ->whereNotIn('tag', ['x'])
            ->orderBy('created_at', 'desc')
            ->take(5);

        $results = $engine->search($builder);

        $search->shouldHaveReceived('setQuery')->with('php')->once();
        $search->shouldHaveReceived('setQuery')->with(Mockery::on(function ($q) {
            return str_contains($q, 'status:"1"') && str_contains($q, 'id:"1" OR id:"2"') && str_contains($q, 'NOT (tag:"x")');
        }))->once();

        $this->assertSame(2, $results['total']);
        $this->assertSame(1, $results['hits'][0]['data']['id']);
        $this->assertSame(0.9, $results['hits'][0]['score']);
        $this->assertSame(90, $results['hits'][0]['percent']);
        $this->assertSame(['php'], $results['hits'][0]['terms']);
        $this->assertTrue($results['hits'][0]['matched']);
    }

    public function testPaginateAddsPaginationMetadata(): void
    {
        $client = Mockery::mock(XunSearchClient::class);
        [$xs, , $search] = $this->mockXs($client);
        $client->shouldReceive('getSearch')->andReturn($search);

        $search->shouldReceive('getQuery')->andReturn('');
        $search->shouldReceive('setQuery')->with('php')->once();
        $search->shouldReceive('setFuzzy')->with(true)->once();
        $search->shouldReceive('setLimit')->with(10, 20)->once()->andReturn($search); // 链式返回 $this
        $search->shouldReceive('search')->once()->andReturn([]);
        $search->shouldReceive('getLastCount')->andReturn(26);
        $search->shouldReceive('getLastTime')->andReturn(0.0);
        $search->shouldReceive('getLastCost')->andReturn(0);

        $results = $this->makeEngine($client)->paginate(new Builder(new Post, 'php'), 10, 3);

        $this->assertSame(26, $results['total']);
        $this->assertSame(10, $results['per_page']);
        $this->assertSame(3, $results['current_page']);
        $this->assertSame(3, $results['last_page']);
    }

    public function testSearchInvokesCallback(): void
    {
        $client = Mockery::mock(XunSearchClient::class);
        [, , $search] = $this->mockXs($client);
        $search->shouldNotReceive('search');

        $engine = $this->makeEngine($client);
        $builder = new Builder(new Post, 'php');
        $callbackRan = false;
        $builder->callback = function ($searchArg, $query, $options) use ($search, &$callbackRan) {
            $this->assertSame($search, $searchArg);
            $this->assertSame('php', $query);
            $callbackRan = true;

            return ['hits' => [], 'total' => 0];
        };

        $engine->search($builder);
        $this->assertTrue($callbackRan);
    }

    public function testMapIdsExtractsFromDataWithKeyName(): void
    {
        $engine = $this->makeEngine();

        $this->assertSame([2, 1], $engine->mapIds([
            'hits' => [
                ['data' => ['id' => 2]],
                ['data' => ['id' => 1]],
            ],
        ])->all());

        $this->assertSame([2], $engine->mapIds(['hits' => [['data' => ['_id' => 2]]]])->all());
        $this->assertTrue($engine->mapIds(['hits' => []])->isEmpty());
    }

    public function testMapRestoresModelsInOrder(): void
    {
        $model = Mockery::mock(Post::class)->makePartial();
        $model->shouldReceive('getScoutModelsByIds')->once()->with(Mockery::type(Builder::class), [2, 1])
            ->andReturn(new EloquentCollection([
                (new Post)->newFromBuilder(['id' => 1, 'title' => 'one']),
                (new Post)->newFromBuilder(['id' => 2, 'title' => 'two']),
            ]));

        $engine = $this->makeEngine();
        $results = ['hits' => [['data' => ['id' => 2]], ['data' => ['id' => 1]]]];

        $mapped = $engine->map(new Builder($model, ''), $results, $model);
        $this->assertSame([2, 1], $mapped->pluck('id')->all());

        $this->assertTrue($engine->map(new Builder($model, ''), ['hits' => []], $model)->isEmpty());
    }

    public function testLazyMapRestoresModelsInOrder(): void
    {
        $model = Mockery::mock(Post::class)->makePartial();
        $model->shouldReceive('getScoutModelsByIds')->once()->with(Mockery::type(Builder::class), [2, 1])
            ->andReturn(new EloquentCollection([
                (new Post)->newFromBuilder(['id' => 1]),
                (new Post)->newFromBuilder(['id' => 2]),
            ]));

        $engine = $this->makeEngine();
        $lazy = $engine->lazyMap(new Builder($model, ''), ['hits' => [['data' => ['id' => 2]], ['data' => ['id' => 1]]]], $model);

        $this->assertInstanceOf(LazyCollection::class, $lazy);
        // lazyMap 按命中顺序产出，与 map() 排序一致
        $this->assertSame([2, 1], $lazy->pluck('id')->all());

        $this->assertTrue($engine->lazyMap(new Builder($model, ''), ['hits' => []], $model)->isEmpty());
    }

    public function testGetTotalCountAndFlush(): void
    {
        $engine = $this->makeEngine();
        $this->assertSame(5, $engine->getTotalCount(['total' => 5]));

        $client = Mockery::mock(XunSearchClient::class);
        [, $index] = $this->mockXs($client);
        $index->shouldReceive('clean')->once();
        $index->shouldReceive('flushIndex')->once();

        $this->makeEngine($client)->flush(new Post);
    }

    public function testCreateIndexDelegatesToClientAndRethrows(): void
    {
        $client = Mockery::mock(XunSearchClient::class);
        $client->shouldReceive('createIndex')->once()->with('posts', [])->andReturn(['created' => true]);

        $this->assertSame(['created' => true], $this->makeEngine($client)->createIndex('posts'));

        $failing = Mockery::mock(XunSearchClient::class);
        $failing->shouldReceive('createIndex')->andThrow(new \RuntimeException('boom'));

        $this->expectException(\RuntimeException::class);
        $this->makeEngine($failing)->createIndex('posts');
    }

    public function testDeleteIndexCleansAndRethrows(): void
    {
        $client = Mockery::mock(XunSearchClient::class);
        [, $index] = $this->mockXs($client);
        $index->shouldReceive('clean')->once();
        $index->shouldReceive('flushIndex')->once();

        $this->makeEngine($client)->deleteIndex('posts');

        $failing = Mockery::mock(XunSearchClient::class);
        [, $index] = $this->mockXs($failing);
        $index->shouldReceive('clean')->andThrow(new \RuntimeException('boom'));

        // errors are logged and rethrown, not swallowed
        $this->expectException(\RuntimeException::class);
        $this->makeEngine($failing)->deleteIndex('posts');
    }

    public function testGetIndexInfoReadsIndexStatsAndReturnsEmptyOnFailure(): void
    {
        $client = Mockery::mock(XunSearchClient::class);
        [, $index] = $this->mockXs($client);
        $index->shouldReceive('getDocCount')->andReturn(100);
        $index->shouldReceive('getTotalTerms')->andReturn(500);
        $index->shouldReceive('getLastError')->andReturn('');
        $index->shouldReceive('getCustomData')->andReturn('data');

        $info = $this->makeEngine($client)->getIndexInfo('posts');
        $this->assertSame(100, $info['doc_count']);
        $this->assertSame('data', $info['custom_data']);

        $failing = Mockery::mock(XunSearchClient::class);
        [, $index] = $this->mockXs($failing);
        $index->shouldReceive('getDocCount')->andThrow(new \RuntimeException('boom'));

        $this->assertSame([], $this->makeEngine($failing)->getIndexInfo('posts'));
    }

    public function testRelatedHotAndSearchLogQueries(): void
    {
        $client = Mockery::mock(XunSearchClient::class);
        [, , $search] = $this->mockXs($client);
        $search->shouldReceive('getRelatedQuery')->with('php', 5)->andReturn(['php1']);
        $search->shouldReceive('getHotQuery')->with(3, 'total')->andReturn(['hot']);
        $search->shouldReceive('getSearchLog')->with(2)->andReturn(['log']);

        $engine = $this->makeEngine($client);

        $this->assertSame(['php1'], $engine->getRelatedQuery('php', 'posts', 5));
        $this->assertSame(['hot'], $engine->getHotQuery('posts', 3));
        $this->assertSame(['log'], $engine->getSearchLog('posts', 2));
    }

    public function testAddSynonymFlushesAndReturnsFalseOnError(): void
    {
        $client = Mockery::mock(XunSearchClient::class);
        [, $index] = $this->mockXs($client);
        $index->shouldReceive('addSynonym')->with('php', 'php8')->once();
        $index->shouldReceive('flushIndex')->once();

        $this->assertTrue($this->makeEngine($client)->addSynonym('posts', 'php', 'php8'));

        $failing = Mockery::mock(XunSearchClient::class);
        [, $index] = $this->mockXs($failing);
        $index->shouldReceive('addSynonym')->andThrow(new \RuntimeException('boom'));

        $this->assertFalse($this->makeEngine($failing)->addSynonym('posts', 'php', 'php8'));
    }

    public function testSearchCacheExpiry(): void
    {
        $engine = $this->makeEngine();

        $engine->setSearchCache('k', 'v', 3600);
        $this->assertSame('v', $engine->getSearchCache('k'));

        $engine->setSearchCache('expired', 'v', -1);
        $this->assertNull($engine->getSearchCache('expired'));
        $this->assertNull($engine->getSearchCache('missing'));
    }

    public function testDynamicCallsAreForwardedToClient(): void
    {
        $client = Mockery::mock(XunSearchClient::class);
        $client->shouldReceive('getSearch')->once()->with()->andReturn(Mockery::mock('XSSearch'));

        $this->assertInstanceOf(\XSSearch::class, $this->makeEngine($client)->getSearch());
    }
}
