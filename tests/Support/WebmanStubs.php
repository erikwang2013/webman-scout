<?php

/*
 * Test-only stubs for optional host packages that are not installed in this
 * environment (webman/redis-queue, yiisoft/yii2). They let the package's
 * webman/Yii integration classes be loaded and exercised in isolation.
 */

namespace Webman\RedisQueue {
    interface Consumer
    {
        public function consume($data);
    }

    class Redis
    {
        public static $sent = [];

        public static function send($queue, $data, $delay = 0)
        {
            static::$sent[] = ['queue' => $queue, 'data' => $data, 'delay' => $delay];
        }

        public static function resetSent()
        {
            static::$sent = [];
        }
    }
}

namespace yii\caching {
    class Cache
    {
        public function get($key, $default = null)
        {
            return $default;
        }

        public function set($key, $value, $duration = 0, $dependency = null)
        {
            return true;
        }

        public function delete($key)
        {
            return true;
        }

        public function flush()
        {
            return true;
        }
    }
}

namespace yii\console {
    class Controller
    {
        public $id;

        public function __construct($id = 'scout', $module = null, $config = [])
        {
            $this->id = $id;
        }
    }
}

namespace Yiisoft\Config {
    if (! interface_exists('Yiisoft\Config\ConfigProviderInterface')) {
        interface ConfigProviderInterface
        {
            public function getDefinitions(): array;

            public function getExtensions(): array;

            public function getConfigFiles(): array;

            public function getParams(): array;

            public function getMetadata(): array;
        }
    }

    if (! interface_exists('Yiisoft\Config\ConfigInterface')) {
        interface ConfigInterface
        {
            public function get(string $name);

            public function has(string $name);
        }
    }
}

namespace {
    if (! function_exists('base_path')) {
        function base_path()
        {
            return getenv('WEBMAN_SCOUT_TEST_BASE') ?: sys_get_temp_dir() . '/webman-scout-test-base';
        }
    }

    if (! function_exists('copy_dir')) {
        function copy_dir($source, $dest)
        {
            if (! is_dir($source)) {
                return;
            }
            if (! is_dir($dest)) {
                mkdir($dest, 0777, true);
            }
            foreach (scandir($source) as $item) {
                if ($item === '.' || $item === '..') {
                    continue;
                }
                $src = $source . '/' . $item;
                $dst = $dest . '/' . $item;
                if (is_dir($src)) {
                    copy_dir($src, $dst);
                } else {
                    copy($src, $dst);
                }
            }
        }
    }

    if (! function_exists('remove_dir')) {
        function remove_dir($path)
        {
            if (is_file($path) || is_link($path)) {
                return unlink($path);
            }
            if (! is_dir($path)) {
                return true;
            }
            foreach (scandir($path) as $item) {
                if ($item === '.' || $item === '..') {
                    continue;
                }
                remove_dir($path . '/' . $item);
            }

            return rmdir($path);
        }
    }
}
