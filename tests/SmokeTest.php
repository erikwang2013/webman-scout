<?php

namespace Erikwang2013\WebmanScout\Tests;

use Erikwang2013\WebmanScout\Builder;
use Erikwang2013\WebmanScout\Scout;
use PHPUnit\Framework\TestCase;

class SmokeTest extends TestCase
{
    public function testVersionConstant(): void
    {
        $this->assertSame('10.23.0', Scout::VERSION);
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
