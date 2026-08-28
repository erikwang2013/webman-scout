<?php

namespace Erikwang2013\WebmanScout\Tests;

use Erikwang2013\WebmanScout\Events\ModelsFlushed;
use Erikwang2013\WebmanScout\Events\ModelsImported;
use Erikwang2013\WebmanScout\ScoutConfig;
use Erikwang2013\WebmanScout\SearchableScope;
use Erikwang2013\WebmanScout\Tests\Fixtures\SearchableStubModel;
use Illuminate\Container\Container;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Events\Dispatcher;
use Mockery;
use PDO;
use PHPUnit\Framework\TestCase;

class SearchableScopeTest extends TestCase
{
    protected $container;

    protected function setUp(): void
    {
        $this->container = Container::getInstance();
    }

    protected function tearDown(): void
    {
        $this->container->forgetInstance('events');
        $this->container->forgetInstance(Dispatcher::class);
        Model::unsetConnectionResolver();
        Mockery::close();
        ScoutConfig::setSource(null);
        ScoutConfig::resetResolvedBase();
    }

    protected function makeBuilderWithRows(int $rows = 3): EloquentBuilder
    {
        ScoutConfig::setSource(static function (string $key, $default = null) {
            $params = [
                'scout' => [
                    'driver' => 'null',
                    'queue' => false,
                    'soft_delete' => false,
                    'chunk' => ['searchable' => 500, 'unsearchable' => 500],
                ],
            ];

            foreach (explode('.', $key) as $segment) {
                if (! is_array($params) || ! array_key_exists($segment, $params)) {
                    return $default;
                }
                $params = $params[$segment];
            }

            return $params;
        });

        $connection = new Connection(new PDO('sqlite::memory:'), 'default', '', ['driver' => 'sqlite']);
        $connection->setSchemaGrammar(new \Illuminate\Database\Schema\Grammars\SQLiteGrammar($connection));
        $connection->getSchemaBuilder()->create('scout_stub_models', function ($table) {
            $table->increments('id');
            $table->string('title');
            $table->timestamp('deleted_at')->nullable();
        });
        $models = [];
        for ($i = 1; $i <= $rows; $i++) {
            $models[] = ['title' => 'title '.$i];
        }
        $connection->table('scout_stub_models')->insert($models);

        $resolver = new \Illuminate\Database\ConnectionResolver(['default' => $connection]);
        $resolver->setDefaultConnection('default');
        Model::setConnectionResolver($resolver);

        $queryBuilder = $connection->table('scout_stub_models');
        $builder = new EloquentBuilder($queryBuilder);
        $builder->setModel(new SearchableStubModel());

        $dispatcher = new Dispatcher($this->container);
        $this->container->instance('events', $dispatcher);
        $this->container->instance(Dispatcher::class, $dispatcher);

        return $builder;
    }

    public function testApplyIsNoop(): void
    {
        $builder = $this->makeBuilderWithRows(0);

        $scope = new SearchableScope();
        $scope->apply($builder, $builder->getModel());

        $this->addToAssertionCount(1); // no exception thrown
    }

    public function testExtendRegistersSearchableMacros(): void
    {
        $builder = $this->makeBuilderWithRows(0);
        $scope = new SearchableScope();
        $scope->extend($builder);

        $this->assertTrue($builder->hasMacro('searchable'));
        $this->assertTrue($builder->hasMacro('unsearchable'));
        $this->assertSame(
            method_exists(HasManyThrough::class, 'chunkById'),
            HasManyThrough::hasMacro('searchable')
        );
    }

    public function testSearchableMacroIndexesChunkedModelsAndDispatchesEvent(): void
    {
        $builder = $this->makeBuilderWithRows(3);
        (new SearchableScope())->extend($builder);

        $imported = 0;
        $this->container->make('events')->listen(ModelsImported::class, function () use (&$imported) {
            $imported++;
        });

        $builder->searchable(2);

        $this->assertSame(2, $imported); // 3 rows, chunk 2 -> 2 chunks
    }

    public function testUnsearchableMacroChunksAndDispatchesEvent(): void
    {
        $builder = $this->makeBuilderWithRows(3);
        (new SearchableScope())->extend($builder);

        $flushed = 0;
        $this->container->make('events')->listen(ModelsFlushed::class, function () use (&$flushed) {
            $flushed++;
        });

        $builder->unsearchable(2);

        $this->assertSame(2, $flushed);
    }

    public function testSearchableMacroUsesConfiguredChunkSize(): void
    {
        $builder = $this->makeBuilderWithRows(3);
        (new SearchableScope())->extend($builder);

        $imported = 0;
        $this->container->make('events')->listen(ModelsImported::class, function () use (&$imported) {
            $imported++;
        });

        $builder->searchable(); // chunk default 500 -> single chunk

        $this->assertSame(1, $imported);
    }
}
