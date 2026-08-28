<?php

namespace Erikwang2013\WebmanScout\Tests\Command;

require_once __DIR__ . '/../Support/WebmanStubs.php';
require_once __DIR__ . '/../Support/Fixtures.php';

use Erikwang2013\WebmanScout\Command\SyncIndexSettingsCommand;
use Erikwang2013\WebmanScout\Contracts\UpdatesIndexSettings;
use Erikwang2013\WebmanScout\EngineManager;
use Erikwang2013\WebmanScout\Engines\Engine;
use Erikwang2013\WebmanScout\ScoutConfig;
use Erikwang2013\WebmanScout\Tests\Support\TestSoftDeletingModel;
use Illuminate\Container\Container;
use Mockery;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

use function Erikwang2013\WebmanScout\Tests\Support\scoutSource;

class SyncIndexSettingsCommandTest extends TestCase
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

    private function bindEngine($engine): void
    {
        $manager = Mockery::mock(EngineManager::class);
        $manager->shouldReceive('engine')->once()->with('meilisearch')->andReturn($engine);
        Container::getInstance()->instance(EngineManager::class, $manager);
    }

    private function runCommand(array $input = []): array
    {
        $output = new BufferedOutput();
        $code = (new SyncIndexSettingsCommand())->run(new ArrayInput($input), $output);

        return [$code, $output->fetch()];
    }

    public function testSyncsConfiguredIndexSettings(): void
    {
        ScoutConfig::setSource(scoutSource([
            'driver' => 'meilisearch',
            'prefix' => 'scout_',
            'soft_delete' => false,
            'meilisearch' => [
                'index-settings' => [
                    'posts' => ['filterableAttributes' => ['status']],
                    TestSoftDeletingModel::class => ['filterableAttributes' => ['deleted_at']],
                ],
            ],
        ]));

        $engine = Mockery::mock(Engine::class, UpdatesIndexSettings::class);
        $engine->shouldReceive('updateIndexSettings')->once()->with('scout_posts', ['filterableAttributes' => ['status']]);
        $engine->shouldReceive('updateIndexSettings')->once()
            ->with('scout_soft_deletables', ['filterableAttributes' => ['deleted_at']]);
        $this->bindEngine($engine);

        [$code, $out] = $this->runCommand();

        $this->assertSame(0, $code);
        $this->assertStringContainsString('Settings for the [scout_posts] index synced successfully.', $out);
        $this->assertStringContainsString('Settings for the [scout_soft_deletables] index synced successfully.', $out);
    }

    public function testAppliesSoftDeleteFilter(): void
    {
        ScoutConfig::setSource(scoutSource([
            'driver' => 'meilisearch',
            'prefix' => 'scout_',
            'soft_delete' => true,
            'meilisearch' => [
                'index-settings' => [
                    TestSoftDeletingModel::class => ['filterableAttributes' => ['deleted_at']],
                ],
            ],
        ]));

        $engine = Mockery::mock(Engine::class, UpdatesIndexSettings::class);
        $engine->shouldReceive('configureSoftDeleteFilter')->once()
            ->with(['filterableAttributes' => ['deleted_at']])
            ->andReturn(['filterableAttributes' => ['deleted_at', '__soft_deleted']]);
        $engine->shouldReceive('updateIndexSettings')->once()
            ->with('scout_soft_deletables', ['filterableAttributes' => ['deleted_at', '__soft_deleted']]);
        $this->bindEngine($engine);

        [$code] = $this->runCommand();

        $this->assertSame(0, $code);
    }

    public function testDriverOptionOverridesDefault(): void
    {
        ScoutConfig::setSource(scoutSource([
            'driver' => 'meilisearch',
            'prefix' => 'scout_',
            'soft_delete' => false,
            'typesense' => ['index-settings' => []],
        ]));

        $engine = Mockery::mock(Engine::class, UpdatesIndexSettings::class);
        $manager = Mockery::mock(EngineManager::class);
        $manager->shouldReceive('engine')->once()->with('typesense')->andReturn($engine);
        Container::getInstance()->instance(EngineManager::class, $manager);

        [$code, $out] = $this->runCommand(['--driver' => 'typesense']);

        $this->assertSame(0, $code);
        $this->assertStringContainsString('No index settings found for the "typesense" engine.', $out);
    }

    public function testFailsWhenEngineDoesNotSupportSettings(): void
    {
        $this->bindEngine(Mockery::mock(Engine::class));

        [$code, $out] = $this->runCommand();

        $this->assertSame(1, $code);
        $this->assertStringContainsString('The "meilisearch" engine does not support updating index settings.', $out);
    }

    public function testFailureWhenUpdateThrows(): void
    {
        ScoutConfig::setSource(scoutSource([
            'driver' => 'meilisearch',
            'prefix' => 'scout_',
            'soft_delete' => false,
            'meilisearch' => ['index-settings' => ['posts' => ['x' => 1]]],
        ]));

        $engine = Mockery::mock(Engine::class, UpdatesIndexSettings::class);
        $engine->shouldReceive('updateIndexSettings')->once()->andThrow(new \RuntimeException('settings boom'));
        $this->bindEngine($engine);

        [$code, $out] = $this->runCommand();

        $this->assertSame(1, $code);
        $this->assertStringContainsString('settings boom', $out);
    }
}
