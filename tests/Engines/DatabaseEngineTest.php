<?php

namespace Erikwang2013\WebmanScout\Tests\Engines;

use Erikwang2013\WebmanScout\Builder;
use Erikwang2013\WebmanScout\Engines\DatabaseEngine;
use Erikwang2013\WebmanScout\Tests\Fixtures\Post;
use Erikwang2013\WebmanScout\Tests\Fixtures\PostFullText;
use Erikwang2013\WebmanScout\Tests\Fixtures\PostSoft;
use Erikwang2013\WebmanScout\Tests\TestCase;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Pagination\LengthAwarePaginator;
use Mockery;

class DatabaseEngineTest extends TestCase
{
    protected DatabaseEngine $engine;

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

        $this->engine = new DatabaseEngine;
    }

    protected function seedPosts(int $count = 25): void
    {
        Post::query()->delete();

        for ($i = 1; $i <= $count; $i++) {
            Post::create([
                'id' => $i,
                'title' => "Post title {$i}",
                'body' => $i % 2 === 0 ? 'contains laravel keyword' : 'plain body',
                'status' => $i % 3,
            ]);
        }
    }

    public function testSearchMatchesLikeClauseAcrossColumns(): void
    {
        $this->seedPosts(6);

        $results = $this->engine->search(new Builder(Post::query()->first(), 'laravel'));

        $this->assertSame(3, $results['total']);
        $this->assertSame([6, 4, 2], collect($results['results'])->pluck('id')->all());
    }

    public function testSearchByPrimaryKeyWhenQueryIsNumeric(): void
    {
        $this->seedPosts(6);

        $results = $this->engine->search(new Builder(Post::query()->first(), '3'));

        $this->assertSame([3], collect($results['results'])->pluck('id')->all());
    }

    public function testSearchAppliesConstraintsOrdersAndLimit(): void
    {
        $this->seedPosts(9);

        $builder = (new Builder(Post::query()->first(), ''))
            ->where('status', 1)
            ->whereIn('id', [1, 2, 4, 5, 7])
            ->whereNotIn('id', [5])
            ->orderBy('title', 'asc')
            ->take(2);

        $results = $this->engine->search($builder);

        $this->assertSame([1, 4], collect($results['results'])->pluck('id')->all());
    }

    public function testSearchAppliesQueryCallback(): void
    {
        $this->seedPosts(6);

        $builder = new Builder(Post::query()->first(), '');
        $builder->query(function ($query) {
            $query->where('status', 2);
        });

        $results = $this->engine->search($builder);

        $this->assertSame([5, 2], collect($results['results'])->pluck('id')->all());
    }

    public function testPaginateKeepsTotalConsistentWithRows(): void
    {
        $this->seedPosts(25);

        $paginator = $this->engine->paginate(new Builder(Post::query()->first(), ''), 10, 1);

        $this->assertInstanceOf(LengthAwarePaginator::class, $paginator);
        $this->assertSame(25, $paginator->total());
        $this->assertSame(10, $paginator->count());
        $this->assertSame(3, $paginator->lastPage());

        $page3 = $this->engine->paginate(new Builder(Post::query()->first(), ''), 10, 3);
        $this->assertSame(5, $page3->count());
        $this->assertSame(25, $page3->total());

        $filtered = $this->engine->paginate((new Builder(Post::query()->first(), ''))->where('status', 1), 10, 1);
        $this->assertSame(9, $filtered->total());
        $this->assertSame(9, $filtered->count());
    }

    public function testSimplePaginateUsesDatabase(): void
    {
        $this->seedPosts(25);

        $paginator = $this->engine->simplePaginate(new Builder(Post::query()->first(), ''), 10, 2);

        $this->assertTrue($paginator->hasMorePages());
        $this->assertSame(10, $paginator->count());
    }

    public function testMapIdsMapAndLazyMap(): void
    {
        $this->seedPosts(6);

        $builder = new Builder(Post::query()->first(), '');
        $results = $this->engine->search($builder->take(3));

        $this->assertSame([6, 5, 4], $this->engine->mapIds($results)->all());
        $this->assertSame(3, $results['total']);
        $this->assertSame([6, 5, 4], $this->engine->map($builder, $results, Post::query()->first())->pluck('id')->all());

        $lazy = $this->engine->lazyMap($builder, $results, Post::query()->first());
        $this->assertSame([6, 5, 4], $lazy->pluck('id')->all());
    }

    public function testEmptySearchResults(): void
    {
        $this->seedPosts(0);

        $builder = new Builder(new Post, '');
        $results = $this->engine->search($builder);

        $this->assertSame([], $this->engine->mapIds($results)->all());
        $this->assertSame(0, $this->engine->getTotalCount($results));
        $this->assertSame(0, $this->engine->map($builder, $results, new Post)->count());
    }

    public function testSoftDeletedConstraintsAreApplied(): void
    {
        PostSoft::create(['id' => 1, 'title' => 'Visible', 'body' => 'ok']);
        PostSoft::create(['id' => 2, 'title' => 'Trashed', 'body' => 'gone']);
        PostSoft::query()->where('id', 2)->delete();

        $without = $this->engine->search(new Builder(PostSoft::query()->first(), '', null, true));
        $this->assertSame([1], collect($without['results'])->pluck('id')->all());

        $only = new Builder(PostSoft::query()->first(), '', null, true);
        $only->onlyTrashed();
        $this->assertSame([2], collect($this->engine->search($only)['results'])->pluck('id')->all());
    }

    // ------------------------------------------------------------------
    // SQL generation (query builder mocked, execution stubbed)
    // ------------------------------------------------------------------

    protected function sqlBuilderMock($model): EloquentBuilder
    {
        // makePartial keeps when()/take()/where() trait+real behavior; every
        // method that would touch the underlying query builder is intercepted.
        $query = Mockery::mock(EloquentBuilder::class)->makePartial();
        $model->shouldReceive('newQuery')->andReturn($query);

        return $query;
    }

    public function testSqlGenerationUsesLikeForMysqlAndWrapsWhereClosure(): void
    {
        $model = Mockery::mock(Post::class)->makePartial();
        $model->shouldReceive('getConnection->getDriverName')->andReturn('mysql');

        $query = $this->sqlBuilderMock($model);

        $calls = [];
        $query->shouldReceive('where')->with(Mockery::type(\Closure::class))
            ->andReturnUsing(function ($closure) use ($query, &$calls) {
                $closure($query);
                $calls[] = 'where';

                return $query;
            });
        $query->shouldReceive('orWhere')->with('posts.id', 'like', '%foo%')->andReturnSelf();
        $query->shouldReceive('orWhere')->with('posts.title', 'like', '%foo%')->andReturnSelf();
        $query->shouldReceive('orWhere')->with('posts.body', 'like', '%foo%')->andReturnSelf();
        $query->shouldReceive('orWhere')->with('posts.status', 'like', '%foo%')->andReturnSelf();
        $query->shouldReceive('orderBy')->with('posts.id', 'desc')->andReturnSelf();
        $query->shouldReceive('take')->with(null)->andReturnSelf();
        $query->shouldReceive('get')->andReturn(new \Illuminate\Database\Eloquent\Collection());

        $results = $this->engine->search(new Builder($model, 'foo'));

        $this->assertSame(['where'], $calls);
        $this->assertSame(0, $results['total']);
    }

    public function testSqlGenerationUsesPrefixLikeForMarkedColumnsAndIlikeForPgsql(): void
    {
        $model = Mockery::mock(PostFullText::class)->makePartial();
        $model->shouldReceive('getConnection->getDriverName')->andReturn('pgsql');

        $query = $this->sqlBuilderMock($model);

        $query->shouldReceive('where')->with(Mockery::type(\Closure::class))
            ->andReturnUsing(fn ($closure) => tap($query, fn () => $closure($query)));
        $query->shouldReceive('orWhere')->with('posts.id', 'ilike', '%foo%')->andReturnSelf();
        // title is prefix-search column -> trailing wildcard only
        $query->shouldReceive('orWhere')->with('posts.title', 'ilike', 'foo%')->andReturnSelf();
        $query->shouldReceive('orWhereFullText')->with(['posts.body'], 'foo', ['language' => 'simple'])->andReturnSelf();
        // pgsql + fulltext + no orders -> relevance ordering via ts_rank
        $query->shouldReceive('orderByRaw')->with(
            "ts_rank(to_tsvector('simple', posts.body), plainto_tsquery(?)) desc",
            ['foo']
        )->andReturnSelf();
        $query->shouldReceive('take')->with(null)->andReturnSelf();
        $query->shouldReceive('get')->andReturn(new \Illuminate\Database\Eloquent\Collection());

        $this->expectNotToPerformAssertions();

        $this->engine->search(new Builder($model, 'foo'));
    }

    public function testSqlGenerationAddsPrimaryKeyClauseForNumericQueryOnIntKey(): void
    {
        $model = Mockery::mock(Post::class)->makePartial();
        $model->shouldReceive('getConnection->getDriverName')->andReturn('mysql');
        $model->shouldReceive('getKeyType')->andReturn('int');

        $query = $this->sqlBuilderMock($model);

        $query->shouldReceive('where')->with(Mockery::type(\Closure::class))
            ->andReturnUsing(fn ($closure) => tap($query, fn () => $closure($query)));
        $query->shouldReceive('orWhere')->with('posts.id', '123')->andReturnSelf();
        $query->shouldReceive('orWhere')->with('posts.id', 'like', '%123%')->andReturnSelf();
        $query->shouldReceive('orWhere')->with('posts.title', 'like', '%123%')->andReturnSelf();
        $query->shouldReceive('orWhere')->with('posts.body', 'like', '%123%')->andReturnSelf();
        $query->shouldReceive('orWhere')->with('posts.status', 'like', '%123%')->andReturnSelf();
        $query->shouldReceive('orderBy')->with('posts.id', 'desc')->andReturnSelf();
        $query->shouldReceive('take')->with(null)->andReturnSelf();
        $query->shouldReceive('get')->andReturn(new \Illuminate\Database\Eloquent\Collection());

        $this->expectNotToPerformAssertions();

        $this->engine->search(new Builder($model, '123'));
    }

    public function testSqlGenerationAppliesWheresWhereInsAndOrderBy(): void
    {
        $model = Mockery::mock(Post::class)->makePartial();
        $model->shouldReceive('getConnection->getDriverName')->andReturn('mysql');

        $query = $this->sqlBuilderMock($model);

        $query->shouldReceive('where')->with('status', '=', 1)->andReturnSelf();
        $query->shouldReceive('whereIn')->with('id', [1, 2])->andReturnSelf();
        $query->shouldReceive('whereNotIn')->with('id', [9])->andReturnSelf();
        $query->shouldReceive('orderBy')->with('title', 'desc')->andReturnSelf();
        // default order by key is appended even when developer orders exist
        $query->shouldReceive('orderBy')->with('posts.id', 'desc')->andReturnSelf();
        $query->shouldReceive('take')->with(null)->andReturnSelf();
        $query->shouldReceive('get')->andReturn(new \Illuminate\Database\Eloquent\Collection());

        $builder = (new Builder($model, ''))->where('status', 1)->whereIn('id', [1, 2])->whereNotIn('id', [9])->orderBy('title', 'desc');

        $this->expectNotToPerformAssertions();

        $this->engine->search($builder);
    }

    public function testSqlGenerationAppliesSoftDeleteConstraints(): void
    {
        $model = Mockery::mock(PostSoft::class)->makePartial();
        $model->shouldReceive('getConnection->getDriverName')->andReturn('mysql');

        $query = $this->sqlBuilderMock($model);
        $query->shouldReceive('withoutTrashed')->andReturnSelf();
        $query->shouldReceive('orderBy')->with('posts.id', 'desc')->andReturnSelf();
        $query->shouldReceive('take')->with(null)->andReturnSelf();
        $query->shouldReceive('get')->andReturn(new \Illuminate\Database\Eloquent\Collection());

        $this->engine->search(new Builder($model, '', null, true));

        $only = Mockery::mock(PostSoft::class)->makePartial();
        $only->shouldReceive('getConnection->getDriverName')->andReturn('mysql');
        $onlyQuery = $this->sqlBuilderMock($only);
        $onlyQuery->shouldReceive('onlyTrashed')->andReturnSelf();
        $onlyQuery->shouldReceive('orderBy')->with('posts.id', 'desc')->andReturnSelf();
        $onlyQuery->shouldReceive('take')->with(null)->andReturnSelf();
        $onlyQuery->shouldReceive('get')->andReturn(new \Illuminate\Database\Eloquent\Collection());

        $builder = new Builder($only, '', null, true);
        $builder->onlyTrashed();

        $this->expectNotToPerformAssertions();

        $this->engine->search($builder);
    }

    public function testPaginateUsingDatabaseDelegatesToEloquentPaginator(): void
    {
        $model = Mockery::mock(Post::class)->makePartial();
        $model->shouldReceive('getConnection->getDriverName')->andReturn('mysql');

        $query = $this->sqlBuilderMock($model);
        $query->shouldReceive('orderBy')->with('posts.id', 'desc')->andReturnSelf();
        $query->shouldReceive('take')->with(null)->andReturnSelf();

        $paginator = new LengthAwarePaginator([], 0, 10);
        $query->shouldReceive('paginate')->with(10, ['*'], 'page', 2)->andReturn($paginator);

        $this->assertSame($paginator, $this->engine->paginate(new Builder($model, ''), 10, 2));
    }
}
