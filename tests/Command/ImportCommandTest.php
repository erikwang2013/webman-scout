<?php

namespace Erikwang2013\WebmanScout\Tests\Command;

require_once __DIR__ . '/../Support/WebmanStubs.php';
require_once __DIR__ . '/../Support/Fixtures.php';

use Erikwang2013\WebmanScout\Command\ImportCommand;
use Erikwang2013\WebmanScout\Events\ModelsImported;
use Erikwang2013\WebmanScout\Exceptions\ScoutException;
use Erikwang2013\WebmanScout\Tests\Support\TestSearchableModel;
use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Events\Dispatcher;
use Mockery;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

class ImportCommandTest extends TestCase
{
    protected function setUp(): void
    {
        TestSearchableModel::resetHooks();
    }

    protected function tearDown(): void
    {
        Container::getInstance()->flush();
        Mockery::close();
    }

    private function bindDispatcher(): Dispatcher
    {
        $dispatcher = Mockery::mock(Dispatcher::class);
        Container::getInstance()->instance(Dispatcher::class, $dispatcher);

        return $dispatcher;
    }

    private function runCommand(array $input): array
    {
        $output = new BufferedOutput();
        $code = (new ImportCommand())->run(new ArrayInput($input), $output);

        return [$code, $output->fetch()];
    }

    public function testImportsModel(): void
    {
        $dispatcher = $this->bindDispatcher();
        $dispatcher->shouldReceive('listen')->once()->with(ModelsImported::class, Mockery::type(\Closure::class));
        $dispatcher->shouldReceive('forget')->once()->with(ModelsImported::class);

        [$code, $out] = $this->runCommand(['model' => TestSearchableModel::class]);

        $this->assertSame(0, $code);
        $this->assertNull(TestSearchableModel::$lastChunk);
        $this->assertFalse(TestSearchableModel::$flushed);
        $this->assertStringContainsString('All [' . TestSearchableModel::class . '] records have been imported.', $out);
    }

    public function testImportsWithChunkOption(): void
    {
        $dispatcher = $this->bindDispatcher();
        $dispatcher->shouldReceive('listen')->once()->with(ModelsImported::class, Mockery::type(\Closure::class));
        $dispatcher->shouldReceive('forget')->once()->with(ModelsImported::class);

        [$code] = $this->runCommand(['model' => TestSearchableModel::class, '--chunk' => '100']);

        $this->assertSame(0, $code);
        $this->assertSame('100', TestSearchableModel::$lastChunk);
    }

    public function testFreshFlushesBeforeImport(): void
    {
        $dispatcher = $this->bindDispatcher();
        $dispatcher->shouldReceive('listen')->once();
        $dispatcher->shouldReceive('forget')->once();

        [$code] = $this->runCommand(['model' => TestSearchableModel::class, '--fresh' => true]);

        $this->assertSame(0, $code);
        $this->assertTrue(TestSearchableModel::$flushed);
    }

    public function testModelsImportedListenerWritesLastScoutKey(): void
    {
        $listener = null;
        $dispatcher = $this->bindDispatcher();
        $dispatcher->shouldReceive('listen')->once()->with(ModelsImported::class, Mockery::capture($listener));
        $dispatcher->shouldReceive('forget')->once()->with(ModelsImported::class);

        $output = new BufferedOutput();
        $code = (new ImportCommand())->run(new ArrayInput(['model' => TestSearchableModel::class]), $output);

        $model = new TestSearchableModel;
        $model->id = 42;
        $listener(new ModelsImported(new Collection([$model])));

        $this->assertSame(0, $code);
        $this->assertStringContainsString('Imported [' . TestSearchableModel::class . '] models up to ID: 42', $output->fetch());
    }

    public function testMissingModelArgumentReturnsFailure(): void
    {
        [$code, $out] = $this->runCommand([]);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('Model class name is required.', $out);
    }

    public function testUnknownModelReturnsFailure(): void
    {
        [$code, $out] = $this->runCommand(['model' => 'Nope']);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('Model [Nope] not found.', $out);
    }
}
