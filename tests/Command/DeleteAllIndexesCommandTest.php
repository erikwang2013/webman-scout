<?php

namespace Erikwang2013\WebmanScout\Tests\Command;

require_once __DIR__ . '/../Support/WebmanStubs.php';
require_once __DIR__ . '/../Support/Fixtures.php';

use Erikwang2013\WebmanScout\Command\DeleteAllIndexesCommand;
use Erikwang2013\WebmanScout\EngineManager;
use Erikwang2013\WebmanScout\Engines\Engine;
use Erikwang2013\WebmanScout\ScoutConfig;
use Erikwang2013\WebmanScout\Tests\Support\TestEngine;
use Illuminate\Container\Container;
use Mockery;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

use function Erikwang2013\WebmanScout\Tests\Support\scoutSource;

class DeleteAllIndexesCommandTest extends TestCase
{
    protected function setUp(): void
    {
        ScoutConfig::setSource(scoutSource(['driver' => 'meilisearch']));
    }

    protected function tearDown(): void
    {
        ScoutConfig::setSource(null);
        ScoutConfig::resetResolvedBase();
        Container::getInstance()->flush();
        Mockery::close();
    }

    public function testFailsWhenEngineDoesNotSupportDeletingAllIndexes(): void
    {
        // A plain Engine mock never defines deleteAllIndexes(), so
        // method_exists() is false and the guard kicks in.
        $engine = Mockery::mock(Engine::class);
        $manager = Mockery::mock(EngineManager::class);
        $manager->shouldReceive('engine')->once()->andReturn($engine);
        Container::getInstance()->instance(EngineManager::class, $manager);

        $output = new BufferedOutput();
        $code = (new DeleteAllIndexesCommand())->run(new ArrayInput([]), $output);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('The [meilisearch] engine does not support deleting all indexes.', $output->fetch());
    }

    public function testDeletesAllIndexes(): void
    {
        $engine = Mockery::mock(TestEngine::class);
        $engine->shouldReceive('deleteAllIndexes')->once();
        $manager = Mockery::mock(EngineManager::class);
        $manager->shouldReceive('engine')->once()->andReturn($engine);
        Container::getInstance()->instance(EngineManager::class, $manager);

        $output = new BufferedOutput();
        $code = (new DeleteAllIndexesCommand())->run(new ArrayInput([]), $output);

        $this->assertSame(0, $code);
        $this->assertStringContainsString('All indexes deleted successfully.', $output->fetch());
    }

    public function testFailureWhenDeleteAllIndexesThrows(): void
    {
        $engine = Mockery::mock(TestEngine::class);
        $engine->shouldReceive('deleteAllIndexes')->once()->andThrow(new \RuntimeException('nuke boom'));
        $manager = Mockery::mock(EngineManager::class);
        $manager->shouldReceive('engine')->once()->andReturn($engine);
        Container::getInstance()->instance(EngineManager::class, $manager);

        $output = new BufferedOutput();
        $code = (new DeleteAllIndexesCommand())->run(new ArrayInput([]), $output);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('nuke boom', $output->fetch());
    }
}
