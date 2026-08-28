<?php

namespace Erikwang2013\WebmanScout\Tests\Command;

require_once __DIR__ . '/../Support/WebmanStubs.php';

use Erikwang2013\WebmanScout\Command\DeleteIndexCommand;
use Erikwang2013\WebmanScout\EngineManager;
use Erikwang2013\WebmanScout\Engines\Engine;
use Erikwang2013\WebmanScout\ScoutConfig;
use Illuminate\Container\Container;
use Mockery;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

use function Erikwang2013\WebmanScout\Tests\Support\scoutSource;

class DeleteIndexCommandTest extends TestCase
{
    protected function setUp(): void
    {
        ScoutConfig::setSource(scoutSource(['prefix' => 'scout_']));
    }

    protected function tearDown(): void
    {
        ScoutConfig::setSource(null);
        ScoutConfig::resetResolvedBase();
        Container::getInstance()->flush();
        Mockery::close();
    }

    public function testDeletesIndexWithPrefix(): void
    {
        $engine = Mockery::mock(Engine::class);
        $engine->shouldReceive('deleteIndex')->once()->with('scout_posts');
        $manager = Mockery::mock(EngineManager::class);
        $manager->shouldReceive('engine')->once()->andReturn($engine);
        Container::getInstance()->instance(EngineManager::class, $manager);

        $output = new BufferedOutput();
        $code = (new DeleteIndexCommand())->run(new ArrayInput(['name' => 'posts']), $output);

        $this->assertSame(0, $code);
        $this->assertStringContainsString('Index "scout_posts" deleted.', $output->fetch());
    }

    public function testFailureWhenEngineThrows(): void
    {
        $engine = Mockery::mock(Engine::class);
        $engine->shouldReceive('deleteIndex')->once()->andThrow(new \RuntimeException('delete boom'));
        $manager = Mockery::mock(EngineManager::class);
        $manager->shouldReceive('engine')->once()->andReturn($engine);
        Container::getInstance()->instance(EngineManager::class, $manager);

        $output = new BufferedOutput();
        $code = (new DeleteIndexCommand())->run(new ArrayInput(['name' => 'posts']), $output);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('delete boom', $output->fetch());
    }
}
