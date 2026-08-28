<?php

namespace Erikwang2013\WebmanScout\Tests\Exceptions;

use Erikwang2013\WebmanScout\Exceptions\NotSupportedException;
use Erikwang2013\WebmanScout\Exceptions\ScoutException;
use PHPUnit\Framework\TestCase;

class ExceptionsTest extends TestCase
{
    public function testScoutExceptionExtendsException(): void
    {
        $e = new ScoutException('message', 42);

        $this->assertInstanceOf(\Exception::class, $e);
        $this->assertSame('message', $e->getMessage());
        $this->assertSame(42, $e->getCode());
    }

    public function testNotSupportedExceptionExtendsScoutException(): void
    {
        $e = new NotSupportedException('nope', 7, $previous = new \Exception('previous'));

        $this->assertInstanceOf(ScoutException::class, $e);
        $this->assertSame('nope', $e->getMessage());
        $this->assertSame(7, $e->getCode());
        $this->assertSame($previous, $e->getPrevious());
    }
}
