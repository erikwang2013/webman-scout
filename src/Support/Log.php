<?php

namespace Erikwang2013\WebmanScout\Support;

/**
 * Cross-framework logging adapter.
 * Delegates to Webman's support\Log when available, otherwise falls back to Illuminate Log facade.
 */
class Log
{
    public static function __callStatic($name, $arguments)
    {
        if (class_exists('support\Log')) {
            return \support\Log::$name(...$arguments);
        }
        return \Illuminate\Support\Facades\Log::$name(...$arguments);
    }
}
