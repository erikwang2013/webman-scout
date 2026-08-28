<?php

namespace Erikwang2013\WebmanScout\Tests\Command;

require_once __DIR__ . '/../Support/WebmanStubs.php';
require_once __DIR__ . '/../Support/Fixtures.php';

use Erikwang2013\WebmanScout\Command\QueueImportCommand;
use Erikwang2013\WebmanScout\ScoutConfig;
use Erikwang2013\WebmanScout\Tests\Support\TestSearchableModel;
use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Builder;
use Mockery;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Webman\RedisQueue\Redis as QueueRedis;

use function Erikwang2013\WebmanScout\Tests\Support\scoutSource;

class QueueImportCommandTest extends TestCase
{
    protected function setUp(): void
    {
        TestSearchableModel::resetHooks();
        QueueRedis::resetSent();
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

    private function bindQuery(): Builder
    {
        $query = Mockery::mock(Builder::class);
        TestSearchableModel::$query = $query;

        return $query;
    }

    private function runCommand(array $input): array
    {
        $output = new BufferedOutput();
        $code = (new QueueImportCommand())->run(new ArrayInput($input), $output);

        return [$code, $output->fetch()];
    }

    public function testQueuesRangesWithChunk(): void
    {
        $this->bindQuery();

        [$code, $out] = $this->runCommand([
            'model' => TestSearchableModel::class,
            '--min' => '1',
            '--max' => '5',
            '--chunk' => '2',
        ]);

        $this->assertSame(0, $code);
        $this->assertCount(3, QueueRedis::$sent);
        // 命令对 min/max 做 (int) 强转，start/end 均为 int
        $this->assertSame([
            ['queue' => 'scout_make_range', 'data' => ['model' => TestSearchableModel::class, 'start' => 1, 'end' => 2], 'delay' => 0],
            ['queue' => 'scout_make_range', 'data' => ['model' => TestSearchableModel::class, 'start' => 3, 'end' => 4], 'delay' => 0],
            ['queue' => 'scout_make_range', 'data' => ['model' => TestSearchableModel::class, 'start' => 5, 'end' => 5], 'delay' => 0],
        ], QueueRedis::$sent);
        $this->assertStringContainsString('All [' . TestSearchableModel::class . '] records have been queued for importing.', $out);
    }

    public function testDefaultsToQueryMinMaxAndConfigChunk(): void
    {
        $query = $this->bindQuery();
        $query->shouldReceive('min')->once()->with('id')->andReturn(1);
        $query->shouldReceive('max')->once()->with('id')->andReturn(10);

        [$code] = $this->runCommand(['model' => TestSearchableModel::class]);

        $this->assertSame(0, $code);
        $this->assertCount(1, QueueRedis::$sent);
        $this->assertSame([
            'model' => TestSearchableModel::class,
            'start' => 1,
            'end' => 10,
        ], QueueRedis::$sent[0]['data']);
    }

    public function testNoRecordsReturnsFailure(): void
    {
        $query = $this->bindQuery();
        $query->shouldReceive('min')->once()->with('id')->andReturn(null);
        $query->shouldReceive('max')->once()->with('id')->andReturn(null);

        [$code, $out] = $this->runCommand(['model' => TestSearchableModel::class]);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('No records found for [' . TestSearchableModel::class . '].', $out);
    }

    public function testNonNumericPrimaryKeyReturnsFailure(): void
    {
        $this->bindQuery();

        [$code, $out] = $this->runCommand([
            'model' => TestSearchableModel::class,
            '--min' => 'abc',
            '--max' => 'def',
        ]);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('The primary key for [' . TestSearchableModel::class . '] is not numeric.', $out);
    }

    public function testFailureWhenQueryThrows(): void
    {
        $query = $this->bindQuery();
        $query->shouldReceive('min')->once()->andThrow(new \RuntimeException('query boom'));

        [$code, $out] = $this->runCommand(['model' => TestSearchableModel::class]);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('query boom', $out);
    }
}
