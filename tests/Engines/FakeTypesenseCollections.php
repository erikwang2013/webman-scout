<?php

namespace Erikwang2013\WebmanScout\Tests\Engines;

use Typesense\Collections;

/**
 * Typesense\Collections::__get() touches a private typed $apiCall, so it cannot
 * be mocked with Mockery (parent constructor is never called). A tiny subclass
 * replaces the magic getter with a plain array map.
 */
class FakeTypesenseCollections extends Collections
{
    public array $collections = [];

    public array $createdSchemas = [];

    public function __construct()
    {
    }

    public function __get($collectionName)
    {
        return $this->collections[$collectionName] ?? null;
    }

    public function create(array $schema): array
    {
        $this->createdSchemas[] = $schema;

        return [];
    }
}
