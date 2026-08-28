<?php

namespace Erikwang2013\WebmanScout\Tests\Command;

require_once __DIR__ . '/../Support/WebmanStubs.php';
require_once __DIR__ . '/../Support/Fixtures.php';

use Erikwang2013\WebmanScout\Command\IndexCommand;
use Erikwang2013\WebmanScout\Contracts\UpdatesIndexSettings;
use Erikwang2013\WebmanScout\EngineManager;
use Erikwang2013\WebmanScout\Engines\Engine;
use Erikwang2013\WebmanScout\Exceptions\NotSupportedException;
use Erikwang2013\WebmanScout\ScoutConfig;
use Erikwang2013\WebmanScout\Tests\Support\TestSoftDeletingModel;
use Illuminate\Container\Container;
use Mockery;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

use function Erikwang2013\WebmanScout\Tests\Support\scoutSource;

class IndexCommandTest extends TestCase
{
    protected function setUp(): void
    {
        ScoutConfig::setSource(scoutSource([
            'driver' => 'meilisearch',
            'prefix' => 'scout_',
            'soft_delete' => false,
        ]));
    }

    protected function tearDown(): void
    {
        ScoutConfig::setSource(null);
        ScoutConfig::resetResolvedBase();
        Container::getInstance()->flush();
        Mockery::close();
    }

    private function bindEngine(Engine $engine): void
    {
        $manager = Mockery::mock(EngineManager::class);
        $manager->shouldReceive('engine')->once()->andReturn($engine);
        Container::getInstance()->instance(EngineManager::class, $manager);
    }

    private function runCommand(array $input): array
    {
        $output = new BufferedOutput();
        $code = (new IndexCommand())->run(new ArrayInput($input), $output);

        return [$code, $output->fetch()];
    }

    public function testCreatesIndexWithPrefix(): void
    {
        $engine = Mockery::mock(Engine::class);
        $engine->shouldReceive('createIndex')->once()->with('scout_posts', []);
        $this->bindEngine($engine);

        [$code, $out] = $this->runCommand(['name' => 'posts']);

        $this->assertSame(0, $code);
        $this->assertStringContainsString('Index ["scout_posts"] created successfully.', $out);
    }

    public function testCreatesIndexWithPrimaryKeyOption(): void
    {
        $engine = Mockery::mock(Engine::class);
        $engine->shouldReceive('createIndex')->once()->with('scout_posts', ['primaryKey' => 'id']);
        $this->bindEngine($engine);

        [$code] = $this->runCommand(['name' => 'posts', '--key' => 'id']);

        $this->assertSame(0, $code);
    }

    public function testSwallowsNotSupportedException(): void
    {
        $engine = Mockery::mock(Engine::class);
        $engine->shouldReceive('createIndex')->once()->andThrow(new NotSupportedException('nope'));
        $this->bindEngine($engine);

        [$code, $out] = $this->runCommand(['name' => 'posts']);

        $this->assertSame(0, $code);
        $this->assertStringContainsString('created successfully', $out);
    }

    public function testFailureWhenEngineThrows(): void
    {
        $engine = Mockery::mock(Engine::class);
        $engine->shouldReceive('createIndex')->once()->andThrow(new \RuntimeException('boom'));
        $this->bindEngine($engine);

        [$code, $out] = $this->runCommand(['name' => 'posts']);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('boom', $out);
    }

    public function testUpdatesIndexSettingsWhenEngineSupportsIt(): void
    {
        ScoutConfig::setSource(scoutSource([
            'driver' => 'meilisearch',
            'prefix' => 'scout_',
            'soft_delete' => false,
            'meilisearch' => [
                'index-settings' => [
                    'scout_posts' => ['filterableAttributes' => ['status']],
                ],
            ],
        ]));

        $engine = Mockery::mock(Engine::class, UpdatesIndexSettings::class);
        $engine->shouldReceive('createIndex')->once()->with('scout_posts', []);
        $engine->shouldReceive('updateIndexSettings')->once()
            ->with('scout_posts', ['filterableAttributes' => ['status']]);
        $this->bindEngine($engine);

        [$code] = $this->runCommand(['name' => 'posts']);

        $this->assertSame(0, $code);
    }

    public function testAppliesSoftDeleteFilterForSoftDeletingModel(): void
    {
        ScoutConfig::setSource(scoutSource([
            'driver' => 'meilisearch',
            'prefix' => 'scout_',
            'soft_delete' => true,
            'meilisearch' => [
                'index-settings' => [
                    'scout_soft_deletables' => ['filterableAttributes' => ['deleted_at']],
                ],
            ],
        ]));

        $engine = Mockery::mock(Engine::class, UpdatesIndexSettings::class);
        $engine->shouldReceive('createIndex')->once()->with('scout_soft_deletables', []);
        $engine->shouldReceive('configureSoftDeleteFilter')->once()
            ->with(['filterableAttributes' => ['deleted_at']])
            ->andReturn(['filterableAttributes' => ['deleted_at', '__soft_deleted']]);
        $engine->shouldReceive('updateIndexSettings')->once()
            ->with('scout_soft_deletables', ['filterableAttributes' => ['deleted_at', '__soft_deleted']]);
        $this->bindEngine($engine);

        [$code] = $this->runCommand(['name' => TestSoftDeletingModel::class]);

        $this->assertSame(0, $code);
    }
}
