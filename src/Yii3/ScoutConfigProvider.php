<?php

/**
 * Copyright (c) erik <erik@erik.xyz> (https://erik.xyz). All Rights Reserved.
 */

namespace Erikwang2013\WebmanScout\Yii3;

use Erikwang2013\WebmanScout\ScoutConfig;
use Erikwang2013\WebmanScout\Support\Cache;
use Erikwang2013\WebmanScout\Support\Log;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;
use Yiisoft\Config\ConfigInterface;
use Yiisoft\Config\ConfigProviderInterface;

/**
 * Yii3 config plugin: injects the default `scout` params and wires the
 * Scout config/cache/log seams to the Yii3 container.
 *
 * Register in config-plugin.php:
 *   'erikwang2013/webman-scout' => [ScoutConfigProvider::class]
 *
 * The `scout` params default to the package's webman config and can be
 * overridden under the `scout` key of the app params.
 */
final class ScoutConfigProvider implements ConfigProviderInterface
{
    private array $defaultParams;

    public function __construct(?ContainerInterface $container = null)
    {
        $this->defaultParams = require __DIR__ . '/config/params.php';

        ScoutConfig::setSource(function (string $key, $default = null) use ($container) {
            $params = $this->resolveParams($container);

            foreach (explode('.', $key) as $segment) {
                if (! is_array($params) || ! array_key_exists($segment, $params)) {
                    return $default;
                }
                $params = $params[$segment];
            }

            return $params;
        });

        if ($container !== null) {
            Cache::setPsr16Resolver(
                static fn () => $container->has(CacheInterface::class) ? $container->get(CacheInterface::class) : null
            );
            Log::setLoggerResolver(
                static fn () => $container->has(LoggerInterface::class) ? $container->get(LoggerInterface::class) : null
            );
        }
    }

    /**
     * Prefer the merged app params (so app-level `scout` overrides win);
     * fall back to the bundled defaults wrapped under the `scout` key when the
     * config is not in the container, so lookups always resolve the same way.
     */
    private function resolveParams(?ContainerInterface $container): array
    {
        if ($container !== null && $container->has(ConfigInterface::class)) {
            try {
                $params = $container->get(ConfigInterface::class)->get('params');

                if (is_array($params) && array_key_exists('scout', $params)) {
                    return $params;
                }
            } catch (\Throwable $e) {
                // fall through to defaults
            }
        }

        return ['scout' => $this->defaultParams];
    }

    public function getDefinitions(): array
    {
        return [];
    }

    public function getExtensions(): array
    {
        return [];
    }

    public function getConfigFiles(): array
    {
        return [];
    }

    public function getParams(): array
    {
        return ['scout' => $this->defaultParams];
    }

    public function getMetadata(): array
    {
        return [];
    }
}
