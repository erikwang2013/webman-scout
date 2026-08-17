<?php

/**
 * Copyright (c) erik <erik@erik.xyz> (https://erik.xyz). All Rights Reserved.
 */

namespace Erikwang2013\WebmanScout\Support;

use Psr\SimpleCache\CacheInterface;

/**
 * Cross-framework cache adapter.
 * Delegates to Webman's support\Cache when available, otherwise falls back to
 * the Illuminate Cache facade. Under Yii2/Yii3 the host cache is wrapped into
 * an Illuminate Store so existing callers (Cache::store()->get/put) work unchanged.
 */
class Cache
{
    /**
     * @var callable|null fn(): ?CacheInterface — set by the Yii3 ConfigProvider.
     */
    protected static $psr16Resolver;

    public static function setPsr16Resolver(?callable $resolver): void
    {
        static::$psr16Resolver = $resolver;
    }

    /**
     * Return the host cache wrapped as an Illuminate Store, or null when the
     * host is webman/Laravel (which route through their own facades).
     */
    protected static function storeInstance(): ?\Illuminate\Contracts\Cache\Store
    {
        if (class_exists(\yii\base\Application::class) && isset(\Yii::$app) && \Yii::$app->has('cache')) {
            $cache = \Yii::$app->get('cache');
            if ($cache instanceof \yii\caching\Cache) {
                return new YiiCacheStore($cache);
            }
        }

        $resolver = static::$psr16Resolver;
        if ($resolver !== null) {
            $psr16 = $resolver();
            if ($psr16 instanceof CacheInterface) {
                return new Psr16Store($psr16);
            }
        }

        return null;
    }

    public static function store($store = null)
    {
        if (class_exists('support\Cache')) {
            return \support\Cache::store($store);
        }

        $instance = static::storeInstance();
        if ($instance !== null) {
            return $instance;
        }

        return \Illuminate\Support\Facades\Cache::store($store);
    }

    public static function __callStatic($name, $arguments)
    {
        if (class_exists('support\Cache')) {
            return \support\Cache::$name(...$arguments);
        }

        $instance = static::storeInstance();
        if ($instance !== null) {
            return $instance->$name(...$arguments);
        }

        return \Illuminate\Support\Facades\Cache::$name(...$arguments);
    }
}
