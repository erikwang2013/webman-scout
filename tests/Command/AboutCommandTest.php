<?php

namespace Erikwang2013\WebmanScout\Tests\Command;

require_once __DIR__ . '/../Support/WebmanStubs.php';
require_once __DIR__ . '/../Support/Fixtures.php';

use Erikwang2013\WebmanScout\Command\AboutCommand;
use Erikwang2013\WebmanScout\Scout;
use Erikwang2013\WebmanScout\ScoutConfig;
use Illuminate\Container\Container;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

use function Erikwang2013\WebmanScout\Tests\Support\scoutSource;

class AboutCommandTest extends TestCase
{
    protected function tearDown(): void
    {
        ScoutConfig::setSource(null);
        ScoutConfig::resetResolvedBase();
        Container::getInstance()->flush();
    }

    private function runCommand(): array
    {
        $output = new BufferedOutput();
        $code = (new AboutCommand())->run(new ArrayInput([]), $output);

        return [$code, $output->fetch()];
    }

    public function testPrintsMascotAndResolvedConfig(): void
    {
        ScoutConfig::setSource(scoutSource([
            'driver' => 'opensearch',
            'prefix' => 'scout_',
            'queue' => false,
            'soft_delete' => true,
            'chunk' => ['searchable' => 250, 'unsearchable' => 100],
        ]));

        [$code, $out] = $this->runCommand();

        $this->assertSame(0, $code);
        $this->assertStringContainsString('|   o    o   |', $out, 'mascot face is printed');
        $this->assertStringContainsString('\__________/', $out, 'mascot body is printed');
        $this->assertStringContainsString('v' . Scout::VERSION, $out);
        $this->assertStringContainsString('scout', $out, 'config root key is shown');
        $this->assertStringContainsString('opensearch', $out, 'driver is shown');
        $this->assertStringContainsString('scout_', $out, 'prefix is shown');
        $this->assertStringContainsString('searchable=250 / unsearchable=100', $out);
        $this->assertStringContainsString('off（请求内同步索引）', $out);
    }

    public function testMarksBuiltinEnginesAvailableAndListsAllEngines(): void
    {
        ScoutConfig::setSource(scoutSource(['driver' => 'database']));

        [$code, $out] = $this->runCommand();

        $this->assertSame(0, $code);
        foreach (['database', 'collection', 'null', 'opensearch', 'elasticsearch',
                  'meilisearch', 'typesense', 'algolia', 'xunsearch'] as $engine) {
            $this->assertStringContainsString($engine, $out, "[$engine] is listed");
        }
        $this->assertStringContainsString('database       ✔', $out);
        $this->assertStringContainsString('collection     ✔', $out);
        $this->assertStringContainsString('null           ✔', $out);
    }

    public function testFallsBackToNullDriverWhenUnset(): void
    {
        ScoutConfig::setSource(scoutSource([]));

        [$code, $out] = $this->runCommand();

        $this->assertSame(0, $code);
        $this->assertStringContainsString('未配置，回退到 null 引擎', $out);
    }
}
