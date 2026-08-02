<?php

namespace Erikwang2013\WebmanScout\Support;

/**
 * Cross-framework cache adapter.
 * Delegates to Webman's support\Cache when available, otherwise falls back to Illuminate Cache facade.
 */
class Cache
{
    public static function __callStatic($name, $arguments)
    {
        if (class_exists('support\Cache')) {
            return \support\Cache::$name(...$arguments);
        }
        return \Illuminate\Support\Facades\Cache::$name(...$arguments);
    }
}
