<?php

/**
 * Copyright (c) erik <erik@erik.xyz> (https://erik.xyz). All Rights Reserved.
 */

namespace Erikwang2013\WebmanScout\Support;

use Illuminate\Contracts\Cache\Store;

/**
 * 进程内数组缓存（Illuminate Store 契约），供没有缓存组件的宿主兜底：
 * 原生 PHP、Hyperf / ThinkPHP / Yii 等未接 PSR-16 或 Illuminate cache 的环境。
 *
 * 数据存在静态属性里，同一进程内 `Cache::store()` 多次调用共享；进程结束即失效，
 * 因此只能当"请求内缓存"用。要跨请求缓存，请接入宿主缓存或
 * `Cache::setPsr16Resolver()`。
 */
class ArrayStore implements Store
{
    /**
     * @var array<string, array{0: int|null, 1: mixed}> key => [过期时间戳|null, 值]
     */
    protected static $items = [];

    /**
     * 单例：调用方每次 `Cache::store()` 都能拿到同一份数据。
     */
    protected static ?Store $instance = null;

    public static function instance(): Store
    {
        return static::$instance ??= new static();
    }

    public function get($key)
    {
        if (! array_key_exists($key, static::$items)) {
            return null;
        }

        [$expiresAt, $value] = static::$items[$key];

        if ($expiresAt !== null && $expiresAt <= time()) {
            unset(static::$items[$key]);

            return null;
        }

        return $value;
    }

    public function many(array $keys)
    {
        $values = [];

        foreach ($keys as $key) {
            $values[$key] = $this->get($key);
        }

        return $values;
    }

    public function put($key, $value, $seconds)
    {
        static::$items[$key] = [
            is_numeric($seconds) && $seconds > 0 ? time() + (int) $seconds : null,
            $value,
        ];

        return true;
    }

    public function putMany(array $values, $seconds)
    {
        foreach ($values as $key => $value) {
            $this->put($key, $value, $seconds);
        }

        return true;
    }

    public function increment($key, $value = 1)
    {
        $current = (int) $this->get($key);
        $this->put($key, $current + $value, 0);

        return $current + $value;
    }

    public function decrement($key, $value = 1)
    {
        return $this->increment($key, -$value);
    }

    public function forever($key, $value)
    {
        return $this->put($key, $value, 0);
    }

    public function forget($key)
    {
        unset(static::$items[$key]);

        return true;
    }

    public function flush()
    {
        static::$items = [];

        return true;
    }

    public function getPrefix()
    {
        return 'webman-scout-array:';
    }
}
