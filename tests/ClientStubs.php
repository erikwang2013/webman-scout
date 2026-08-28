<?php

/**
 * Minimal client stubs for optional engine SDKs that are not installed in this
 * environment. Declared only when the real package is absent, so the suite also
 * runs against the real SDKs when they are installed.
 */

namespace Elastic\Elasticsearch {
    if (! class_exists('Elastic\Elasticsearch\Client')) {
        class Client
        {
        }
    }
}

namespace OpenSearch {
    if (! class_exists('OpenSearch\Client')) {
        class Client
        {
        }
    }
}

namespace Algolia\AlgoliaSearch {
    if (! class_exists('Algolia\AlgoliaSearch\SearchClient')) {
        // Algolia SDK v3 namespace (v4 installs Algolia\AlgoliaSearch\Api\SearchClient).
        class SearchClient
        {
        }
    }
}

namespace {
    if (! class_exists('XS')) {
        class XS
        {
            public $search;

            public $index;

            public function getSearch()
            {
                return $this->search;
            }

            public function getIndex()
            {
                return $this->index;
            }
        }
    }

    if (! class_exists('XSSearch')) {
        class XSSearch
        {
            public function setAutoSynonyms(): void
            {
            }

            public function setCollapse($field): void
            {
            }

            public function setSemantic(bool $enabled = true): void
            {
            }

            public function setPinyin(bool $enabled = true): void
            {
            }
        }
    }

    if (! class_exists('XSIndex')) {
        class XSIndex
        {
            public function optimize(): void
            {
            }
        }
    }

    if (! class_exists('XSDocument')) {
        class XSDocument
        {
            public array $fields = [];

            public function setFields(array $fields): void
            {
                $this->fields = $fields;
            }

            public function getFields(): array
            {
                return $this->fields;
            }

            public function id()
            {
                return $this->fields['id'] ?? null;
            }

            public function score(): float
            {
                return 0.0;
            }

            public function percent(): int
            {
                return 0;
            }

            public function terms(): array
            {
                return [];
            }

            public function matched(): bool
            {
                return true;
            }

            public function highlight(): array
            {
                return [];
            }

            public function relevance(): array
            {
                return [];
            }
        }
    }
}
