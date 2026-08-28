<?php

namespace Erikwang2013\WebmanScout\Tests;

use Erikwang2013\WebmanScout\ScoutConfig;
use PHPUnit\Framework\TestCase;

class ScoutConfigTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('SCOUT_CONFIG_KEY');
        ScoutConfig::setSource(null);
        ScoutConfig::resetResolvedBase();
    }

    protected function arraySource(array $params): callable
    {
        return static function (string $key, $default = null) use ($params) {
            // Keys may contain literal dots (e.g. "plugin.erikwang2013.webman-scout.app").
            if (array_key_exists($key, $params)) {
                return $params[$key];
            }

            $dotted = array_keys($params);
            usort($dotted, fn ($a, $b) => strlen($b) <=> strlen($a));
            foreach ($dotted as $literal) {
                if (str_starts_with($key, $literal.'.')) {
                    $value = $params[$literal];
                    foreach (explode('.', substr($key, strlen($literal) + 1)) as $segment) {
                        if (! is_array($value) || ! array_key_exists($segment, $value)) {
                            return $default;
                        }
                        $value = $value[$segment];
                    }

                    return $value;
                }
            }

            foreach (explode('.', $key) as $segment) {
                if (! is_array($params) || ! array_key_exists($segment, $params)) {
                    return $default;
                }
                $params = $params[$segment];
            }

            return $params;
        };
    }

    public function testSetSourceNullReturnsDefault(): void
    {
        ScoutConfig::setSource(null);

        $this->assertNull(ScoutConfig::get('driver'));
        $this->assertSame('fallback', ScoutConfig::get('driver', 'fallback'));
        $this->assertNull(ScoutConfig::getSource('scout.driver'));
    }

    public function testGetSourceResolvesValueAndDefault(): void
    {
        ScoutConfig::setSource($this->arraySource(['scout' => ['driver' => 'meilisearch']]));

        $this->assertSame('meilisearch', ScoutConfig::getSource('scout.driver'));
        $this->assertSame('fallback', ScoutConfig::getSource('scout.missing', 'fallback'));
    }

    public function testGetSourceCatchesResolverExceptions(): void
    {
        ScoutConfig::setSource(static function (string $key, $default = null) {
            throw new \RuntimeException('boom');
        });

        $this->assertSame('safe', ScoutConfig::getSource('scout.driver', 'safe'));
    }

    public function testGetWithMultiLevelKeysAndDefaults(): void
    {
        ScoutConfig::setSource($this->arraySource([
            'scout' => [
                'driver' => 'typesense',
                'prefix' => 'pre_',
                'opensearch' => ['host' => 'http://127.0.0.1:9200'],
            ],
        ]));

        $this->assertSame('typesense', ScoutConfig::get('driver'));
        $this->assertSame('pre_', ScoutConfig::get('prefix'));
        $this->assertSame('http://127.0.0.1:9200', ScoutConfig::get('opensearch.host'));
        $this->assertNull(ScoutConfig::get('missing.key'));
        $this->assertSame('fb', ScoutConfig::get('missing.key', 'fb'));
    }

    public function testGetWithNullAndEmptyKeyReturnsWholeBase(): void
    {
        ScoutConfig::setSource($this->arraySource(['scout' => ['driver' => 'null', 'soft_delete' => true]]));

        $this->assertSame(['driver' => 'null', 'soft_delete' => true], ScoutConfig::get());
        $this->assertSame(['driver' => 'null', 'soft_delete' => true], ScoutConfig::get(''));
    }

    public function testBaseKeyEnvOverride(): void
    {
        putenv('SCOUT_CONFIG_KEY=custom_root');
        ScoutConfig::resetResolvedBase();

        $this->assertSame('custom_root', ScoutConfig::baseKey());
        // cached
        $this->assertSame('custom_root', ScoutConfig::baseKey());

        putenv('SCOUT_CONFIG_KEY');
        ScoutConfig::resetResolvedBase();
        $this->assertNotSame('custom_root', ScoutConfig::baseKey());
    }

    public function testBaseKeyPrefersWebmanPluginPath(): void
    {
        ScoutConfig::setSource($this->arraySource([
            'plugin.erikwang2013.webman-scout.app' => ['driver' => 'collection'],
        ]));

        $this->assertSame('plugin.erikwang2013.webman-scout.app', ScoutConfig::baseKey());
        $this->assertSame('collection', ScoutConfig::get('driver'));
    }

    public function testBaseKeyFallsBackToScoutKey(): void
    {
        ScoutConfig::setSource($this->arraySource(['scout' => ['driver' => 'meilisearch']]));

        $this->assertSame('scout', ScoutConfig::baseKey());
        $this->assertSame('meilisearch', ScoutConfig::get('driver'));
    }

    public function testBaseKeyFallsBackToPackageKey(): void
    {
        ScoutConfig::setSource($this->arraySource([
            'erikwang2013.webman-scout' => ['prefix' => 'pre_'],
        ]));

        $this->assertSame('erikwang2013.webman-scout', ScoutConfig::baseKey());
        $this->assertSame('pre_', ScoutConfig::get('prefix'));
    }

    public function testBaseKeyArrayWithoutDriverOrPrefixIsIgnored(): void
    {
        ScoutConfig::setSource($this->arraySource(['scout' => ['foo' => 'bar']]));

        $this->assertSame('plugin.erikwang2013.webman-scout.app', ScoutConfig::baseKey());
    }

    public function testBaseKeyDefaultWhenNothingMatches(): void
    {
        ScoutConfig::setSource($this->arraySource(['unrelated' => ['x' => 1]]));

        $this->assertSame('plugin.erikwang2013.webman-scout.app', ScoutConfig::baseKey());
        $this->assertNull(ScoutConfig::get('driver'));
    }

    public function testResetResolvedBaseForcesReResolution(): void
    {
        putenv('SCOUT_CONFIG_KEY=first');
        ScoutConfig::resetResolvedBase();
        $this->assertSame('first', ScoutConfig::baseKey());

        putenv('SCOUT_CONFIG_KEY=second');
        ScoutConfig::resetResolvedBase();
        $this->assertSame('second', ScoutConfig::baseKey());
    }
}
