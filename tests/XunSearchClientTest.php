<?php

namespace Erikwang2013\WebmanScout\Tests;

require_once __DIR__.'/ClientStubs.php';

use Erikwang2013\WebmanScout\Exceptions\ScoutException;
use Erikwang2013\WebmanScout\ScoutConfig;
use Erikwang2013\WebmanScout\Tests\TestCase;
use Erikwang2013\WebmanScout\XunSearchClient;
use Mockery;

class XunSearchClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        ScoutConfig::setSource(fn ($key, $default) => $key === 'xunsearch' || str_ends_with($key, '.xunsearch') ? ['config_path' => ''] : $default);
    }

    public function testTaskCreatesProjectFromShippedIni(): void
    {
        $client = new XunSearchClient;
        $xs = $client->task('demo');

        $this->assertInstanceOf(\XS::class, $xs);
        $this->assertSame($xs, $client->task('demo')); // cached
    }

    public function testTaskThrowsWhenIniMissing(): void
    {
        $client = new XunSearchClient;

        $this->expectException(ScoutException::class);
        $this->expectExceptionMessage('XunSearch project config not found: nonexistent.ini');

        $client->task('nonexistent');
    }

    public function testRefreshIsAliasOfTask(): void
    {
        $client = new XunSearchClient;

        $this->assertSame($client->task('demo'), $client->refresh('demo'));
    }

    public function testGetSearchReturnsCurrentProjectSearch(): void
    {
        $client = new XunSearchClient;
        $search = Mockery::mock(\XSSearch::class);
        $client->task('demo')->search = $search;

        $this->assertSame($search, $client->getSearch());
    }

    public function testCreateIndexReturnsIndex(): void
    {
        $client = new XunSearchClient;
        $index = Mockery::mock(\XSIndex::class);
        $client->task('demo')->index = $index;

        $this->assertSame($index, $client->createIndex('demo'));
    }
}
