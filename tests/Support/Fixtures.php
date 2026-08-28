<?php

namespace Erikwang2013\WebmanScout\Tests\Support;

use Erikwang2013\WebmanScout\Engines\Engine;
use Erikwang2013\WebmanScout\Searchable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Shared test fixtures: a searchable Eloquent model with static hooks for the
 * command/job code paths, and a concrete Engine subclass exposing
 * deleteAllIndexes() (Mockery mocks never pass method_exists()).
 */
class TestSearchableModel extends Model
{
    use Searchable;

    public static $lastChunk = null;
    public static $flushed = false;
    public static $query = null;

    public $timestamps = false;

    protected $table = 'test_searchables';

    public static function makeAllSearchable($chunk = null)
    {
        static::$lastChunk = $chunk;
    }

    public static function removeAllFromSearch()
    {
        static::$flushed = true;
    }

    public static function makeAllSearchableQuery()
    {
        return static::$query;
    }

    public function newQuery()
    {
        return static::$query ?: parent::newQuery();
    }

    public static function resetHooks()
    {
        static::$lastChunk = null;
        static::$flushed = false;
        static::$query = null;
    }
}

class TestSoftDeletingModel extends Model
{
    use Searchable, SoftDeletes;

    public $timestamps = false;

    protected $table = 'soft_deletables';
}

class TestEngine extends Engine
{
    public $deletedAll = false;
    public $lastUpdateSettings = null;

    public function update($models)
    {
    }

    public function delete($models)
    {
    }

    public function search(\Erikwang2013\WebmanScout\Builder $builder)
    {
    }

    public function paginate(\Erikwang2013\WebmanScout\Builder $builder, $perPage, $page)
    {
    }

    public function mapIds($results)
    {
    }

    public function map(\Erikwang2013\WebmanScout\Builder $builder, $results, $model)
    {
    }

    public function lazyMap(\Erikwang2013\WebmanScout\Builder $builder, $results, $model)
    {
    }

    public function getTotalCount($results)
    {
    }

    public function flush($model)
    {
    }

    public function createIndex($name, array $options = [])
    {
    }

    public function deleteIndex($name)
    {
    }

    public function deleteAllIndexes()
    {
        $this->deletedAll = true;
    }
}

/**
 * Dot-walking config source like the one SmokeTest uses. Params are nested
 * under the "scout" root so ScoutConfig::baseKey() resolves to "scout".
 */
function scoutSource(array $params)
{
    return static function (string $key, $default = null) use ($params) {
        $tree = ['scout' => $params];

        foreach (explode('.', $key) as $segment) {
            if (! is_array($tree) || ! array_key_exists($segment, $tree)) {
                return $default;
            }
            $tree = $tree[$segment];
        }

        return $tree;
    };
}
