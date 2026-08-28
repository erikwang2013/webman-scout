<?php

namespace Erikwang2013\WebmanScout\Tests\Events;

require_once __DIR__ . '/../Support/Fixtures.php';

use Erikwang2013\WebmanScout\Events\ModelsFlushed;
use Erikwang2013\WebmanScout\Events\ModelsImported;
use Erikwang2013\WebmanScout\Tests\Support\TestSearchableModel;
use Illuminate\Database\Eloquent\Collection;
use PHPUnit\Framework\TestCase;

class EventsTest extends TestCase
{
    public function testModelsImportedCarriesModels(): void
    {
        $models = new Collection([new TestSearchableModel]);

        $event = new ModelsImported($models);

        $this->assertSame($models, $event->models);
    }

    public function testModelsFlushedCarriesModels(): void
    {
        $models = new Collection([new TestSearchableModel]);

        $event = new ModelsFlushed($models);

        $this->assertSame($models, $event->models);
    }
}
