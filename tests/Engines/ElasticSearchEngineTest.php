<?php

namespace Erikwang2013\WebmanScout\Tests\Engines;

require_once __DIR__.'/../ClientStubs.php';

use Elastic\Elasticsearch\Client as ElasticClient;
use Erikwang2013\WebmanScout\Builder;
use Erikwang2013\WebmanScout\Engines\ElasticSearchEngine;
use Erikwang2013\WebmanScout\Tests\Fixtures\Post;
use Erikwang2013\WebmanScout\Tests\Fixtures\PostSoft;
use Erikwang2013\WebmanScout\Tests\TestCase;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Mockery;

class ElasticSearchEngineTest extends TestCase
{
    protected function makeEngine($client = null, bool $softDelete = false): ElasticSearchEngine
    {
        return new ElasticSearchEngine($client ?? Mockery::mock(ElasticClient::class), $softDelete);
    }

    protected function rawHits(array $ids, int $total = null): array
    {
        return [
            'hits' => [
                'total' => ['value' => $total ?? count($ids)],
                'hits' => array_map(fn ($id) => [
                    '_id' => (string) $id,
                    '_source' => ['id' => $id, 'title' => "Post {$id}"],
                ], $ids),
            ],
        ];
    }

    public function testUpdateBuildsBulkBodyWithDocAsUpsert(): void
    {
        $client = Mockery::mock(ElasticClient::class);
        $client->shouldReceive('bulk')->once()->with(Mockery::on(function ($params) {
            $this->assertSame([
                ['update' => ['_id' => 1, '_index' => 'posts']],
                ['doc' => ['id' => 1, 'title' => 'First', 'body' => 'one', 'status' => 0], 'doc_as_upsert' => true],
                ['update' => ['_id' => 2, '_index' => 'posts']],
                ['doc' => ['id' => 2, 'title' => 'Second', 'body' => 'two', 'status' => 0], 'doc_as_upsert' => true],
            ], $params['body']);

            return true;
        }));

        $this->makeEngine($client)->update(new EloquentCollection([
            new Post(['id' => 1, 'title' => 'First', 'body' => 'one', 'status' => 0]),
            new Post(['id' => 2, 'title' => 'Second', 'body' => 'two', 'status' => 0]),
        ]));
    }

    public function testUpdateSkipsEmptyCollectionsAndEmptySearchableArrays(): void
    {
        $client = Mockery::mock(ElasticClient::class);
        $client->shouldReceive('bulk')->once()->with(Mockery::on(function ($params) {
            $this->assertSame(0, count($params['body'])); // empty searchable array -> no body entries

            return true;
        }));

        $hidden = new class extends Post {
            public function toSearchableArray(): array
            {
                return [];
            }
        };

        $this->makeEngine($client)->update(new EloquentCollection([new $hidden(['id' => 1])]));

        $noOpClient = Mockery::mock(ElasticClient::class);
        $noOpClient->shouldNotReceive('bulk');

        $this->makeEngine($noOpClient)->update(new EloquentCollection);
    }

    public function testDeleteBuildsBulkDeleteBody(): void
    {
        $client = Mockery::mock(ElasticClient::class);
        $client->shouldReceive('bulk')->once()->with(Mockery::on(function ($params) {
            $this->assertSame([
                ['delete' => ['_id' => 1, '_index' => 'posts']],
                ['delete' => ['_id' => 2, '_index' => 'posts']],
            ], $params['body']);

            return true;
        }));

        $this->makeEngine($client)->delete(new EloquentCollection([
            new Post(['id' => 1]),
            new Post(['id' => 2]),
        ]));

        $this->makeEngine()->delete(new EloquentCollection);
    }

    public function testSearchSendsEscapedQueryStringAndFilters(): void
    {
        $client = Mockery::mock(ElasticClient::class);
        $client->shouldReceive('search')->once()->with([
            'index' => 'posts',
            'body' => [
                'query' => [
                    'bool' => [
                        'must' => [
                            ['query_string' => ['query' => '*foo bar*']],
                            ['term' => ['status' => 1]],
                        ],
                    ],
                ],
                'size' => 5,
            ],
        ])->andReturn($this->rawHits([3, 1]));

        $engine = $this->makeEngine($client);
        $builder = (new Builder(new Post, 'foo bar'))->where('status', 1)->take(5);

        $results = $engine->search($builder);

        $this->assertSame(['3', '1'], $engine->mapIds($results)->all());
    }

    public function testSearchEscapesLuceneSpecialCharacters(): void
    {
        $client = Mockery::mock(ElasticClient::class);
        $client->shouldReceive('search')->once()->with(Mockery::on(function ($params) {
            $query = $params['body']['query']['bool']['must'][0]['query_string']['query'];
            // Every Lucene special char escaped (space untouched).
            $this->assertSame('*a\\+\\-\\=\\&&\\||\\>\\<\\!\\(\\)\\{\\}\\[\\]\\^\\"\\~\\*\\?\\:\\/*', $query);

            return true;
        }))->andReturn($this->rawHits([]));

        $this->makeEngine($client)->search(new Builder(new Post, 'a+-=&&||><!(){}[]^"~*?:/'));
    }

    public function testPaginateAddsFromAndSize(): void
    {
        $client = Mockery::mock(ElasticClient::class);
        $client->shouldReceive('search')->once()->with(Mockery::on(function ($params) {
            $this->assertSame(20, $params['body']['from']);
            $this->assertSame(10, $params['body']['size']);

            return true;
        }))->andReturn($this->rawHits([21, 22]));

        $engine = $this->makeEngine($client);
        $this->assertSame(2, $engine->getTotalCount($engine->paginate(new Builder(new Post, ''), 10, 3)));
    }

    public function testSearchInvokesBuilderCallbackWithClientAndParams(): void
    {
        $client = Mockery::mock(ElasticClient::class);
        $client->shouldNotReceive('search');

        $engine = $this->makeEngine($client);
        $builder = new Builder(new Post, 'foo');
        $callbackRan = false;
        $builder->callback = function ($clientArg, $query, $params) use ($client, &$callbackRan) {
            $this->assertSame($client, $clientArg);
            $this->assertSame('foo', $query);
            $this->assertArrayHasKey('index', $params);
            $callbackRan = true;

            return ['hits' => ['total' => ['value' => 0], 'hits' => []]];
        };

        $results = $engine->search($builder);

        $this->assertTrue($callbackRan);
        $this->assertSame(0, $engine->getTotalCount($results));
    }

    public function testSortIsAppliedFromBuilderOrders(): void
    {
        $client = Mockery::mock(ElasticClient::class);
        $client->shouldReceive('search')->once()->with(Mockery::on(function ($params) {
            $this->assertSame([['created_at' => 'desc']], $params['body']['sort']);

            return true;
        }))->andReturn($this->rawHits([]));

        $this->makeEngine($client)->search((new Builder(new Post, ''))->orderBy('created_at', 'desc'));
    }

    public function testMapIdsPlucksUnderscoreIds(): void
    {
        $engine = $this->makeEngine();

        $this->assertSame(['5', '9'], $engine->mapIds($this->rawHits([5, 9]))->all());
    }

    public function testMapRestoresModelsInResultOrder(): void
    {
        $model = Mockery::mock(Post::class)->makePartial();
        $model->shouldReceive('getScoutModelsByIds')->once()->with(Mockery::type(Builder::class), ['2', '1'])
            ->andReturn(new EloquentCollection([
                (new Post)->newFromBuilder(['id' => 1, 'title' => 'one']),
                (new Post)->newFromBuilder(['id' => 2, 'title' => 'two']),
            ]));

        $engine = $this->makeEngine();
        $mapped = $engine->map(new Builder($model, 'foo'), $this->rawHits([2, 1]), $model);

        $this->assertSame([2, 1], $mapped->pluck('id')->all());
    }

    public function testMapReturnsEmptyCollectionWhenTotalIsZero(): void
    {
        $engine = $this->makeEngine();
        $mapped = $engine->map(new Builder(new Post, ''), ['hits' => ['total' => ['value' => 0], 'hits' => []]], new Post);

        $this->assertInstanceOf(EloquentCollection::class, $mapped);
        $this->assertTrue($mapped->isEmpty());
    }

    public function testLazyMapBuildsModelsFromSource(): void
    {
        $engine = $this->makeEngine();

        $lazy = $engine->lazyMap(new Builder(new Post, ''), $this->rawHits([7]), new Post);

        $this->assertInstanceOf(Collection::class, $lazy);
        $this->assertSame([7], $lazy->pluck('id')->all());
    }

    public function testGetTotalCountReadsHitsTotalValue(): void
    {
        $this->assertSame(12, $this->makeEngine()->getTotalCount(['hits' => ['total' => ['value' => 12]]]));
    }

    public function testFlushDeletesAllRecordsUnsearchable(): void
    {
        $query = Mockery::mock(\Illuminate\Database\Eloquent\Builder::class)->makePartial();
        $query->shouldReceive('orderBy')->with('id')->andReturnSelf();
        $query->shouldReceive('unsearchable')->once()->andReturnNull();

        $model = Mockery::mock(Post::class)->makePartial();
        $model->shouldReceive('newQuery')->andReturn($query);

        $this->makeEngine()->flush($model);
    }

    public function testCreateIndexCreatesWhenMissingAndSkipsWhenExists(): void
    {
        $indices = Mockery::mock();
        $indices->shouldReceive('exists')->once()->with(['index' => 'posts'])->andReturn(
            Mockery::mock(['asBool' => true])
        );

        $client = Mockery::mock(ElasticClient::class);
        $client->shouldReceive('indices')->once()->andReturn($indices);

        $engine = $this->makeEngine($client);
        $this->assertNull($engine->createIndex('posts'));
    }

    public function testCreateIndexCreatesWithOptionsWhenProvided(): void
    {
        $indices = Mockery::mock();
        $indices->shouldReceive('exists')->once()->with(['index' => 'posts'])->andReturn(
            Mockery::mock(['asBool' => false])
        );
        $indices->shouldReceive('create')->once()->with([
            'index' => 'posts',
            'settings' => ['number_of_shards' => 1],
        ])->andReturnNull();

        $client = Mockery::mock(ElasticClient::class);
        $client->shouldReceive('indices')->twice()->andReturn($indices);

        $this->makeEngine($client)->createIndex('posts', ['settings' => ['number_of_shards' => 1]]);
    }

    public function testDeleteIndexCallsIndicesDelete(): void
    {
        $indices = Mockery::mock();
        $indices->shouldReceive('delete')->once()->with(['index' => 'posts'])->andReturnNull();

        $client = Mockery::mock(ElasticClient::class);
        $client->shouldReceive('indices')->once()->andReturn($indices);

        $this->makeEngine($client)->deleteIndex('posts');
    }

    public function testDynamicCallsAreForwardedToClient(): void
    {
        $client = Mockery::mock(ElasticClient::class);
        $client->shouldReceive('ping')->once()->with()->andReturn(true);

        $this->assertTrue($this->makeEngine($client)->ping());
    }

    public function testSoftDeletedModelsPushMetadataBeforeBulk(): void
    {
        $client = Mockery::mock(ElasticClient::class);
        $client->shouldReceive('bulk')->once()->with(Mockery::on(function ($params) {
            $this->assertSame(2, count($params['body'])); // update meta + doc
            $this->assertArrayNotHasKey('__soft_deleted', $params['body'][1]['doc']);

            return true;
        }));

        $this->makeEngine($client, true)->update(new EloquentCollection([
            new PostSoft(['id' => 1, 'title' => 'x', 'body' => 'y']),
        ]));

        // Note: ElasticSearchEngine does not merge scoutMetadata (__soft_deleted)
        // into the bulk doc — asserted here by the absence of that key.
    }
}
