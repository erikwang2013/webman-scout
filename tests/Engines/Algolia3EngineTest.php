<?php

namespace Erikwang2013\WebmanScout\Tests\Engines;

require_once __DIR__.'/../ClientStubs.php';

use Algolia\AlgoliaSearch\SearchClient as Algolia3SearchClient;
use Erikwang2013\WebmanScout\Builder;
use Erikwang2013\WebmanScout\Engines\Algolia3Engine;
use Erikwang2013\WebmanScout\Tests\Fixtures\Post;
use Erikwang2013\WebmanScout\Tests\Fixtures\PostSoft;
use Erikwang2013\WebmanScout\Tests\TestCase;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Mockery;

class Algolia3EngineTest extends TestCase
{
    protected function makeEngine($client = null, bool $softDelete = false): Algolia3Engine
    {
        return new Algolia3Engine($client ?? Mockery::mock(Algolia3SearchClient::class), $softDelete);
    }

    protected function mockIndex(Mockery\MockInterface $client, string $indexName = 'posts'): Mockery\MockInterface
    {
        $index = Mockery::mock();
        $client->shouldReceive('initIndex')->with($indexName)->andReturn($index);

        return $index;
    }

    public function testUpdateSavesObjectsMergedWithMetadataAndObjectId(): void
    {
        $client = Mockery::mock(Algolia3SearchClient::class);
        $index = $this->mockIndex($client);

        $index->shouldReceive('saveObjects')->once()->with(Mockery::on(function ($objects) {
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

    public function testUpdateSkipsEmptySearchableArraysAndEmptyCollections(): void
    {
        $hidden = new class extends Post {
            public function toSearchableArray(): array
            {
                return [];
            }
        };

        $client = Mockery::mock(Algolia3SearchClient::class);
        $index = $this->mockIndex($client);
        $index->shouldReceive('saveObjects')->never();

        $this->makeEngine($client)->update(new EloquentCollection([new $hidden(['id' => 1])]));

        $noOpClient = Mockery::mock(Algolia3SearchClient::class);
        $noOpClient->shouldNotReceive('initIndex');
        $this->makeEngine($noOpClient)->update(new EloquentCollection);
    }

    public function testUpdateMergesSoftDeleteMetadataWhenEnabled(): void
    {
        $client = Mockery::mock(Algolia3SearchClient::class);
        $index = $this->mockIndex($client);

        $index->shouldReceive('saveObjects')->once()->with(Mockery::on(function ($objects) {
            $this->assertSame(1, $objects[0]['__soft_deleted']);

            return true;
        }));

        $model = Mockery::mock(PostSoft::class)->makePartial();
        $model->shouldReceive('trashed')->andReturn(true);

        $this->makeEngine($client, true)->update(new EloquentCollection([$model]));
    }

    public function testDeleteRemovesObjectsByScoutKeys(): void
    {
        $client = Mockery::mock(Algolia3SearchClient::class);
        $index = $this->mockIndex($client);
        $index->shouldReceive('deleteObjects')->once()->with([1, 2]);

        $this->makeEngine($client)->delete(new EloquentCollection([
            new Post(['id' => 1]),
            new Post(['id' => 2]),
        ]));

        $noOpClient = Mockery::mock(Algolia3SearchClient::class);
        $noOpClient->shouldNotReceive('initIndex');
        $this->makeEngine($noOpClient)->delete(new EloquentCollection);
    }

    public function testDeleteIndexAndFlush(): void
    {
        $client = Mockery::mock(Algolia3SearchClient::class);
        $index = $this->mockIndex($client);
        $index->shouldReceive('delete')->once()->andReturn(['deletedAt' => 'now']);

        $this->assertSame(['deletedAt' => 'now'], $this->makeEngine($client)->deleteIndex('posts'));

        $client2 = Mockery::mock(Algolia3SearchClient::class);
        $index2 = $this->mockIndex($client2);
        $index2->shouldReceive('clearObjects')->once();

        $this->makeEngine($client2)->flush(new Post);
    }

    public function testSearchSendsQueryOptionsAndNumericFilters(): void
    {
        $client = Mockery::mock(Algolia3SearchClient::class);
        $index = $this->mockIndex($client);

        $index->shouldReceive('search')->once()->with('foo', Mockery::on(function ($options) {
            $this->assertSame(['status=1'], $options['numericFilters']);
            $this->assertSame(5, $options['hitsPerPage']);
            $this->assertSame('title', $options['attributesToRetrieve']);

            return true;
        }))->andReturn(['hits' => [['objectID' => '1']], 'nbHits' => 1]);

        $engine = $this->makeEngine($client);
        $builder = (new Builder(new Post, 'foo'))->where('status', 1)->take(5)
            ->options(['attributesToRetrieve' => 'title']);

        $this->assertSame(1, $engine->getTotalCount($engine->search($builder)));
    }

    public function testSearchInvokesCallbackInsteadOfClient(): void
    {
        $client = Mockery::mock(Algolia3SearchClient::class);
        $index = $this->mockIndex($client);
        $index->shouldNotReceive('search');

        $engine = $this->makeEngine($client);
        $builder = new Builder(new Post, 'foo');
        $callbackRan = false;
        $builder->callback = function ($indexArg, $query, $options) use ($index, &$callbackRan) {
            $this->assertSame($index, $indexArg);
            $this->assertSame('foo', $query);
            $callbackRan = true;

            return ['hits' => [], 'nbHits' => 0];
        };

        $engine->search($builder);
        $this->assertTrue($callbackRan);
    }

    public function testUpdateIndexSettingsSetsSettingsOnIndex(): void
    {
        $client = Mockery::mock(Algolia3SearchClient::class);
        $index = $this->mockIndex($client);
        $index->shouldReceive('setSettings')->once()->with(['searchableAttributes' => ['title']]);

        $this->makeEngine($client)->updateIndexSettings('posts', ['searchableAttributes' => ['title']]);
    }
}
