<?php

namespace Erikwang2013\WebmanScout\Tests\Engines;

use Erikwang2013\WebmanScout\Builder;
use Erikwang2013\WebmanScout\Engines\CollectionEngine;
use Erikwang2013\WebmanScout\Tests\Fixtures\Post;
use Erikwang2013\WebmanScout\Tests\Fixtures\PostSoft;
use Erikwang2013\WebmanScout\Tests\TestCase;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Support\LazyCollection;

class CollectionEngineTest extends TestCase
{
    protected CollectionEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();

        $capsule = new Capsule;
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $capsule->setAsGlobal();
        $capsule->bootEloquent();
        $capsule->schema()->create('posts', function ($table) {
            $table->increments('id');
            $table->string('title');
            $table->text('body');
            $table->integer('status')->default(0);
            $table->timestamp('deleted_at')->nullable();
        });

        $this->engine = new CollectionEngine;
    }

    protected function seedPosts(): void
    {
        Post::query()->delete();

        Post::create(['id' => 1, 'title' => 'Laravel Scout', 'body' => 'Full text search', 'status' => 1]);
        Post::create(['id' => 2, 'title' => 'Meilisearch Docs', 'body' => 'Fast search engine', 'status' => 1]);
        Post::create(['id' => 3, 'title' => 'OpenSearch Guide', 'body' => 'Search beyond text', 'status' => 0]);
        Post::create(['id' => 4, 'title' => 'Database Engine', 'body' => 'SQL where like', 'status' => 0]);
    }

    protected function makeBuilder(string $query = ''): Builder
    {
        return new Builder(Post::query()->firstOrFail(), $query);
    }

    public function testSearchReturnsAllModelsWithoutQueryOrderedByKeyDesc(): void
    {
        $this->seedPosts();

        $results = $this->engine->search($this->makeBuilder());

        $this->assertCount(4, $results['results']);
        $this->assertSame(4, $results['total']);
        $this->assertSame([4, 3, 2, 1], collect($results['results'])->pluck('id')->all());
    }

    public function testSearchAppliesWhereWhereInAndWhereNotIn(): void
    {
        $this->seedPosts();

        $builder = $this->makeBuilder()->where('status', 1)->whereIn('id', [1, 2, 3])->whereNotIn('id', [2]);

        $results = $this->engine->search($builder);

        $this->assertSame([1], collect($results['results'])->pluck('id')->all());
    }

    public function testSearchFiltersByQueryOnSearchableValuesCaseInsensitively(): void
    {
        $this->seedPosts();

        $results = $this->engine->search($this->makeBuilder('search'));

        // "search" matches body of posts 1, 2 and 3.
        $this->assertSame([3, 2, 1], collect($results['results'])->pluck('id')->all());
    }

    public function testSearchHonorsLimit(): void
    {
        $this->seedPosts();

        $results = $this->engine->search($this->makeBuilder()->take(2));

        $this->assertCount(2, $results['results']);
        $this->assertSame([4, 3], collect($results['results'])->pluck('id')->all());
    }

    public function testSearchUsesCallbackInsteadOfConstraints(): void
    {
        $this->seedPosts();

        $builder = $this->makeBuilder()->where('status', 1);
        $builder->callback = function ($query, $builder, $queryString) {
            $query->where('title', 'Meilisearch Docs');
        };

        $results = $this->engine->search($builder);

        $this->assertSame([2], collect($results['results'])->pluck('id')->all());
    }

    public function testSearchFiltersOutModelsThatShouldNotBeSearchable(): void
    {
        $this->seedPosts();

        $hidden = new class extends Post {
            public function shouldBeSearchable()
            {
                return false;
            }
        };
        $builder = new Builder($hidden, '');
        $results = $this->engine->search($builder);

        $this->assertSame([], $results['results']);
        $this->assertSame(0, $results['total']);
    }

    public function testPaginateSlicesPageAndReportsTotal(): void
    {
        $this->seedPosts();

        $page2 = $this->engine->paginate($this->makeBuilder(), 3, 2);

        $this->assertSame(4, $page2['total']);
        $this->assertSame([1], collect($page2['results'])->pluck('id')->all());

        $page1 = $this->engine->paginate($this->makeBuilder(), 3, 1);
        $this->assertSame([4, 3, 2], collect($page1['results'])->pluck('id')->all());
    }

    public function testMapIdsPlucksScoutKeys(): void
    {
        $this->seedPosts();

        $ids = $this->engine->mapIds($this->engine->search($this->makeBuilder()));

        $this->assertSame([4, 3, 2, 1], $ids->all());
    }

    public function testMapRestoresModelsInResultOrder(): void
    {
        $this->seedPosts();

        $builder = $this->makeBuilder();
        $results = $this->engine->search($builder->take(2));

        $mapped = $this->engine->map($builder, $results, Post::query()->first());

        $this->assertSame([4, 3], $mapped->pluck('id')->all());
        $this->assertContainsOnlyInstancesOf(Post::class, $mapped);
    }

    public function testLazyMapRestoresModelsInResultOrder(): void
    {
        $this->seedPosts();

        $builder = $this->makeBuilder();
        $lazy = $this->engine->lazyMap($builder, $this->engine->search($builder->take(2)), Post::query()->first());

        $this->assertInstanceOf(LazyCollection::class, $lazy);
        $this->assertSame([4, 3], $lazy->pluck('id')->all());
    }

    public function testEmptyResultsYieldEmptyMappings(): void
    {
        $this->seedPosts();

        $builder = $this->makeBuilder('no-such-term-anywhere');
        $results = $this->engine->search($builder);

        $this->assertSame([], $this->engine->mapIds($results)->all());
        $this->assertSame(0, $this->engine->map($builder, $results, Post::query()->first())->count());
        $this->assertSame(0, $this->engine->getTotalCount($results));
    }

    public function testSoftDeleteConstraintExcludesTrashedModels(): void
    {
        $capsule = Capsule::schema();
        $model = PostSoft::query()->first();

        PostSoft::create(['id' => 1, 'title' => 'Visible', 'body' => 'ok']);
        PostSoft::create(['id' => 2, 'title' => 'Trashed', 'body' => 'gone']);
        PostSoft::query()->where('id', 2)->delete();

        $builder = new Builder(PostSoft::query()->first(), '', null, true); // softDelete = true

        $results = $this->engine->search($builder);

        $this->assertSame([1], collect($results['results'])->pluck('id')->all());
    }

    public function testOnlyTrashedReturnsOnlyTrashedModels(): void
    {
        PostSoft::create(['id' => 1, 'title' => 'Visible', 'body' => 'ok']);
        PostSoft::create(['id' => 2, 'title' => 'Trashed', 'body' => 'gone']);
        PostSoft::query()->where('id', 2)->delete();

        $builder = new Builder(PostSoft::query()->first(), '', null, true);
        $builder->onlyTrashed();

        $results = $this->engine->search($builder);

        $this->assertSame([2], collect($results['results'])->pluck('id')->all());
    }

    public function testUpdateDeleteFlushAndIndexOperationsAreNoOps(): void
    {
        $this->seedPosts();

        $this->assertNull($this->engine->update(Post::all()));
        $this->assertNull($this->engine->delete(Post::all()));
        $this->assertNull($this->engine->flush(Post::query()->first()));
        $this->assertNull($this->engine->createIndex('posts'));
        $this->assertNull($this->engine->deleteIndex('posts'));
    }
}
