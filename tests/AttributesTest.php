<?php

namespace Erikwang2013\WebmanScout\Tests;

use Erikwang2013\WebmanScout\Attributes\SearchUsingFullText;
use Erikwang2013\WebmanScout\Attributes\SearchUsingPrefix;
use PHPUnit\Framework\TestCase;

class AttributesTest extends TestCase
{
    public function testSearchUsingFullTextWrapsColumnsAndOptions(): void
    {
        $attribute = new SearchUsingFullText(['title', 'body'], ['mode' => 'boolean']);

        $this->assertSame(['title', 'body'], $attribute->columns);
        $this->assertSame(['mode' => 'boolean'], $attribute->options);
    }

    public function testSearchUsingFullTextAcceptsSingleStringColumn(): void
    {
        $attribute = new SearchUsingFullText('title');

        $this->assertSame(['title'], $attribute->columns);
        $this->assertSame([], $attribute->options);
    }

    public function testSearchUsingFullTextDefaultsOptions(): void
    {
        $attribute = new SearchUsingFullText(['title']);

        $this->assertSame([], $attribute->options);
    }

    public function testSearchUsingPrefixWrapsColumns(): void
    {
        $attribute = new SearchUsingPrefix(['name', 'slug']);

        $this->assertSame(['name', 'slug'], $attribute->columns);
    }

    public function testSearchUsingPrefixAcceptsSingleString(): void
    {
        $attribute = new SearchUsingPrefix('name');

        $this->assertSame(['name'], $attribute->columns);
    }
}
