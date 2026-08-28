<?php

namespace Erikwang2013\WebmanScout\Tests\Engines;

use Erikwang2013\WebmanScout\Builder;
use Erikwang2013\WebmanScout\Engines\TypesenseEngine;
use Erikwang2013\WebmanScout\Exceptions\NotSupportedException;
use Erikwang2013\WebmanScout\ScoutConfig;
use Erikwang2013\WebmanScout\Tests\Fixtures\Post;
use Erikwang2013\WebmanScout\Tests\Fixtures\PostSoft;
use Erikwang2013\WebmanScout\Tests\TestCase;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\LazyCollection;
use Typesense\Client as TypesenseClient;
use Typesense\Collections;
use Typesense\Collection as TypesenseCollection;
use Typesense\Documents;
use Typesense\Exceptions\ObjectNotFound;
use Typesense\Exceptions\TypesenseClientError;
use Mockery;

require_once __DIR__.'/FakeTypesenseCollections.php';

class TypesenseEngineTest extends TestCase
{
    protected function makeEngine($client = null, int $maxTotalResults = 10000): TypesenseEngine
    {
        return new TypesenseEngine($client ?? Mockery::mock(TypesenseClient::class), $maxTotalResults);
    }

    /**
     * Client -> getCollections() -> fake collections index -> collection mock.
     */
    protected function mockCollections(Mockery\MockInterface $client, string $name = 'posts'): array
    {
        $collections = new FakeTypesenseCollections;
        $collection = Mockery::mock(TypesenseCollection::class);
        $collections->collections[$name] = $collection;

        $client->shouldReceive('getCollections')->andReturn($collections);

        return [$collections, $collection];
    }

    protected function mockDocuments(TypesenseCollection $collection): Mockery\MockInterface
    {
        $documents = Mockery::mock(Documents::class);
        $collection->shouldReceive('getDocuments')->andReturn($documents);

        return $documents;
    }

    public function testUpdateImportsDocumentsWithMetadata(): void
    {
        $client = Mockery::mock(TypesenseClient::class);
        [, $collection] = $this->mockCollections($client);
        $documents = $this->mockDocuments($collection);

        // collection exists -> no create
        $collection->shouldReceive('retrieve')->once()->andReturn([]);
        $collection->shouldReceive('setExists')->with(true)->andReturnSelf();

        $documents->shouldReceive('import')->once()->with(Mockery::on(function ($objects) {
            $this->assertSame([
                ['id' => 1, 'title' => 'One', 'body' => 'a', 'status' => 0],
                ['id' => 2, 'title' => 'Two', 'body' => 'b', 'status' => 0],
            ], $objects);

            return true;
        }), ['action' => 'upsert'])->andReturn([
            ['success' => true, 'document' => '{"id":1}'],
            ['success' => true, 'document' => '{"id":2}'],
        ]);

        $this->makeEngine($client)->update(new EloquentCollection([
            new Post(['id' => 1, 'title' => 'One', 'body' => 'a', 'status' => 0]),
            new Post(['id' => 2, 'title' => 'Two', 'body' => 'b', 'status' => 0]),
        ]));
    }

    public function testUpdateCreatesCollectionWhenMissingAndMergesSoftDeleteMetadata(): void
    {
        ScoutConfig::setSource(fn ($key, $default) => str_ends_with($key, '.soft_delete') || $key === 'soft_delete' ? true : $default);

        $client = Mockery::mock(TypesenseClient::class);
        [$collections, $collection] = $this->mockCollections($client);
        $documents = $this->mockDocuments($collection);

        $collection->shouldReceive('retrieve')->once()->andThrow(new TypesenseClientError('missing'));
        $collection->shouldReceive('setExists')->with(true)->andReturnSelf();

        $documents->shouldReceive('import')->once()->with(Mockery::on(function ($objects) {
            $this->assertSame(1, $objects[0]['__soft_deleted']);

            return true;
        }), ['action' => 'upsert'])->andReturn([['success' => true, 'document' => '{}']]);

        $model = Mockery::mock(PostSoft::class)->makePartial();
        $model->shouldReceive('trashed')->andReturn(true);

        $this->makeEngine($client)->update(new EloquentCollection([$model]));

        $this->assertSame('posts', $collections->createdSchemas[0]['name']);
    }

    public function testUpdateThrowsOnFailedImport(): void
    {
        $client = Mockery::mock(TypesenseClient::class);
        [, $collection] = $this->mockCollections($client);
        $documents = $this->mockDocuments($collection);

        $collection->shouldReceive('retrieve')->once()->andReturn([]);
        $collection->shouldReceive('setExists')->with(true)->andReturnSelf();
        $documents->shouldReceive('import')->once()->andReturn([
            ['success' => false, 'error' => 'bad doc'],
        ]);

        $this->expectException(TypesenseClientError::class);
        $this->expectExceptionMessage('Error importing document: bad doc');

        $this->makeEngine($client)->update(new EloquentCollection([new Post(['id' => 1])]));
    }

    public function testSearchSendsParametersAndMapsIds(): void
    {
        $client = Mockery::mock(TypesenseClient::class);
        [, $collection] = $this->mockCollections($client);
        $documents = $this->mockDocuments($collection);

        $documents->shouldReceive('search')->once()->with(Mockery::on(function ($params) {
            $this->assertSame('foo', $params['q']);
            $this->assertSame('status:=1', $params['filter_by']);
            $this->assertSame(5, $params['per_page']);
            $this->assertSame(1, $params['page']);
            $this->assertSame('<mark>', $params['highlight_start_tag']);

            return true;
        }))->andReturn([
            'found' => 2,
            'hits' => [
                ['document' => ['id' => 3]],
                ['document' => ['id' => 1]],
            ],
        ]);

        $engine = $this->makeEngine($client);
        $results = $engine->search((new Builder(new Post, 'foo'))->where('status', 1)->take(5));

        $this->assertSame([3, 1], $engine->mapIds($results)->all());
        $this->assertSame(2, $engine->getTotalCount($results));
    }

    public function testSearchRetriesAfterCreatingMissingCollection(): void
    {
        $client = Mockery::mock(TypesenseClient::class);
        [$collections, $collection] = $this->mockCollections($client);
        $documents = $this->mockDocuments($collection);

        $documents->shouldReceive('search')->once()->andThrow(new ObjectNotFound('nope'));
        $documents->shouldReceive('search')->once()->andReturn(['found' => 0, 'hits' => []]);
        $collection->shouldReceive('retrieve')->once()->andThrow(new TypesenseClientError('missing'));
        $collection->shouldReceive('setExists')->once()->with(true)->andReturnNull();

        $results = $this->makeEngine($client)->search(new Builder(new Post, 'foo'));

        $this->assertSame(0, $results['found']);
        $this->assertSame('posts', $collections->createdSchemas[0]['name']);
    }

    public function testSearchInvokesCallbackInsteadOfClient(): void
    {
        $client = Mockery::mock(TypesenseClient::class);
        [, $collection] = $this->mockCollections($client);
        $documents = $this->mockDocuments($collection);
        $documents->shouldNotReceive('search');

        $engine = $this->makeEngine($client);
        $builder = new Builder(new Post, 'foo');
        $callbackRan = false;
        $builder->callback = function ($documentsArg, $query, $params) use ($documents, &$callbackRan) {
            $this->assertSame($documents, $documentsArg);
            $this->assertSame('foo', $query);
            $callbackRan = true;

            return ['found' => 0, 'hits' => []];
        };

        $engine->search($builder);
        $this->assertTrue($callbackRan);
    }

    public function testSearchUsesPaginatedPathWhenLimitExceedsMaxPerPage(): void
    {
        $client = Mockery::mock(TypesenseClient::class);
        [, $collection] = $this->mockCollections($client);
        $documents = $this->mockDocuments($collection);

        $documents->shouldReceive('search')->twice()->andReturn(
            ['found' => 400, 'hits' => array_fill(0, 250, ['document' => ['id' => 1]])],
            ['found' => 400, 'hits' => array_fill(0, 150, ['document' => ['id' => 2]])]
        );

        $results = $this->makeEngine($client)->search((new Builder(new Post, 'foo'))->take(400));

        $this->assertSame(400, $results['found']);
        $this->assertSame(1, $results['page']);
        $this->assertSame(400, $results['out_of']);
    }

    public function testPaginateClampsPageWithinIntegerRange(): void
    {
        $client = Mockery::mock(TypesenseClient::class);
        [, $collection] = $this->mockCollections($client);
        $documents = $this->mockDocuments($collection);

        $documents->shouldReceive('search')->once()->with(Mockery::on(function ($params) {
            // page * perPage overflows -> page clamped down
            $this->assertLessThanOrEqual(4294967295, $params['page'] * 10);
            $this->assertSame(10, $params['per_page']);

            return true;
        }))->andReturn(['found' => 0, 'hits' => []]);

        $this->makeEngine($client)->paginate(new Builder(new Post, 'foo'), 10, PHP_INT_MAX);
    }

    public function testBuildSearchParametersCombinesOrdersOptionsAndModelSettings(): void
    {
        $engine = $this->makeEngine();

        $model = new class extends Post {
            public function typesenseSearchParameters(): array
            {
                return ['query_by' => 'title,body'];
            }
        };

        $builder = (new Builder($model, 'foo'))
            ->orderBy('title', 'desc')
            ->orderBy('id', 'asc')
            ->options(['query_by' => 'title', 'exhaustive_search' => true]);

        $params = $engine->buildSearchParameters($builder, 2, 25);

        $this->assertSame('foo', $params['q']);
        // builder options win over model-provided defaults
        $this->assertSame('title', $params['query_by']);
        $this->assertSame(2, $params['page']);
        $this->assertSame(25, $params['per_page']);
        $this->assertTrue($params['exhaustive_search']);
        $this->assertSame('title:desc,id:asc', $params['sort_by']);
    }

    public function testFiltersParseValuesForTypesenseSyntax(): void
    {
        $engine = new class extends TypesenseEngine {
            public function __construct()
            {
                parent::__construct(new \Typesense\Client(['api_key' => 'x', 'nodes' => [['host' => 'h', 'port' => 1, 'protocol' => 'http']]]), 100);
            }

            public function filtersPub(Builder $builder): string
            {
                return $this->filters($builder);
            }
        };

        $builder = (new Builder(new Post, ''))
            ->where('status', 1)
            ->where('active', true)
            ->where('deleted', false)
            ->where('note', null)
            ->where('name', 'say "hi"')
            ->whereIn('id', [1, 2, 'abc'])
            ->whereNotIn('tag', ['x']);

        $this->assertSame(
            'status:=1 && active:=true && deleted:=false && note:=null && name:="say \"hi\"" && id:=[1, 2, "abc"] && tag:!=["x"]',
            $engine->filtersPub($builder)
        );
    }

    public function testMapRestoresModelsInOrderAndHandlesGroupedHits(): void
    {
        $model = Mockery::mock(Post::class)->makePartial();
        $model->shouldReceive('getScoutModelsByIds')->twice()->with(Mockery::type(Builder::class), Mockery::any())
            ->andReturn(new EloquentCollection([
                (new Post)->newFromBuilder(['id' => 1, 'title' => 'one']),
                (new Post)->newFromBuilder(['id' => 2, 'title' => 'two']),
            ]));

        $engine = $this->makeEngine();
        $results = [
            'found' => 2,
            'hits' => [['document' => ['id' => 2]], ['document' => ['id' => 1]]],
        ];

        $mapped = $engine->map(new Builder($model, ''), $results, $model);
        $this->assertSame([2, 1], $mapped->pluck('id')->all());

        $grouped = ['grouped_hits' => [['hits' => [['document' => ['id' => 2]]]]], 'found' => 1];
        $groupedMapped = $engine->map(new Builder($model, ''), $grouped, $model);
        $this->assertSame([2], $groupedMapped->pluck('id')->all());

        $this->assertTrue($engine->map(new Builder($model, ''), ['found' => 0, 'hits' => []], $model)->isEmpty());
    }

    public function testLazyMapRestoresModelsInOrder(): void
    {
        $query = Mockery::mock(\Illuminate\Database\Eloquent\Builder::class)->makePartial();
        $query->shouldReceive('cursor')->andReturn(LazyCollection::empty());

        $model = Mockery::mock(Post::class)->makePartial();
        $model->shouldReceive('queryScoutModelsByIds')->once()->with(Mockery::type(Builder::class), [2, 1])
            ->andReturn($query);

        $engine = $this->makeEngine();
        $lazy = $engine->lazyMap(new Builder($model, ''), [
            'found' => 2,
            'hits' => [['document' => ['id' => 2]], ['document' => ['id' => 1]]],
        ], $model);

        $this->assertInstanceOf(LazyCollection::class, $lazy);
        $this->assertTrue($lazy->isEmpty());

        $empty = $engine->lazyMap(new Builder($model, ''), ['found' => 0, 'hits' => []], $model);
        $this->assertInstanceOf(LazyCollection::class, $empty);
    }

    public function testDeleteRemovesDocuments(): void
    {
        $client = Mockery::mock(TypesenseClient::class);
        [, $collection] = $this->mockCollections($client);
        $collection->shouldReceive('retrieve')->andReturn([]);
        $collection->shouldReceive('setExists')->with(true)->andReturnSelf();

        $documents = Mockery::mock(Documents::class);
        $document = Mockery::mock(\Typesense\Document::class);
        $documents->shouldReceive('offsetGet')->with('1')->andReturn($document);
        $collection->shouldReceive('getDocuments')->andReturn($documents);

        $document->shouldReceive('retrieve')->once()->andReturn([]);
        $document->shouldReceive('delete')->once()->andReturn(['id' => '1']);

        $this->assertNull($this->makeEngine($client)->delete(new EloquentCollection([new Post(['id' => 1])])));
    }

    public function testDeleteSwallowsMissingDocuments(): void
    {
        $client = Mockery::mock(TypesenseClient::class);
        [, $collection] = $this->mockCollections($client);
        $collection->shouldReceive('retrieve')->andReturn([]);
        $collection->shouldReceive('setExists')->with(true)->andReturnSelf();

        $documents = Mockery::mock(Documents::class);
        $document = Mockery::mock(\Typesense\Document::class);
        $documents->shouldReceive('offsetGet')->with('1')->andReturn($document);
        $collection->shouldReceive('getDocuments')->andReturn($documents);

        $document->shouldReceive('retrieve')->once()->andThrow(new ObjectNotFound('gone'));
        $document->shouldNotReceive('delete');

        $this->makeEngine($client)->delete(new EloquentCollection([new Post(['id' => 1])]));
    }

    public function testFlushDeletesCollection(): void
    {
        $client = Mockery::mock(TypesenseClient::class);
        [, $collection] = $this->mockCollections($client);
        $collection->shouldReceive('retrieve')->andReturn([]);
        $collection->shouldReceive('setExists')->with(true)->andReturnSelf();
        $collection->shouldReceive('delete')->once()->andReturn([]);

        $this->makeEngine($client)->flush(new Post);
    }

    public function testCreateIndexThrowsNotSupported(): void
    {
        $this->expectException(NotSupportedException::class);
        $this->makeEngine()->createIndex('posts');
    }

    public function testDeleteIndexDeletesCollection(): void
    {
        $client = Mockery::mock(TypesenseClient::class);
        [, $collection] = $this->mockCollections($client);
        $collection->shouldReceive('delete')->once()->andReturn([]);

        $this->makeEngine($client)->deleteIndex('posts');
    }

    public function testDynamicCallsAreForwardedToClient(): void
    {
        $client = Mockery::mock(TypesenseClient::class);
        $client->shouldReceive('health')->once()->with()->andReturn(['ok' => true]);

        $this->assertSame(['ok' => true], $this->makeEngine($client)->health());
    }
}
