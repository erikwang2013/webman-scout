<?php

/**
 * Copyright (c) erik <erik@erik.xyz> (https://erik.xyz). All Rights Reserved.
 */

use Erikwang2013\WebmanScout\EngineManager;
use Illuminate\Container\Container;
use Illuminate\Events\Dispatcher;
use Meilisearch\Client as MeilisearchClient;

if (! function_exists('app')) {
    /**
     * Get the available container instance.
     *
     * @param  string|null  $abstract
     * @param  array  $parameters
     * @return mixed|\Illuminate\Contracts\Foundation\Application
     */
    function app($abstract = null, array $parameters = [])
    {
        if (is_null($abstract)) {
            return Container::getInstance();
        }

        return Container::getInstance()->make($abstract, $parameters);
    }
}

if (! function_exists('event')) {
    /**
     * Dispatch an event and call the listeners.
     *
     * @param  string|object  $event
     * @param  mixed  $payload
     * @param  bool  $halt
     * @return array|null
     */
    function event(...$args)
    {
        return app(Dispatcher::class)->dispatch(...$args);
    }
}

if (! function_exists('scout_config')) {
    /**
     * Read a Scout option relative to the active config root (see ScoutConfig).
     *
     * @param  string|null  $key
     * @return mixed
     */
    function scout_config(?string $key = null, $default = null)
    {
        return \Erikwang2013\WebmanScout\ScoutConfig::get($key, $default);
    }
}

if (! function_exists('config')) {
    /**
     * Laravel-style config() polyfill for hosts without one (Yii2 / Yii3).
     *
     * Yii2: reads Yii::$app->params via dot notation.
     * Yii3: reads the params source registered by ScoutConfig::setSource()
     * (see Erikwang2013\WebmanScout\Yii3\ScoutConfigProvider).
     */
    function config($key = null, $default = null)
    {
        if (class_exists(\yii\base\Application::class) && isset(\Yii::$app)) {
            if ($key === 'app.debug') {
                return (bool) \Yii::$app->debug;
            }

            $params = \Yii::$app->params;
            foreach (explode('.', (string) $key) as $segment) {
                if (! is_array($params) || ! array_key_exists($segment, $params)) {
                    return $default;
                }
                $params = $params[$segment];
            }

            return $params;
        }

        return \Erikwang2013\WebmanScout\ScoutConfig::getSource((string) $key, $default);
    }
}

if (class_exists(MeilisearchClient::class)) {
    app()->singleton(MeilisearchClient::class, function () {
        $c = scout_config('meilisearch', []);

        return new MeilisearchClient(
            $c['host'] ?? 'http://127.0.0.1:7700',
            $c['key'] ?? null
        );
    });
}

app()->singleton(Dispatcher::class, function ($app) {
    return new Dispatcher($app);
});

app()->singleton(EngineManager::class, function ($app) {
    return new EngineManager($app);
});
