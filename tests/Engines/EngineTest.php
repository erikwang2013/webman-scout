<?php

namespace Erikwang2013\WebmanScout\Tests\Engines;

use Erikwang2013\WebmanScout\Builder;
use Erikwang2013\WebmanScout\Engines\Engine;
use Erikwang2013\WebmanScout\Tests\TestCase;
use Illuminate\Support\Collection;

class EngineTest extends TestCase
{
    /**
     * Minimal concrete engine exposing the abstract base helpers.
     */
    protected function makeEngine(): Engine
    {
        return new class extends Engine {
            public function update($models) { return 'updated'; }
            public function delete($models) { return 'deleted'; }
            public function search(Builder $builder) { return ['hits' => [['id' => 3], ['id' => 1], ['id' => 2]]]; }
            public function paginate(Builder $builder, $perPage, $page) { return ['hits' => [['id' => 1]]]; }
            public function mapIds($results) { return collect($results['hits'])->pluck('id'); }
            public function map(Builder $builder, $results, $model) { return new Collection([$results['hits'][0]['id']]); }
            public function lazyMap(Builder $builder, $results, $model) { return \Illuminate\Support\LazyCollection::make([1, 2]); }
            public function getTotalCount($results) { return 3; }
            public function flush($model) { return 'flushed'; }
            public function createIndex($name, array $options = []) { return 'created'; }
            public function deleteIndex($name) { return 'deleted-index'; }
        };
    }

    public function testMapIdsFromDelegatesToMapIds(): void
    {
        $engine = $this->makeEngine();
        $ids = $engine->mapIdsFrom(['hits' => [['id' => 7]]], 'id');

        $this->assertInstanceOf(Collection::class, $ids);
        $this->assertSame([7], $ids->all());
    }

    public function testKeysMapsSearchResultsToPrimaryKeys(): void
    {
        $engine = $this->makeEngine();
        $builder = new Builder(new \stdClass(), 'foo');

        $this->assertSame([3, 1, 2], $engine->keys($builder)->all());
    }

    public function testGetMapsResultsThroughModelAndAppliesAfterRawSearchCallback(): void
    {
        $engine = $this->makeEngine();
        $builder = new Builder(new \stdClass(), 'foo');
        $builder->withRawResults(function ($results) {
            $results['hits'] = array_map(fn ($hit) => ['id' => $hit['id'] * 10], $results['hits']);

            return $results;
        });

        $result = $engine->get($builder);

        // Raw callback ran (ids became 30/10/20) before map() consumed the raw hits.
        $this->assertSame([30], $result->all());
    }

    public function testCursorUsesLazyMapWithRawCallbackApplied(): void
    {
        $engine = $this->makeEngine();
        $builder = new Builder(new \stdClass(), 'foo');
        $builder->withRawResults(function ($results) {
            return ['hits' => [['id' => 99]]];
        });

        $this->assertSame([1, 2], $engine->cursor($builder)->all());
    }
}
