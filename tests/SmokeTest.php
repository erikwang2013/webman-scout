<?php

namespace Erikwang2013\WebmanScout\Tests;

use Erikwang2013\WebmanScout\Builder;
use Erikwang2013\WebmanScout\Scout;
use Erikwang2013\WebmanScout\ScoutConfig;
use PHPUnit\Framework\TestCase;

class SmokeTest extends TestCase
{
    public function testVersionConstant(): void
    {
        $this->assertSame('10.23.0', Scout::VERSION);
    }

    public function testScoutConfigSourceResolution(): void
    {
        ScoutConfig::setSource(static function (string $key, $default = null) {
            $params = [
                'scout' => [
                    'driver' => 'meilisearch',
                    'prefix' => 'posts_',
                    'meilisearch' => ['host' => 'http://127.0.0.1:7700'],
                ],
            ];

            foreach (explode('.', $key) as $segment) {
                if (! is_array($params) || ! array_key_exists($segment, $params)) {
                    return $default;
                }
                $params = $params[$segment];
            }

            return $params;
        });

        $this->assertSame('scout', ScoutConfig::baseKey());
        $this->assertSame('meilisearch', ScoutConfig::get('driver'));
        $this->assertSame('posts_', ScoutConfig::get('prefix'));
        $this->assertSame('http://127.0.0.1:7700', ScoutConfig::get('meilisearch.host'));
        $this->assertNull(ScoutConfig::get('missing.key'));
        $this->assertSame('fallback', ScoutConfig::get('missing.key', 'fallback'));

        ScoutConfig::setSource(null);
        ScoutConfig::resetResolvedBase();
    }

    public function testConfigPolyfillYii3Branch(): void
    {
        if (! function_exists('config')) {
            require __DIR__ . '/../helpers.php';
        }

        if (function_exists('config') && realpath((string) (new \ReflectionFunction('config'))->getFileName()) !== realpath(__DIR__ . '/../helpers.php')) {
            $this->markTestSkipped('Host framework provides its own config(); polyfill branch not exercised.');
        }

        ScoutConfig::setSource(static function (string $key, $default = null) {
            $params = ['scout' => ['driver' => 'typesense']];

            foreach (explode('.', $key) as $segment) {
                if (! is_array($params) || ! array_key_exists($segment, $params)) {
                    return $default;
                }
                $params = $params[$segment];
            }

            return $params;
        });

        $this->assertSame('typesense', config('scout.driver'));
        $this->assertNull(config('scout.missing'));

        ScoutConfig::setSource(null);
        ScoutConfig::resetResolvedBase();
    }

    public function testBuilderDefaults(): void
    {
        $builder = new Builder(new \stdClass(), 'foo');

        $this->assertSame('foo', $builder->query);
        $this->assertSame([], $builder->wheres);
        $this->assertSame([], $builder->whereIns);
        $this->assertNull($builder->index);
    }

    public function testBuilderWhere(): void
    {
        $builder = new Builder(new \stdClass(), 'foo');

        $builder->where('status', 1);

        $this->assertSame(['status' => 1], $builder->wheres);
    }
}
