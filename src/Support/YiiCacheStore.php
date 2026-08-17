<?php

/**
 * Copyright (c) erik <erik@erik.xyz> (https://erik.xyz). All Rights Reserved.
 */

namespace Erikwang2013\WebmanScout\Support;

use Illuminate\Contracts\Cache\Store;

/**
 * Adapts yii\caching\Cache to Illuminate\Contracts\Cache\Store.
 */
class YiiCacheStore implements Store
{
    protected \yii\caching\Cache $cache;

    public function __construct(\yii\caching\Cache $cache)
    {
        $this->cache = $cache;
    }

    public function get($key)
    {
        return $this->cache->get($key) ?: null;
    }

    public function many(array $keys)
    {
        $result = [];
        foreach ($keys as $key) {
            $result[$key] = $this->get($key);
        }

        return $result;
    }

    public function put($key, $value, $ttl)
    {
        // Yii2: duration 0 means forever.
        return $this->cache->set($key, $value, $ttl === null ? 0 : max(0, (int) $ttl));
    }

    public function putMany(array $values, $ttl)
    {
        foreach ($values as $key => $value) {
            $this->put($key, $value, $ttl);
        }

        return true;
    }

    public function increment($key, $value = 1)
    {
        $current = (int) $this->get($key);

        return $this->cache->set($key, $current + $value) ? $current + $value : false;
    }

    public function decrement($key, $value = 1)
    {
        return $this->increment($key, -$value);
    }

    public function forever($key, $value)
    {
        return $this->cache->set($key, $value, 0);
    }

    public function forget($key)
    {
        return $this->cache->delete($key);
    }

    public function flush()
    {
        return $this->cache->flush();
    }

    public function getPrefix()
    {
        return '';
    }
}
