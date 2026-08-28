<?php

namespace Erikwang2013\WebmanScout\Tests\Jobs;

require_once __DIR__ . '/../Support/Fixtures.php';

use Erikwang2013\WebmanScout\Jobs\RemoveableScoutCollection;
use Erikwang2013\WebmanScout\Tests\Support\TestSearchableModel;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Mockery;
use PHPUnit\Framework\TestCase;

class RemoveableScoutCollectionTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
    }

    public function testQueueableIdsUseScoutKeysForSearchableModels(): void
    {
        $model1 = new TestSearchableModel;
        $model1->id = 7;
        $model2 = new TestSearchableModel;
        $model2->id = 9;

        $collection = RemoveableScoutCollection::make(new Collection([$model1, $model2]));

        $this->assertSame([7, 9], $collection->getQueueableIds());
    }

    public function testQueueableIdsFallBackToEloquentKeys(): void
    {
        // Eloquent Model is abstract since Laravel 11; use a concrete subclass.
        $plain = new class extends Model {
            protected $table = 'plain_models';
        };
        $model1 = new $plain;
        $model1->id = 3;
        $model2 = new $plain;
        $model2->id = 4;

        $collection = RemoveableScoutCollection::make(new Collection([$model1, $model2]));

        $this->assertSame([3, 4], $collection->getQueueableIds());
    }

    public function testQueueableIdsEmptyWhenEmpty(): void
    {
        $this->assertSame([], (new RemoveableScoutCollection())->getQueueableIds());
    }
}
