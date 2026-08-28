<?php

namespace Erikwang2013\WebmanScout\Tests\Engines;

use Erikwang2013\WebmanScout\Builder;
use Erikwang2013\WebmanScout\Engines\NullEngine;
use Erikwang2013\WebmanScout\Tests\Fixtures\Post;
use Erikwang2013\WebmanScout\Tests\TestCase;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\LazyCollection;

class NullEngineTest extends TestCase
{
    protected NullEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->engine = new NullEngine;
    }

    public function testSearchAndPaginateReturnEmptyArrays(): void
    {
        $builder = new Builder(new Post, 'foo');

        $this->assertSame([], $this->engine->search($builder));
        $this->assertSame([], $this->engine->paginate($builder, 10, 1));
    }

    public function testMapMethodsReturnEmptyCollections(): void
    {
        $builder = new Builder(new Post, 'foo');

        $this->assertInstanceOf(Collection::class, $this->engine->mapIds([]));
        $this->assertTrue($this->engine->mapIds([])->isEmpty());

        $this->assertInstanceOf(
            \Illuminate\Database\Eloquent\Collection::class,
            $this->engine->map($builder, [], new Post)
        );
        $this->assertTrue($this->engine->map($builder, [], new Post)->isEmpty());

        $this->assertInstanceOf(LazyCollection::class, $this->engine->lazyMap($builder, [], new Post));
        $this->assertTrue($this->engine->lazyMap($builder, [], new Post)->isEmpty());
    }

    public function testGetTotalCountCountsRawResults(): void
    {
        $this->assertSame(0, $this->engine->getTotalCount([]));
        $this->assertSame(2, $this->engine->getTotalCount(['a' => 1, 'b' => 2]));
    }

    public function testWriteOperationsAreNoOps(): void
    {
        $this->assertNull($this->engine->update(new EloquentCollection([new Post])));
        $this->assertNull($this->engine->delete(new EloquentCollection([new Post])));
        $this->assertNull($this->engine->flush(new Post));
        $this->assertSame([], $this->engine->createIndex('posts'));
        $this->assertSame([], $this->engine->deleteIndex('posts'));
    }
}
