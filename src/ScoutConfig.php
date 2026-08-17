<?php

/**
 * Copyright (c) erik <erik@erik.xyz> (https://erik.xyz). All Rights Reserved.
 */

namespace Erikwang2013\WebmanScout;

/**
 * Resolves the configuration root for Scout across Webman, Laravel, ThinkPHP, and Hyperf.
 *
 * Default: Webman plugin path `plugin.erikwang2013.webman-scout.app`.
 * Override: set env `SCOUT_CONFIG_KEY` to e.g. `scout` and publish the same array under `config/scout.php`.
 */
class ScoutConfig
{
    protected static ?string $resolvedBase = null;

    /**
     * Custom resolver: fn(string $key, $default) => mixed. Set by Yii3's ConfigProvider
     * (and consumed by the Yii2/3 `config()` polyfill in helpers.php).
     * No callable type declaration — property types for callable need PHP 8.4+.
     *
     * @var callable|null
     */
    protected static $customSource = null;

    public static function resetResolvedBase(): void
    {
        static::$resolvedBase = null;
    }

    public static function setSource(?callable $resolver): void
    {
        static::$customSource = $resolver;
        static::resetResolvedBase();
    }

    public static function getSource(string $key, $default = null)
    {
        $resolver = static::$customSource;
        if ($resolver === null) {
            return $default;
        }

        try {
            return $resolver($key, $default);
        } catch (\Throwable $e) {
            return $default;
        }
    }

    protected static function resolve(string $key, $default = null)
    {
        if (static::$customSource !== null) {
            return static::getSource($key, $default);
        }

        if (! function_exists('config')) {
            return $default;
        }

        try {
            return config($key, $default);
        } catch (\Throwable $e) {
            return $default;
        }
    }

    public static function baseKey(): string
    {
        if (static::$resolvedBase !== null) {
            return static::$resolvedBase;
        }

        $override = getenv('SCOUT_CONFIG_KEY');
        if (is_string($override) && $override !== '') {
            return static::$resolvedBase = rtrim($override, '.');
        }

        if (static::$customSource === null && ! function_exists('config')) {
            return static::$resolvedBase = 'plugin.erikwang2013.webman-scout.app';
        }

        $webman = static::resolve('plugin.erikwang2013.webman-scout.app');
        if (is_array($webman)) {
            return static::$resolvedBase = 'plugin.erikwang2013.webman-scout.app';
        }

        foreach (['scout', 'erikwang2013.webman-scout'] as $candidate) {
            $v = static::resolve($candidate);
            if (is_array($v) && (array_key_exists('driver', $v) || array_key_exists('prefix', $v))) {
                return static::$resolvedBase = $candidate;
            }
        }

        return static::$resolvedBase = 'plugin.erikwang2013.webman-scout.app';
    }

    /**
     * @param  string|null  $relativeKey  Dot key under the scout root (e.g. "driver", "opensearch.host").
     * @return mixed
     */
    public static function get(?string $relativeKey = null, $default = null)
    {
        $base = static::baseKey();

        if ($relativeKey === null || $relativeKey === '') {
            return static::resolve($base, $default);
        }

        return static::resolve($base.'.'.$relativeKey, $default);
    }
}
