<?php

namespace Erikwang2013\WebmanScout\Tests\Engines;

use Algolia\AlgoliaSearch\Api\SearchClient as Algolia4SearchClient;
use Erikwang2013\WebmanScout\Builder;
use Erikwang2013\WebmanScout\Engines\Algolia4Engine;
use Erikwang2013\WebmanScout\Tests\Fixtures\Post;
use Erikwang2013\WebmanScout\Tests\Fixtures\PostSoft;
use Erikwang2013\WebmanScout\Tests\TestCase;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Mockery;

class Algolia4EngineTest extends TestCase
{
    protected function makeEngine($client = null, bool $softDelete = false): Algolia4Engine
    {
        return new Algolia4Engine($client ?? Mockery::mock(Algolia4SearchClient::class), $softDelete);
    }

    public function testUpdateCallsSaveObjectsWithIndexNameAndObjects(): void
    {
        $client = Mockery::mock(Algolia4SearchClient::class);
        $client->shouldReceive('saveObjects')->once()->with('posts', Mockery::on(function ($objects) {
            $this->assertSame([
                ['id' => 1, 'title' => 'One', 'body' => 'a', 'status' => 0, 'objectID' => 1],
                ['id' => 2, 'title' => 'Two', 'body' => 'b', 'status' => 0, 'objectID' => 2],
            ], $objects);

            return true;
        }));

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

        $client = Mockery::mock(Algolia4SearchClient::class);
        $client->shouldReceive('saveObjects')->never();

        $this->makeEngine($client)->update(new EloquentCollection([new $hidden(['id' => 1])]));
        $this->makeEngine()->update(new EloquentCollection);
    }

    public function testUpdateMergesSoftDeleteMetadataWhenEnabled(): void
    {
        $client = Mockery::mock(Algolia4SearchClient::class);
        $client->shouldReceive('saveObjects')->once()->with('posts', Mockery::on(function ($objects) {
            $this->assertSame(1, $objects[0]['__soft_deleted']);

            return true;
        }));

        $model = Mockery::mock(PostSoft::class)->makePartial();
        $model->shouldReceive('trashed')->andReturn(true);

        $this->makeEngine($client, true)->update(new EloquentCollection([$model]));
    }

    public function testDeleteCallsDeleteObjectsWithIndexName(): void
    {
        $client = Mockery::mock(Algolia4SearchClient::class);
        $client->shouldReceive('deleteObjects')->once()->with('posts', [1, 2]);

        $this->makeEngine($client)->delete(new EloquentCollection([
            new Post(['id' => 1]),
            new Post(['id' => 2]),
        ]));

        $noOpClient = Mockery::mock(Algolia4SearchClient::class);
        $noOpClient->shouldNotReceive('deleteObjects');
        $this->makeEngine($noOpClient)->delete(new EloquentCollection);
    }

    public function testDeleteIndexFlushAndUpdateSettings(): void
    {
        $client = Mockery::mock(Algolia4SearchClient::class);
        $client->shouldReceive('deleteIndex')->once()->with('posts')->andReturn(['deletedAt' => 'now']);
        $this->assertSame(['deletedAt' => 'now'], $this->makeEngine($client)->deleteIndex('posts'));

        $client->shouldReceive('clearObjects')->once()->with('posts');
        $this->makeEngine($client)->flush(new Post);

        $client->shouldReceive('setSettings')->once()->with('posts', ['searchableAttributes' => ['title']]);
        $this->makeEngine($client)->updateIndexSettings('posts', ['searchableAttributes' => ['title']]);
    }

    public function testSearchUsesSearchSingleIndexWithQueryInParams(): void
    {
        $client = Mockery::mock(Algolia4SearchClient::class);
        $client->shouldReceive('searchSingleIndex')->once()->with('posts', Mockery::on(function ($params) {
            $this->assertSame('foo', $params['query']);
            $this->assertSame(['status=1'], $params['numericFilters']);
            $this->assertSame(5, $params['hitsPerPage']);

            return true;
        }))->andReturn(['hits' => [['objectID' => '1']], 'nbHits' => 3]);

        $engine = $this->makeEngine($client);
        $results = $engine->search((new Builder(new Post, 'foo'))->where('status', 1)->take(5));

        $this->assertSame(3, $engine->getTotalCount($results));
    }

    public function testSearchInvokesCallbackWithClient(): void
    {
        $client = Mockery::mock(Algolia4SearchClient::class);
        $client->shouldNotReceive('searchSingleIndex');

        $engine = $this->makeEngine($client);
        $builder = new Builder(new Post, 'foo');
        $callbackRan = false;
        $builder->callback = function ($clientArg, $query, $options) use ($client, &$callbackRan) {
            $this->assertSame($client, $clientArg);
            $this->assertSame('foo', $query);
            $callbackRan = true;

            return ['hits' => [], 'nbHits' => 0];
        };

        $engine->search($builder);
        $this->assertTrue($callbackRan);
    }

    public function testPaginatePassesZeroBasedPage(): void
    {
        $client = Mockery::mock(Algolia4SearchClient::class);
        $client->shouldReceive('searchSingleIndex')->once()->with('posts', Mockery::on(function ($params) {
            $this->assertSame(20, $params['hitsPerPage']);
            $this->assertSame(2, $params['page']);

            return true;
        }))->andReturn(['hits' => [], 'nbHits' => 0]);

        $this->makeEngine($client)->paginate(new Builder(new Post, 'foo'), 20, 3);
    }
}
