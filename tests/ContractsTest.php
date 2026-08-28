<?php

namespace Erikwang2013\WebmanScout\Tests;

use Erikwang2013\WebmanScout\Contracts\PaginatesEloquentModels;
use Erikwang2013\WebmanScout\Contracts\PaginatesEloquentModelsUsingDatabase;
use Erikwang2013\WebmanScout\Contracts\UpdatesIndexSettings;
use PHPUnit\Framework\TestCase;

class ContractsTest extends TestCase
{
    public function testInterfacesExist(): void
    {
        $this->assertTrue(interface_exists(PaginatesEloquentModels::class));
        $this->assertTrue(interface_exists(PaginatesEloquentModelsUsingDatabase::class));
        $this->assertTrue(interface_exists(UpdatesIndexSettings::class));
    }

    public function testPaginatesEloquentModelsMethodSignatures(): void
    {
        $reflection = new \ReflectionClass(PaginatesEloquentModels::class);

        $paginate = $reflection->getMethod('paginate');
        $this->assertTrue($paginate->isPublic());
        $this->assertSame(['builder', 'perPage', 'page'], array_map(fn ($p) => $p->getName(), $paginate->getParameters()));

        $simple = $reflection->getMethod('simplePaginate');
        $this->assertTrue($simple->isPublic());
        $this->assertSame(['builder', 'perPage', 'page'], array_map(fn ($p) => $p->getName(), $simple->getParameters()));
    }

    public function testPaginatesEloquentModelsUsingDatabaseMethodSignatures(): void
    {
        $reflection = new \ReflectionClass(PaginatesEloquentModelsUsingDatabase::class);

        $paginate = $reflection->getMethod('paginateUsingDatabase');
        $this->assertSame(['builder', 'perPage', 'pageName', 'page'], array_map(fn ($p) => $p->getName(), $paginate->getParameters()));

        $simple = $reflection->getMethod('simplePaginateUsingDatabase');
        $this->assertSame(['builder', 'perPage', 'pageName', 'page'], array_map(fn ($p) => $p->getName(), $simple->getParameters()));
    }

    public function testUpdatesIndexSettingsMethodSignatures(): void
    {
        $reflection = new \ReflectionClass(UpdatesIndexSettings::class);

        $update = $reflection->getMethod('updateIndexSettings');
        $this->assertSame(['name', 'settings'], array_map(fn ($p) => $p->getName(), $update->getParameters()));
        $this->assertSame([], $update->getParameters()[1]->getDefaultValue());

        $configure = $reflection->getMethod('configureSoftDeleteFilter');
        $this->assertSame(['settings'], array_map(fn ($p) => $p->getName(), $configure->getParameters()));
    }
}
