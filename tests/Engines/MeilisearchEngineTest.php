<?php

namespace Erikwang2013\WebmanScout\Tests\Engines;

use Erikwang2013\WebmanScout\Builder;
use Erikwang2013\WebmanScout\Engines\MeilisearchEngine;
use Erikwang2013\WebmanScout\Tests\Fixtures\Post;
use Erikwang2013\WebmanScout\Tests\Fixtures\PostSoft;
use Erikwang2013\WebmanScout\Tests\TestCase;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\LazyCollection;
use Meilisearch\Client as MeilisearchClient;
use Meilisearch\Endpoints\Indexes;
use Meilisearch\Exceptions\ApiException;
use Mockery;

class MeilisearchEngineTest extends TestCase
{
    protected function makeEngine($client = null, bool $softDelete = false): MeilisearchEngine
    {
        return new MeilisearchEngine($client ?? Mockery::mock(MeilisearchClient::class), $softDelete);
    }

    protected function rawResults(array $hits, int $totalHits = null): array
    {
        return ['hits' => $hits, 'totalHits' => $totalHits ?? count($hits)];
    }

    protected function expectIndex(Mockery\MockInterface $client, string $uid = 'posts', $primaryKey = null): Mockery\MockInterface
    {
        $index = Mockery::mock(Indexes::class);
        $client->shouldReceive('index')->with($uid)->andReturn($index);

        return $index;
    }

    public function testUpdateAddsDocumentsWithMetadataAndKey(): void
    {
        $client = Mockery::mock(MeilisearchClient::class);
        $index = $this->expectIndex($client);

        $index->shouldReceive('addDocuments')->once()->with(Mockery::on(function ($objects) {
            $this->assertSame([
                ['id' => 1, 'title' => 'One', 'body' => 'a', 'status' => 0],
                ['id' => 2, 'title' => 'Two', 'body' => 'b', 'status' => 0],
            ], $objects);

            return true;
        }), 'id');

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

        $client = Mockery::mock(MeilisearchClient::class);
        $index = $this->expectIndex($client);
        $index->shouldReceive('addDocuments')->never();

        $this->makeEngine($client)->update(new EloquentCollection([new $hidden(['id' => 1])]));
        $this->makeEngine()->update(new EloquentCollection);
    }

    public function testSoftDeleteMetadataIsMergedIntoDocuments(): void
    {
        $client = Mockery::mock(MeilisearchClient::class);
        $index = $this->expectIndex($client);

        $index->shouldReceive('addDocuments')->once()->with(Mockery::on(function ($objects) {
            $this->assertSame(1, $objects[0]['__soft_deleted']);

            return true;
        }), 'id');

        $model = Mockery::mock(PostSoft::class)->makePartial();
        $model->shouldReceive('trashed')->andReturn(true);

        $this->makeEngine($client, true)->update(new EloquentCollection([$model]));
    }

    public function testDeleteRemovesDocumentsByScoutKeys(): void
    {
        $client = Mockery::mock(MeilisearchClient::class);
        $index = $this->expectIndex($client);

        $index->shouldReceive('deleteDocuments')->once()->with([1, 2]);

        $this->makeEngine($client)->delete(new EloquentCollection([
            new Post(['id' => 1]),
            new Post(['id' => 2]),
        ]));

        $this->makeEngine()->delete(new EloquentCollection);
    }

    public function testSearchSendsQueryFiltersAndOptions(): void
    {
        $client = Mockery::mock(MeilisearchClient::class);
        $index = $this->expectIndex($client);

        $index->shouldReceive('rawSearch')->once()->with('foo', Mockery::on(function ($params) {
            $this->assertSame('status=1 AND title="bar" AND id IN [1, 2]', $params['filter']);
            $this->assertSame(5, $params['hitsPerPage']);
            $this->assertSame(['created_at:desc'], $params['sort']);

            return true;
        }))->andReturn($this->rawResults([['id' => 2], ['id' => 1]]));

        $engine = $this->makeEngine($client);
        $builder = (new Builder(new Post, 'foo'))
            ->where('status', 1)
            ->whereIn('id', [1, 2])
            ->where('title', 'bar')
            ->orderBy('created_at', 'desc')
            ->take(5);

        $results = $engine->search($builder);

        $this->assertSame(2, $engine->getTotalCount($results));
        $this->assertSame([2, 1], $engine->mapIds($results)->all());
    }

    public function testFiltersHandleBooleansNullsAndNotIn(): void
    {
        $client = Mockery::mock(MeilisearchClient::class);
        $index = $this->expectIndex($client);

        $index->shouldReceive('rawSearch')->once()->with('', Mockery::on(function ($params) {
            $this->assertSame('active=true AND deleted=false AND note IS NULL AND id NOT IN [3, 4]', $params['filter']);

            return true;
        }))->andReturn($this->rawResults([]));

        $builder = (new Builder(new Post, ''))
            ->where('active', true)
            ->where('deleted', false)
            ->where('note', null)
            ->whereNotIn('id', [3, 4]);

        $this->makeEngine($client)->search($builder);
    }

    public function testSearchEscapesQuotesAndBackslashesInFilterValues(): void
    {
        $client = Mockery::mock(MeilisearchClient::class);
        $index = $this->expectIndex($client);

        $index->shouldReceive('rawSearch')->once()->with('', Mockery::on(function ($params) {
            $this->assertSame('name="say \\"hi\\" \\\\ok"', $params['filter']);

            return true;
        }))->andReturn($this->rawResults([]));

        $this->makeEngine($client)->search((new Builder(new Post, ''))->where('name', 'say "hi" \ok'));
    }

    public function testPaginateSendsPageAndHitsPerPage(): void
    {
        $client = Mockery::mock(MeilisearchClient::class);
        $index = $this->expectIndex($client);

        $index->shouldReceive('rawSearch')->once()->with('foo', Mockery::on(function ($params) {
            $this->assertSame(10, $params['hitsPerPage']);
            $this->assertSame(3, $params['page']);

            return true;
        }))->andReturn($this->rawResults([['id' => 30]], 26));

        $engine = $this->makeEngine($client);
        $results = $engine->paginate(new Builder(new Post, 'foo'), 10, 3);

        $this->assertSame(26, $engine->getTotalCount($results));
    }

    public function testSearchInvokesCallbackWhenSet(): void
    {
        $client = Mockery::mock(MeilisearchClient::class);
        $index = $this->expectIndex($client);
        $index->shouldNotReceive('rawSearch');

        $engine = $this->makeEngine($client);
        $builder = new Builder(new Post, 'foo');
        $builder->options = ['attributesToRetrieve' => ['title']];
        $callbackRan = false;
        $builder->callback = function ($indexArg, $query, $params) use ($index, &$callbackRan) {
            $this->assertSame($index, $indexArg);
            $this->assertSame('foo', $query);
            // primary key is always prepended to attributesToRetrieve
            $this->assertSame(['id', 'title'], $params['attributesToRetrieve']);
            $callbackRan = true;

            return ['hits' => [['id' => 1]], 'totalHits' => 1];
        };

        $this->assertSame(1, $engine->getTotalCount($engine->search($builder)));
        $this->assertTrue($callbackRan);
    }

    public function testMapIdsUsesFirstHitKeyAndHandlesEmptyHits(): void
    {
        $engine = $this->makeEngine();

        $this->assertSame([5, 6], $engine->mapIds($this->rawResults([['id' => 5], ['id' => 6]]))->all());
        $this->assertTrue($engine->mapIds($this->rawResults([]))->isEmpty());
        $this->assertSame([5], $engine->mapIdsFrom($this->rawResults([['scout_id' => 5]]), 'scout_id')->all());
    }

    public function testMapRestoresModelsInOrderAndAppliesMetadata(): void
    {
        $model = Mockery::mock(Post::class)->makePartial();
        $model->shouldReceive('getScoutModelsByIds')->once()->with(Mockery::type(Builder::class), [2, 1])
            ->andReturn(new EloquentCollection([
                (new Post)->newFromBuilder(['id' => 1, 'title' => 'one']),
                (new Post)->newFromBuilder(['id' => 2, 'title' => 'two']),
            ]));

        $engine = $this->makeEngine();
        $results = $this->rawResults([
            ['id' => 2, '_formatted' => ['title' => 'two']],
            ['id' => 1, '_formatted' => ['title' => 'one']],
        ]);

        $mapped = $engine->map(new Builder($model, ''), $results, $model);

        $this->assertSame([2, 1], $mapped->pluck('id')->all());
        $this->assertSame(['title' => 'two'], $mapped->first()->scoutMetadata()['_formatted']);

        $this->assertTrue($engine->map(new Builder($model, ''), $this->rawResults([]), $model)->isEmpty());
    }

    public function testLazyMapRestoresModelsInOrder(): void
    {
        $query = Mockery::mock(\Illuminate\Database\Eloquent\Builder::class)->makePartial();
        $query->shouldReceive('cursor')->andReturn(LazyCollection::empty());

        $model = Mockery::mock(Post::class)->makePartial();
        $model->shouldReceive('queryScoutModelsByIds')->once()->with(Mockery::type(Builder::class), [2, 1])
            ->andReturn($query);

        $engine = $this->makeEngine();

        $lazy = $engine->lazyMap(new Builder($model, ''), $this->rawResults([['id' => 2], ['id' => 1]]), $model);

        $this->assertInstanceOf(LazyCollection::class, $lazy);
        $this->assertTrue($lazy->isEmpty()); // nothing in DB, filter drops all

        $empty = $engine->lazyMap(new Builder($model, ''), $this->rawResults([]), $model);
        $this->assertInstanceOf(LazyCollection::class, $empty);
    }

    public function testGetTotalCountPrefersTotalHits(): void
    {
        $engine = $this->makeEngine();

        $this->assertSame(9, $engine->getTotalCount(['hits' => [], 'totalHits' => 9, 'estimatedTotalHits' => 99]));
        $this->assertSame(4, $engine->getTotalCount(['hits' => [], 'estimatedTotalHits' => 4]));
    }

    public function testFlushDeletesAllDocuments(): void
    {
        $client = Mockery::mock(MeilisearchClient::class);
        $index = $this->expectIndex($client);
        $index->shouldReceive('deleteAllDocuments')->once();

        $this->makeEngine($client)->flush(new Post);
    }

    public function testCreateIndexReusesExistingIndex(): void
    {
        $client = Mockery::mock(MeilisearchClient::class);
        $existing = Mockery::mock(Indexes::class);
        $existing->shouldReceive('getUid')->andReturn('posts');
        $client->shouldReceive('getIndex')->with('posts')->andReturn($existing);
        $client->shouldNotReceive('createIndex');

        $this->assertSame($existing, $this->makeEngine($client)->createIndex('posts'));
    }

    public function testCreateIndexCreatesWhenMissing(): void
    {
        $client = Mockery::mock(MeilisearchClient::class);
        $client->shouldReceive('getIndex')->with('posts')->andThrow(Mockery::mock(ApiException::class));
        $client->shouldReceive('createIndex')->once()->with('posts', ['primaryKey' => 'id'])->andReturn(['taskUid' => 7]);

        $this->assertSame(['taskUid' => 7], $this->makeEngine($client)->createIndex('posts', ['primaryKey' => 'id']));
    }

    public function testUpdateIndexSettingsSplitsEmbedders(): void
    {
        $client = Mockery::mock(MeilisearchClient::class);
        $index = $this->expectIndex($client);

        $index->shouldReceive('updateSettings')->once()->with(['searchableAttributes' => ['title']]);
        $index->shouldReceive('updateEmbedders')->once()->with(['default' => ['source' => 'huggingFace']]);

        $this->makeEngine($client)->updateIndexSettings('posts', [
            'searchableAttributes' => ['title'],
            'embedders' => ['default' => ['source' => 'huggingFace']],
        ]);
    }

    public function testConfigureSoftDeleteFilterAppendsFilterableAttribute(): void
    {
        $settings = $this->makeEngine()->configureSoftDeleteFilter([
            'filterableAttributes' => ['status'],
        ]);

        $this->assertSame(['status', '__soft_deleted'], $settings['filterableAttributes']);
    }

    public function testDeleteIndexAndDeleteAllIndexes(): void
    {
        $client = Mockery::mock(MeilisearchClient::class);
        $client->shouldReceive('deleteIndex')->once()->with('posts')->andReturn(['taskUid' => 8]);
        $this->assertSame(['taskUid' => 8], $this->makeEngine($client)->deleteIndex('posts'));

        $client2 = Mockery::mock(MeilisearchClient::class);
        $index = Mockery::mock(Indexes::class);
        $index->shouldReceive('delete')->andReturn(['taskUid' => 1]);
        $collection = new \Meilisearch\Contracts\IndexesResults([
            'results' => [$index],
            'offset' => 0,
            'limit' => 20,
        ]);
        $client2->shouldReceive('getIndexes')->once()->with(Mockery::type(\Meilisearch\Contracts\IndexesQuery::class))
            ->andReturn($collection);

        $this->assertSame([['taskUid' => 1]], $this->makeEngine($client2)->deleteAllIndexes());
    }

    public function testDynamicCallsAreForwardedToClient(): void
    {
        $client = Mockery::mock(MeilisearchClient::class);
        $client->shouldReceive('health')->once()->with()->andReturn(['status' => 'available']);

        $this->assertSame(['status' => 'available'], $this->makeEngine($client)->health());
    }
}
