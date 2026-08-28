<?php

namespace Erikwang2013\WebmanScout\Tests\Command;

require_once __DIR__ . '/../Support/WebmanStubs.php';
require_once __DIR__ . '/../Support/Fixtures.php';

use Erikwang2013\WebmanScout\Command\FlushCommand;
use Erikwang2013\WebmanScout\Exceptions\ScoutException;
use Erikwang2013\WebmanScout\Tests\Support\TestSearchableModel;
use Illuminate\Container\Container;
use Mockery;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

class FlushCommandTest extends TestCase
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

    public function testFlushesModel(): void
    {
        $output = new BufferedOutput();
        $code = (new FlushCommand())->run(new ArrayInput(['model' => TestSearchableModel::class]), $output);

        $this->assertSame(0, $code);
        $this->assertTrue(TestSearchableModel::$flushed);
        $this->assertStringContainsString('All [' . TestSearchableModel::class . '] records have been flushed.', $output->fetch());
    }

    public function testFailureWhenModelNotFound(): void
    {
        $output = new BufferedOutput();
        $code = (new FlushCommand())->run(new ArrayInput(['model' => 'MissingModel']), $output);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('Model [MissingModel] not found.', $output->fetch());
    }
}
