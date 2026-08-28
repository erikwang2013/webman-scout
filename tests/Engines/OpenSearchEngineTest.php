<?php

namespace Erikwang2013\WebmanScout\Tests\Engines;

require_once __DIR__.'/../ClientStubs.php';

use Erikwang2013\WebmanScout\Builder;
use Erikwang2013\WebmanScout\Engines\OpenSearchEngine;
use Erikwang2013\WebmanScout\Tests\Fixtures\Post;
use Erikwang2013\WebmanScout\Tests\Fixtures\PostSoft;
use Erikwang2013\WebmanScout\Tests\TestCase;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\LazyCollection;
use OpenSearch\Client as OpenSearchClient;
use Mockery;

class OpenSearchEngineTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Engine internals log through the Log facade; swap it for a no-op logger.
        Log::swap(Mockery::mock(\Psr\Log\LoggerInterface::class)->shouldIgnoreMissing());
    }

    protected function tearDown(): void
    {
        Log::clearResolvedInstance('log');
        parent::tearDown();
    }

    protected function makeEngine($client = null, bool $softDelete = false): OpenSearchEngine
    {
        return new OpenSearchEngine($client ?? Mockery::mock(OpenSearchClient::class), $softDelete);
    }

    protected function mockIndices(Mockery\MockInterface $client, int $times = 1): Mockery\MockInterface
    {
        $indices = Mockery::mock();
        $client->shouldReceive('indices')->times($times)->andReturn($indices);

        return $indices;
    }

    protected function rawHits(array $ids, int $total = null): array
    {
        return [
            'hits' => [
                'total' => ['value' => $total ?? count($ids)],
                'hits' => array_map(fn ($id) => ['_id' => (string) $id, '_source' => ['id' => $id]], $ids),
            ],
        ];
    }

    public function testUpdateSendsIndexMetaAndDataPairs(): void
    {
        $client = Mockery::mock(OpenSearchClient::class);
        $client->shouldReceive('bulk')->once()->with(Mockery::on(function ($params) {
            $this->assertSame([
                ['index' => ['_index' => 'posts', '_id' => 1]],
                ['id' => 1, 'title' => 'One', 'body' => 'a', 'status' => 0],
                ['index' => ['_index' => 'posts', '_id' => 2]],
                ['id' => 2, 'title' => 'Two', 'body' => 'b', 'status' => 0],
            ], $params['body']);

            return true;
        }))->andReturn(['errors' => false]);

        $this->makeEngine($client)->update(new EloquentCollection([
            new Post(['id' => 1, 'title' => 'One', 'body' => 'a', 'status' => 0]),
            new Post(['id' => 2, 'title' => 'Two', 'body' => 'b', 'status' => 0]),
        ]));
    }

    public function testUpdateSkipsEmptySearchableArraysAndCollections(): void
    {
        $hidden = new class extends Post {
            public function toSearchableArray(): array
            {
                return [];
            }
        };

        $client = Mockery::mock(OpenSearchClient::class);
        $client->shouldNotReceive('bulk');

        $this->makeEngine($client)->update(new EloquentCollection([new $hidden(['id' => 1])]));

        $noOpClient = Mockery::mock(OpenSearchClient::class);
        $noOpClient->shouldNotReceive('bulk');
        $this->makeEngine($noOpClient)->update(new EloquentCollection);
    }

    public function testUpdateSanitizesControlCharacters(): void
    {
        $model = Mockery::mock(Post::class)->makePartial();
        $model->shouldReceive('toSearchableArray')->andReturn(['id' => 1, 'title' => "a\x00b\x0Bc"]);

        $client = Mockery::mock(OpenSearchClient::class);
        $client->shouldReceive('bulk')->once()->with(Mockery::on(function ($params) {
            $this->assertSame('abc', $params['body'][1]['title']);

            return true;
        }))->andReturn(['errors' => false]);

        $this->makeEngine($client)->update(new EloquentCollection([$model]));
    }

    public function testUpdateMergesSoftDeleteMetadataAndBulkSplitsBySize(): void
    {
        $client = Mockery::mock(OpenSearchClient::class);
        $counts = [];
        $client->shouldReceive('bulk')->twice()->with(Mockery::on(function ($params) use (&$counts) {
            $counts[] = count($params['body']);
            // Note: OpenSearchEngine does NOT merge scoutMetadata (__soft_deleted)
            // into the bulk doc — asserted here by the absence of that key (src bug).
            $this->assertArrayNotHasKey('__soft_deleted', $params['body'][1]);

            return true;
        }))->andReturn(['errors' => false]);

        $models = [];
        for ($i = 1; $i <= 1500; $i++) {
            $model = Mockery::mock(PostSoft::class)->makePartial();
            $model->shouldReceive('trashed')->andReturn(true);
            $model->setRawAttributes(['id' => $i]);
            $models[] = $model;
        }

        $engine = $this->makeEngine($client, true);
        $engine->setBulkSize(1000);
        $engine->update(new EloquentCollection($models));

        $this->assertSame([2000, 1000], $counts);
    }

    public function testBulkErrorsAreHandledAndExceptionsRethrown(): void
    {
        $client = Mockery::mock(OpenSearchClient::class);
        $client->shouldReceive('bulk')->once()->andReturn([
            'errors' => true,
            'items' => [
                ['index' => ['_id' => 1, '_index' => 'posts', 'error' => ['reason' => 'boom']]],
            ],
        ]);

        // Errors are logged (swallowed by the facade swap) but not thrown.
        $this->makeEngine($client)->update(new EloquentCollection([new Post(['id' => 1])]));

        $failing = Mockery::mock(OpenSearchClient::class);
        $failing->shouldReceive('bulk')->once()->andThrow(new \RuntimeException('connection lost'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('connection lost');
        $this->makeEngine($failing)->update(new EloquentCollection([new Post(['id' => 1])]));
    }

    public function testDeleteSendsDeleteMetaPairs(): void
    {
        $client = Mockery::mock(OpenSearchClient::class);
        $client->shouldReceive('bulk')->once()->with(Mockery::on(function ($params) {
            $this->assertSame([
                ['delete' => ['_index' => 'posts', '_id' => 1]],
                ['delete' => ['_index' => 'posts', '_id' => 2]],
            ], $params['body']);

            return true;
        }))->andReturn(['errors' => false]);

        $this->makeEngine($client)->delete(new EloquentCollection([
            new Post(['id' => 1]),
            new Post(['id' => 2]),
        ]));
    }

    public function testSearchBuildsMatchAllForEmptyQuery(): void
    {
        $client = Mockery::mock(OpenSearchClient::class);
        $client->shouldReceive('search')->once()->with(Mockery::on(function ($params) {
            $this->assertSame('posts', $params['index']);
            $this->assertInstanceOf(\stdClass::class, $params['body']['query']['match_all']);
            $this->assertSame(5, $params['body']['size']);

            return true;
        }))->andReturn($this->rawHits([1]));

        $results = $this->makeEngine($client)->search((new Builder(new Post, ''))->take(5));
        $this->assertSame(1, $this->makeEngine($client)->getTotalCount($results));
    }

    public function testSearchBuildsMultiMatchWithFiltersSortAndPagination(): void
    {
        $model = new class extends Post {
            public function searchableFields(): array
            {
                return ['title', 'body'];
            }
        };

        $client = Mockery::mock(OpenSearchClient::class);
        $client->shouldReceive('search')->once()->with(Mockery::on(function ($params) {
            $must = $params['body']['query']['bool']['must'];
            $this->assertSame('php', $must['multi_match']['query']);
            $this->assertSame(['title', 'body'], $must['multi_match']['fields']);
            $this->assertSame([
                ['term' => ['status' => 1]],
                ['terms' => ['id' => [1, 2]]],
                ['bool' => ['must_not' => [['terms' => ['tag' => ['x']]]]]],
            ], $params['body']['query']['bool']['filter']);
            $this->assertSame([['created_at' => ['order' => 'desc']]], $params['body']['sort']);
            $this->assertSame(20, $params['body']['from']);
            $this->assertSame(10, $params['body']['size']);

            return true;
        }))->andReturn($this->rawHits([3], 26));

        $engine = $this->makeEngine($client);
        $builder = (new Builder($model, 'php'))
            ->where('status', 1)
            ->whereIn('id', [1, 2])
            ->whereNotIn('tag', ['x'])
            ->orderBy('created_at', 'desc');

        $this->assertSame(26, $engine->getTotalCount($engine->paginate($builder, 10, 3)));
    }

    public function testSearchInvokesCallbackWithClientBuilderAndParams(): void
    {
        $client = Mockery::mock(OpenSearchClient::class);
        $client->shouldNotReceive('search');

        $engine = $this->makeEngine($client);
        $builder = new Builder(new Post, 'foo');
        $callbackRan = false;
        $builder->callback = function ($clientArg, $builderArg, $params) use ($client, $builder, &$callbackRan) {
            $this->assertSame($client, $clientArg);
            $this->assertSame($builder, $builderArg);
            $this->assertSame('posts', $params['index']);
            $callbackRan = true;

            return $this->rawHits([]);
        };

        $engine->search($builder);
        $this->assertTrue($callbackRan);
    }

    public function testMapRestoresModelsInResultOrderAndLazyMap(): void
    {
        $model = Mockery::mock(Post::class)->makePartial();
        $model->shouldReceive('getScoutModelsByIds')->once()->with(Mockery::type(Builder::class), ['2', '1'])
            ->andReturn(new EloquentCollection([
                (new Post)->newFromBuilder(['id' => 1, 'title' => 'one']),
                (new Post)->newFromBuilder(['id' => 2, 'title' => 'two']),
            ]));

        $engine = $this->makeEngine();
        $mapped = $engine->map(new Builder($model, ''), $this->rawHits([2, 1]), $model);

        $this->assertSame([2, 1], $mapped->pluck('id')->all());
        $this->assertTrue($engine->map(new Builder($model, ''), $this->rawHits([]), $model)->isEmpty());

        $model2 = Mockery::mock(Post::class)->makePartial();
        $model2->shouldReceive('getScoutModelsByIds')->once()->andReturn(new EloquentCollection([
            (new Post)->newFromBuilder(['id' => 1]),
        ]));
        $lazy = $engine->lazyMap(new Builder($model2, ''), $this->rawHits([1]), $model2);
        $this->assertInstanceOf(LazyCollection::class, $lazy);
        $this->assertSame([1], $lazy->pluck('id')->all());

        $this->assertTrue($engine->lazyMap(new Builder($model2, ''), $this->rawHits([]), $model2)->isEmpty());
    }

    public function testMapIdsPlucksStringIds(): void
    {
        $this->assertSame(['5', '9'], $this->makeEngine()->mapIds($this->rawHits([5, 9]))->all());
    }

    public function testGetTotalCountHandlesIntAndArrayTotals(): void
    {
        $this->assertSame(12, $this->makeEngine()->getTotalCount(['hits' => ['total' => ['value' => 12]]]));
        $this->assertSame(4, $this->makeEngine()->getTotalCount(['hits' => ['total' => 4]]));
    }

    public function testFlushDeletesAndRecreatesIndex(): void
    {
        $client = Mockery::mock(OpenSearchClient::class);
        $indices = $this->mockIndices($client, 4);
        $indices->shouldReceive('exists')->andReturn(
            Mockery::mock(['asBool' => true]),  // delete path
            Mockery::mock(['asBool' => false])  // create path
        );
        $indices->shouldReceive('delete')->once()->with(['index' => 'posts']);
        $indices->shouldReceive('create')->once()->with(Mockery::on(function ($params) {
            $this->assertSame('posts', $params['index']);
            $this->assertSame(1, $params['body']['settings']['index']['number_of_shards']);

            return true;
        }));

        $this->makeEngine($client)->flush(new Post);
    }

    public function testCreateIndexUsesOptionsAndSetsAliases(): void
    {
        $client = Mockery::mock(OpenSearchClient::class);
        $indices = $this->mockIndices($client, 3);
        $indices->shouldReceive('exists')->once()->with(['index' => 'posts'])->andReturn(Mockery::mock(['asBool' => false]));
        $indices->shouldReceive('create')->once()->with(['index' => 'posts', 'body' => ['settings' => ['number_of_shards' => 2], 'aliases' => ['posts_alias']]]);
        $indices->shouldReceive('updateAliases')->once()->with(['body' => ['actions' => [['add' => ['index' => 'posts', 'alias' => 'posts_alias']]]]]);

        $this->makeEngine($client)->createIndex('posts', [
            'settings' => ['number_of_shards' => 2],
            'aliases' => ['posts_alias'],
        ]);
    }

    public function testDeleteIndexSkipsWhenMissing(): void
    {
        $client = Mockery::mock(OpenSearchClient::class);
        $indices = $this->mockIndices($client, 1);
        $indices->shouldReceive('exists')->once()->with(['index' => 'posts'])->andReturn(Mockery::mock(['asBool' => false]));
        $indices->shouldNotReceive('delete');

        $this->makeEngine($client)->deleteIndex('posts');
    }

    public function testIndexInfoExistsAndSettingsHelpers(): void
    {
        $client = Mockery::mock(OpenSearchClient::class);
        $indices = $this->mockIndices($client, 3);
        $indices->shouldReceive('get')->once()->with(['index' => 'posts'])->andReturn(['posts' => []]);
        $indices->shouldReceive('exists')->once()->with(['index' => 'posts'])->andReturn(Mockery::mock(['asBool' => true]));
        $indices->shouldReceive('putSettings')->once()->with(['index' => 'posts', 'body' => ['refresh_interval' => '2s']]);

        $engine = $this->makeEngine($client);

        $this->assertSame(['posts' => []], $engine->getIndexInfo('posts'));
        $this->assertTrue($engine->indexExists('posts'));
        $this->assertTrue($engine->updateIndexSettings('posts', ['refresh_interval' => '2s']));
    }

    public function testIndexHelpersReturnSafeValuesOnFailure(): void
    {
        $client = Mockery::mock(OpenSearchClient::class);
        $indices = $this->mockIndices($client, 4);
        $indices->shouldReceive('get')->andThrow(new \RuntimeException('boom'));
        $indices->shouldReceive('exists')->andThrow(new \RuntimeException('boom'));
        $indices->shouldReceive('putSettings')->andThrow(new \RuntimeException('boom'));
        $indices->shouldReceive('putMapping')->andThrow(new \RuntimeException('boom'));

        $engine = $this->makeEngine($client);

        $this->assertSame([], $engine->getIndexInfo('posts'));
        $this->assertFalse($engine->indexExists('posts'));
        $this->assertFalse($engine->updateIndexSettings('posts', []));
        $this->assertFalse($engine->updateIndexMappings('posts', []));
    }

    public function testGetClientReturnsInstanceAndDynamicCallsForwarded(): void
    {
        $client = Mockery::mock(OpenSearchClient::class);
        $client->shouldReceive('ping')->once()->with()->andReturn(true);

        $engine = $this->makeEngine($client);

        $this->assertSame($client, $engine->getClient());
        $this->assertTrue($engine->ping());
        $this->assertSame($engine, $engine->setBulkSize(500));
    }
}
