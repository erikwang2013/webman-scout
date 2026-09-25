<?php

/**
 * Copyright (c) erik <erik@erik.xyz> (https://erik.xyz). All Rights Reserved.
 */

namespace Erikwang2013\WebmanScout;

use Erikwang2013\WebmanScout\Engines\Engine;

class Scout
{
    /**
     * The Scout library version.
     *
     * @var string
     */
    const VERSION = '2.1.0';

    /**
     * Get a Scout engine instance.
     */
    public static function engine(string $engine): Engine
    {
        return app(EngineManager::class)->engine($engine);
    }

    /**
     * Configure Scout without a framework (plain PHP, CLI scripts, custom hosts).
     *
     *     Scout::configure(require __DIR__.'/scout.php');
     *     Scout::configure(['driver' => 'opensearch', 'prefix' => 'app_', ...]);
     *
     * `app()`, `event()` and `config()` come from this package's helpers.php, so no
     * container bootstrap is needed; models only need an Eloquent bootstrap of your
     * own (e.g. Illuminate\Database\Capsule\Manager).
     *
     * @param  array|string  $config  Config array, or path to a PHP file returning one.
     * @param  string  $root  Config root key the array is published under.
     */
    public static function configure($config, string $root = 'scout'): void
    {
        if (is_string($config)) {
            $config = is_file($config) ? require $config : [];
        }

        ScoutConfig::setArraySource(is_array($config) ? $config : [], $root);
    }
}
