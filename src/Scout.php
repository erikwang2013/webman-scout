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
    const VERSION = '10.23.0';

    /**
     * Get a Scout engine instance.
     */
    public static function engine(string $engine): Engine
    {
        return app(EngineManager::class)->engine($engine);
    }
}
