<?php

/**
 * Copyright (c) erik <erik@erik.xyz> (https://erik.xyz). All Rights Reserved.
 */

namespace Erikwang2013\WebmanScout\Support;

use Illuminate\Contracts\Cache\Store;
use Psr\SimpleCache\CacheInterface;

/**
 * Adapts a PSR-16 cache (Yii3) to Illuminate\Contracts\Cache\Store.
 */
class Psr16Store implements Store
{
    protected CacheInterface $cache;

    public function __construct(CacheInterface $cache)
    {
        $this->cache = $cache;
    }

    public function get($key)
    {
        return $this->cache->get($key);
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
        return $this->cache->set($key, $value, $ttl);
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
        if (! $this->cache->set($key, $current + $value)) {
            return false;
        }

        return $current + $value;
    }

    public function decrement($key, $value = 1)
    {
        return $this->increment($key, -$value);
    }

    public function forever($key, $value)
    {
        return $this->cache->set($key, $value, null);
    }

    /**
     * Illuminate Store 契约在 Laravel 13 新增。PSR-16 没有 touch，
     * 用哨兵对象区分"键不存在"与"值为 null"，再原值重写以延长 TTL。
     */
    public function touch($key, $seconds)
    {
        $miss = new \stdClass();
        $value = $this->cache->get($key, $miss);

        if ($value === $miss) {
            return false;
        }

        return $this->cache->set($key, $value, $seconds);
    }

    public function forget($key)
    {
        return $this->cache->delete($key);
    }

    public function flush()
    {
        return $this->cache->clear();
    }

    public function getPrefix()
    {
        return '';
    }
}
