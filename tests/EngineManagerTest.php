<?php

namespace Erikwang2013\WebmanScout\Tests;

use Erikwang2013\WebmanScout\EngineManager;
use Erikwang2013\WebmanScout\Engines\AdvancedMeilisearchEngine;
use Erikwang2013\WebmanScout\Engines\AdvancedTypesenseEngine;
use Erikwang2013\WebmanScout\Engines\Algolia4Engine;
use Erikwang2013\WebmanScout\Engines\CollectionEngine;
use Erikwang2013\WebmanScout\Engines\DatabaseEngine;
use Erikwang2013\WebmanScout\Engines\MeilisearchEngine;
use Erikwang2013\WebmanScout\Engines\NullEngine;
use Erikwang2013\WebmanScout\Engines\TypesenseEngine;
use Erikwang2013\WebmanScout\Engines\XunSearchEngine;
use Erikwang2013\WebmanScout\Exceptions\ScoutException;
use Erikwang2013\WebmanScout\ScoutConfig;
use Illuminate\Container\Container;
use InvalidArgumentException;
use Mockery;
use PHPUnit\Framework\TestCase;

class EngineManagerTest extends TestCase
{
    protected $container;

    protected function setUp(): void
    {
        $this->container = Container::getInstance();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        ScoutConfig::setSource(null);
        ScoutConfig::resetResolvedBase();
    }

    protected function setConfig(array $params): void
    {
        ScoutConfig::setSource(static function (string $key, $default = null) use ($params) {
            foreach (explode('.', $key) as $segment) {
                if (! is_array($params) || ! array_key_exists($segment, $params)) {
                    return $default;
                }
                $params = $params[$segment];
            }

            return $params;
        });
    }

    public function testEngineAliasesDriver(): void
    {
        $manager = new EngineManager($this->container);

        $this->assertInstanceOf(NullEngine::class, $manager->engine('null'));
        $this->assertInstanceOf(CollectionEngine::class, $manager->engine('collection'));
        $this->assertInstanceOf(DatabaseEngine::class, $manager->engine('database'));
    }

    public function testEngineInstancesAreCached(): void
    {
        $manager = new EngineManager($this->container);

        $this->assertSame($manager->engine('null'), $manager->engine('null'));
    }

    public function testGetDefaultDriverFallsBackToNullForMissingOrEmptyConfig(): void
    {
        $manager = new EngineManager($this->container);

        $this->assertSame('null', $manager->getDefaultDriver());

        $this->setConfig(['scout' => ['driver' => '']]);
        $this->assertSame('null', $manager->getDefaultDriver());

        $this->setConfig(['scout' => ['driver' => false]]);
        $this->assertSame('null', $manager->getDefaultDriver());

        $this->setConfig(['scout' => ['driver' => 'meilisearch']]);
        $this->assertSame('meilisearch', $manager->getDefaultDriver());
    }

    public function testDriverWithoutArgumentUsesDefault(): void
    {
        $this->setConfig(['scout' => ['driver' => 'collection']]);

        $manager = new EngineManager($this->container);
        $this->assertInstanceOf(CollectionEngine::class, $manager->driver());
    }

    public function testUnknownDriverThrows(): void
    {
        $manager = new EngineManager($this->container);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Driver [bogus] not supported.');
        $manager->driver('bogus');
    }

    public function testMeilisearchDrivers(): void
    {
        $this->setConfig(['scout' => [
            'driver' => 'meilisearch',
            'meilisearch' => ['host' => 'http://127.0.0.1:7700'],
            'soft_delete' => true,
        ]]);

        $manager = new EngineManager($this->container);
        $this->assertInstanceOf(MeilisearchEngine::class, $manager->driver('meilisearch'));
        $this->assertInstanceOf(AdvancedMeilisearchEngine::class, $manager->driver('advanced-meilisearch'));
    }

    public function testTypesenseDrivers(): void
    {
        $this->setConfig([
            'scout' => [
                'driver' => 'typesense',
                'typesense' => [
                    'client-settings' => [
                        'api_key' => 'test-key',
                        'nodes' => [['host' => 'localhost', 'port' => 8108, 'protocol' => 'http']],
                    ],
                    'max_total_results' => 200,
                ],
            ],
        ]);

        $manager = new EngineManager($this->container);
        $this->assertInstanceOf(TypesenseEngine::class, $manager->driver('typesense'));
        $this->assertInstanceOf(AdvancedTypesenseEngine::class, $manager->driver('advanced-typesense'));
    }

    public function testAlgoliaDriverUsesV4Engine(): void
    {
        $this->setConfig(['scout' => ['driver' => 'algolia', 'soft_delete' => false, 'algolia' => ['id' => 'test-id', 'secret' => 'test-secret']]]);

        $manager = new EngineManager($this->container);
        $engine = $manager->driver('algolia');

        $this->assertInstanceOf(Algolia4Engine::class, $engine);
    }

    public function testElasticsearchMissingClientThrows(): void
    {
        $this->expectException(ScoutException::class);
        $this->expectExceptionMessage('Please install the ElasticSearch client: elasticsearch/elasticsearch.');

        (new EngineManager($this->container))->driver('elasticsearch');
    }

    public function testOpensearchMissingClientThrows(): void
    {
        $this->expectException(ScoutException::class);
        $this->expectExceptionMessage('Please install the OpenSearch client (^2.0): opensearch-project/opensearch-php.');

        (new EngineManager($this->container))->driver('opensearch');
    }

    public function testXunsearchDriver(): void
    {
        $manager = new EngineManager($this->container);

        // tests/XunSearchClientTest.php 加载 ClientStubs 后全局 XS 存在，此时应能构建引擎
        if (class_exists(\XS::class)) {
            $this->assertInstanceOf(XunSearchEngine::class, $manager->driver('xunsearch'));

            return;
        }

        $this->expectException(ScoutException::class);
        $this->expectExceptionMessage('Please install the XunSearch client: hightman/xunsearch.');

        $manager->driver('xunsearch');
    }

    public function testForgetEnginesClearsCache(): void
    {
        $manager = new EngineManager($this->container);
        $first = $manager->engine('null');

        $manager->forgetEngines();
        $this->assertNotSame($first, $manager->engine('null'));
    }
}
