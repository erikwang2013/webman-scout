<?php

namespace Erikwang2013\WebmanScout\Tests;

require_once __DIR__ . '/Support/WebmanStubs.php';

use Erikwang2013\WebmanScout\Scout;
use Erikwang2013\WebmanScout\ScoutConfig;
use Erikwang2013\WebmanScout\Tests\Fixtures\Post;
use Erikwang2013\WebmanScout\EngineManager;
use Erikwang2013\WebmanScout\Events\ModelsImported;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use PHPUnit\Framework\TestCase;

/**
 * 端到端冒烟：完全不用框架，按 README「原生 PHP（无框架）」的写法引导后，
 * 搜索 / 分页 / 观察者写入 / 全量导入都能跑通。
 */
class NativePhpSmokeTest extends TestCase
{
    protected function setUp(): void
    {
        $container = Container::getInstance();
        $container->flush();

        // helpers.php 在 Composer files 阶段做的绑定（其它测试文件的 tearDown 会 flush 掉），
        // 这里按原生 PHP 的实际情况重新建立一次
        $container->instance(Dispatcher::class, new Dispatcher($container));
        $container->singleton(EngineManager::class, fn ($app) => new EngineManager($app));

        ScoutConfig::setSource(null);
        ScoutConfig::resetResolvedBase();

        Scout::configure([
            'driver' => 'database',
            'prefix' => '',
            'queue' => false,
            'chunk' => ['searchable' => 2, 'unsearchable' => 2],
        ]);

        $capsule = new Capsule;
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $capsule->setAsGlobal();
        $capsule->bootEloquent();

        Capsule::schema()->create('posts', function ($table) {
            $table->increments('id');
            $table->string('title');
            $table->string('body')->nullable();
            $table->integer('status')->default(1);
        });

        Post::query()->insert([
            ['id' => 1, 'title' => 'Laravel Scout basics', 'body' => 'search', 'status' => 1],
            ['id' => 2, 'title' => 'Full text search in PHP', 'body' => 'laravel', 'status' => 1],
            ['id' => 3, 'title' => 'Unrelated row', 'body' => 'nothing', 'status' => 0],
            ['id' => 4, 'title' => 'Draft laravel note', 'body' => 'draft', 'status' => 0],
        ]);
    }

    protected function tearDown(): void
    {
        Model::unsetConnectionResolver();
        Model::unsetEventDispatcher();
        ScoutConfig::setSource(null);
        ScoutConfig::resetResolvedBase();
        Container::getInstance()->flush();
    }

    public function testSearchAndPaginateWithoutAnyFramework(): void
    {
        $results = Post::search('laravel')->get();

        $this->assertSame([4, 2, 1], $results->pluck('id')->all(), 'title and body are both searched');

        $paginator = Post::search('laravel')->paginate(1);

        $this->assertSame(3, $paginator->total());
        $this->assertCount(1, $paginator->items());

        $drafts = Post::search('laravel')->where('status', 0)->get();
        $this->assertSame([4], $drafts->pluck('id')->all(), 'wheres are applied on top of the match');
    }

    public function testObserverWritesThroughTheNativeBootstrap(): void
    {
        Post::query()->insert(['id' => 5, 'title' => 'Observer written row', 'body' => '', 'status' => 1]);

        $post = Post::find(5);
        $post->title = 'Observer updated row';
        $post->save();

        $this->assertSame('Observer updated row', Post::search('Observer updated')->first()->title);
    }

    public function testBulkImportRunsChunkedAndDispatchesEvents(): void
    {
        $chunks = [];
        app(Dispatcher::class)->listen(ModelsImported::class, function ($event) use (&$chunks) {
            $chunks[] = $event->models->count();
        });

        Post::makeAllSearchable();

        $this->assertSame([2, 2], $chunks, 'chunk.searchable = 2 splits 4 rows into two events');
    }
}
