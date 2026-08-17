<?php

/**
 * Copyright (c) erik <erik@erik.xyz> (https://erik.xyz). All Rights Reserved.
 */

namespace Erikwang2013\WebmanScout\Engines;

use Erikwang2013\WebmanScout\Builder as AdvancedScoutBuilder;
use Erikwang2013\WebmanScout\Support\Log;

class AdvancedMeilisearchEngine extends MeilisearchEngine
{
    public $prefix="";
    /**
     * 获取要检索的属性
     */
    protected function getAttributesToRetrieve(AdvancedScoutBuilder $builder): array
    {
        // 如果指定了特定字段，只返回这些字段
        if ($fields = $builder->options['attributesToRetrieve'] ?? null) {
            return is_array($fields) ? $fields : explode(',', $fields);
        }

        // 如果有包含字段
        if ($includes = $builder->options['includes'] ?? null) {
            return is_array($includes) ? $includes : explode(',', $includes);
        }

        // 如果有排除字段
        if ($excludes = $builder->options['excludes'] ?? null) {
            // Meilisearch 不支持排除字段，所以返回所有字段，后续过滤
            return ['*'];
        }

        // 默认返回所有字段
        return ['*'];
    }

    /**
     * 获取搜索字段
     */
    protected function getSearchFields(AdvancedScoutBuilder $builder): array
    {
        // 优先使用 builder 指定的字段
        if ($builder->options['fields'] ?? false) {
            return is_array($builder->options['fields'])
                ? $builder->options['fields']
                : explode(',', $builder->options['fields']);
        }

        // 使用模型定义的搜索字段
        if (method_exists($builder->model, 'searchableFields')) {
            $fields = $builder->model->searchableFields();

            // 如果是键值对格式，则提取键名
            if (array_values($fields) !== $fields) {
                return array_keys($fields);
            }

            return $fields;
        }

        // 默认搜索所有字段
        return ['*'];
    }

    /**
     * 获取高亮字段
     */
    protected function getHighlightFields(AdvancedScoutBuilder $builder): array
    {
        if ($highlightFields = $builder->options['highlight_fields'] ?? null) {
            return is_array($highlightFields) ? $highlightFields : explode(',', $highlightFields);
        }

        // 默认高亮所有搜索字段
        return $this->getSearchFields($builder);
    }

    /**
     * 执行搜索（增强版）
     */
    public function search(AdvancedScoutBuilder $builder)
    {
        $params = $this->buildSearchParams($builder);
        unset($params['query']);

        return $this->performSearch($builder, $params);
    }

    /**
     * 分页搜索（增强版）
     */
    public function paginate(AdvancedScoutBuilder $builder, $perPage, $page)
    {
        $params = $this->buildSearchParams($builder);
        unset($params['query'], $params['limit'], $params['offset']);
        $params['hitsPerPage'] = (int) $perPage;
        $params['page'] = $page;

        return $this->performSearch($builder, $params);
    }

    /**
     * 构建 Meilisearch 搜索参数（增强版）
     */
    protected function buildSearchParams(AdvancedScoutBuilder $builder): array
    {
        $params = [
            'limit' => $builder->limit ?: 20,
            'offset' => $builder->offset ?: 0,
            'attributesToRetrieve' => $this->getAttributesToRetrieve($builder),
            'attributesToHighlight' => $this->getHighlightFields($builder),
            'attributesToCrop' => $builder->options['crop'] ?? [],
            'cropLength' => $builder->options['crop_length'] ?? 200,
            'cropMarker' => $builder->options['crop_marker'] ?? '...',
            'highlightPreTag' => $builder->options['highlight_pre_tag'] ?? '<mark>',
            'highlightPostTag' => $builder->options['highlight_post_tag'] ?? '</mark>',
            'showMatchesPosition' => $builder->options['show_matches_position'] ?? false,
            'matchingStrategy' => $builder->options['matching_strategy'] ?? 'last',
        ];

        // 设置查询字段
        if ($builder->query) {
            $params['query'] = $builder->query;
            $params['attributesToSearchOn'] = $this->getSearchFields($builder);
        }

        // 处理筛选条件
        $filters = $this->buildFilters($builder);
        if ($filters) {
            $params['filter'] = $filters;
        }

        // 处理排序
        if ($sorts = $builder->getSorts()) {
            $params['sort'] = $this->buildMeilisearchSorts($sorts);
        }

        // 处理向量搜索（Meilisearch v1.3+）
        if ($vectorSearch = $builder->getVectorSearch()) {
            $params = $this->addMeilisearchVectorSearch($params, $vectorSearch);
        }

        // 处理分面
        if ($facets = $builder->getFacetConfig()) {
            $params['facets'] = array_keys($facets);
        }

        // 搜索配置
        $params = array_merge($params, [
            'showRankingScore' => $builder->options['show_ranking_score'] ?? false,
            'showRankingScoreDetails' => $builder->options['show_ranking_score_details'] ?? false,
        ]);

        return $params;
    }

    /**
     * 格式化筛选值：字符串加引号并转义，数字/布尔原样
     */
    protected function quoteValue($value)
    {
        return is_string($value) ? '"' . addcslashes($value, '"\\') . '"' : $value;
    }

    /**
     * 构建 Meilisearch 筛选条件（增强版）
     */
    protected function buildFilters(AdvancedScoutBuilder $builder): string
    {
        $filters = [];

        // 基本 where 条件
        foreach ($builder->wheres as $field => $value) {
            if (is_array($value)) {
                $values = array_map([$this, 'quoteValue'], $value);
                $filters[] = $field . ' IN [' . implode(', ', $values) . ']';
            } else {
                $filters[] = $field . ' = ' . $this->quoteValue($value);
            }
        }

        // 高级 where 条件
        foreach ($builder->getAdvancedWheres() as $condition) {
            $filters[] = $this->buildMeilisearchFilter($condition);
        }

        // whereIn 条件
        foreach ($builder->whereIns as $field => $values) {
            $values = array_map([$this, 'quoteValue'], $values);
            $filters[] = $field . ' IN [' . implode(', ', $values) . ']';
        }

        // whereNotIn 条件
        foreach ($builder->whereNotIns as $field => $values) {
            $values = array_map([$this, 'quoteValue'], $values);
            $filters[] = $field . ' NOT IN [' . implode(', ', $values) . ']';
        }

        return implode(' AND ', $filters);
    }

    /**
     * 构建 Meilisearch 筛选条件（增强版）
     */
    protected function buildMeilisearchFilter(array $condition): string
    {
        $field = $condition['field'];
        $operator = $condition['operator'];
        $value = $condition['value'];
        $options = $condition['options'] ?? [];

        return match ($operator) {
            'range' => sprintf(
                '%s >= %s AND %s <= %s',
                $field,
                $this->quoteValue($value['range'][0] ?? 'null'),
                $field,
                $this->quoteValue($value['range'][1] ?? 'null')
            ),
            'date_range' => sprintf(
                '%s >= %s AND %s <= %s',
                $field,
                $this->quoteValue($value['range'][0] ?? 'null'),
                $field,
                $this->quoteValue($value['range'][1] ?? 'null')
            ),
            '>' => sprintf('%s > %s', $field, $this->quoteValue($value)),
            '>=' => sprintf('%s >= %s', $field, $this->quoteValue($value)),
            '<' => sprintf('%s < %s', $field, $this->quoteValue($value)),
            '<=' => sprintf('%s <= %s', $field, $this->quoteValue($value)),
            '!=' => sprintf('%s != %s', $field, $this->quoteValue($value)),
            'geo_radius' => sprintf(
                '_geoRadius(%s, %s, %s)',
                $value['lat'],
                $value['lng'],
                $value['radius']
            ),
            'geo_bounding_box' => sprintf(
                '_geoBoundingBox([%s, %s, %s, %s])',
                $value['top_left']['lat'],
                $value['top_left']['lng'],
                $value['bottom_right']['lat'],
                $value['bottom_right']['lng']
            ),
            'exists' => $field . ' EXISTS',
            'missing' => $field . ' NOT EXISTS',
            'contains' => sprintf('%s CONTAINS %s', $field, $this->quoteValue($value)),
            'starts_with' => sprintf('%s STARTS WITH %s', $field, $this->quoteValue($value)),
            'ends_with' => sprintf('%s ENDS WITH %s', $field, $this->quoteValue($value)),
            'match' => sprintf('%s = %s', $field, $this->quoteValue($value)),
            'in' => sprintf(
                '%s IN [%s]',
                $field,
                implode(', ', array_map([$this, 'quoteValue'], (array)$value))
            ),
            'not_in' => sprintf(
                '%s NOT IN [%s]',
                $field,
                implode(', ', array_map([$this, 'quoteValue'], (array)$value))
            ),
            'regex' => sprintf('%s MATCHES %s', $field, $this->quoteValue($value)),
            'null' => $field . ' IS NULL',
            'not_null' => $field . ' IS NOT NULL',
            'empty' => $field . ' IS EMPTY',
            'not_empty' => $field . ' IS NOT EMPTY',
            default => $field . ' = ' . $this->quoteValue($value),
        };
    }

    /**
     * 构建 Meilisearch 排序（增强版）
     */
    protected function buildMeilisearchSorts(array $sorts): array
    {
        $meilisearchSorts = [];

        foreach ($sorts as $sort) {
            $field = $sort['field'] ?? null;
            $type = $sort['type'] ?? null;
            $direction = $sort['direction'] ?? 'asc';
            $options = $sort['options'] ?? [];

            if ($type === 'geo_distance') {
                $direction = $direction === 'desc' ? 'desc' : 'asc';
                $meilisearchSorts[] = sprintf(
                    '_geoPoint(%s, %s):%s',
                    $sort['location']['lat'] ?? 0,
                    $sort['location']['lng'] ?? 0,
                    $direction
                );
            } elseif ($type === 'random') {
                $meilisearchSorts[] = '_random:asc';
            } elseif ($field === '_relevance') {
                $meilisearchSorts[] = '_relevance:desc';
            } elseif ($field === '_distance') {
                $meilisearchSorts[] = '_distance:asc';
            } else {
                $direction = $direction === 'desc' ? ':desc' : ':asc';
                $meilisearchSorts[] = $field . $direction;
            }
        }

        return $meilisearchSorts;
    }

    /**
     * 添加 Meilisearch 向量搜索（增强版）
     */
    protected function addMeilisearchVectorSearch(array $params, array $vectorSearch): array
    {
        $vector = $vectorSearch['vector'] ?? null;
        $field = $vectorSearch['field'] ?? '_vectors';
        $options = $vectorSearch['options'] ?? [];

        if (!$vector) {
            return $params;
        }

        // Meilisearch v1.3+ 支持向量搜索，hybrid 必须是 embedder 对象
        $params['vector'] = $vector;
        $params['hybrid'] = $options['hybrid'] ?? [
            'embedder' => $options['embedder'] ?? 'default',
        ];

        if (isset($options['semantic_ratio'])) {
            $params['hybrid']['semanticRatio'] = $options['semantic_ratio'];
        }

        if (isset($options['similarity_threshold'])) {
            $params['similarityThreshold'] = $options['similarity_threshold'];
        }

        if (isset($options['show_ranking_score'])) {
            $params['showRankingScore'] = $options['show_ranking_score'];
        }

        return $params;
    }

    /**
     * 处理 Meilisearch 结果（增强版）
     */
    protected function processMeilisearchResults(array $result, AdvancedScoutBuilder $builder): array
    {
        $processedResults = [
            'hits' => array_map(function ($hit) use ($builder) {
                $document = $hit;

                // 提取 ID
                $document['_id'] = $document['id'] ?? null;
                unset($document['id']);

                // 添加得分
                $document['_score'] = $hit['_rankingScore'] ?? 0.0;

                // 添加向量距离
                if (isset($hit['_vectorDistance'])) {
                    $document['_vector_distance'] = $hit['_vectorDistance'];
                }

                // 添加高亮
                if (isset($hit['_formatted'])) {
                    $document['_highlight'] = $hit['_formatted'];
                }

                // 添加匹配位置
                if (isset($hit['_matchesPosition'])) {
                    $document['_matches_position'] = $hit['_matchesPosition'];
                }

                return $document;
            }, $result['hits'] ?? []),
            'total' => $result['totalHits'] ?? 0,
            'estimatedTotalHits' => $result['estimatedTotalHits'] ?? $result['totalHits'] ?? 0,
            'facets' => $result['facetDistribution'] ?? [],
            'facetStats' => $result['facetStats'] ?? [],
            'processingTimeMs' => $result['processingTimeMs'] ?? 0,
            'query' => $result['query'] ?? '',
            'limit' => $result['limit'] ?? 0,
            'offset' => $result['offset'] ?? 0,
            'rankingScore' => $result['rankingScore'] ?? null,
            'rankingScoreDetails' => $result['rankingScoreDetails'] ?? null,
        ];

        // 应用结果处理器
        foreach ($builder->getResultProcessors() as $processor) {
            $processedResults = $processor($processedResults);
        }

        return $processedResults;
    }

    /**
     * 获取聚合结果（增强版）
     */
    public function getAggregations(AdvancedScoutBuilder $builder): array
    {
        $index = $this->meilisearch->index(
            $this->getIndexName($builder->model)
        );

        $searchParams = $this->buildSearchParams($builder);

        // 确保返回分面结果
        $searchParams['facets'] = array_keys($builder->getAggregationConfig());

        $result = $index->search($builder->query ?? '', $searchParams);

        return [
            'facets' => $result['facetDistribution'] ?? [],
            'facetStats' => $result['facetStats'] ?? [],
            'total' => $result['totalHits'] ?? 0,
        ];
    }

    /**
     * 获取分面结果
     */
    public function getFacets(AdvancedScoutBuilder $builder): array
    {
        return $this->getAggregations($builder);
    }

    /**
     * 更新向量（增强版）
     */
    public function updateVectors($models, array $vectors): void
    {
        $indexName = $this->getIndexName($models->first());
        $index = $this->meilisearch->index($indexName);

        $documents = [];

        foreach ($models as $i => $model) {
            $document = $model->toSearchableArray();
            $document['id'] = $model->getScoutKey();

            if (isset($vectors[$i])) {
                $document['_vectors'] = $vectors[$i];
                $document['embedding'] = $vectors[$i]; // 兼容字段
            }

            $documents[] = $document;
        }

        try {
            $task = $index->updateDocuments($documents);

            // 等待任务完成
            $this->waitForTaskCompletion($index, $task['taskUid'] ?? null);
        } catch (\Exception $e) {
            Log::error('Failed to update vectors in Meilisearch', [
                'error' => $e->getMessage(),
                'index' => $indexName,
                'count' => count($documents),
            ]);
            throw $e;
        }
    }

    /**
     * 等待任务完成
     */
    protected function waitForTaskCompletion($index, ?int $taskUid = null, int $maxAttempts = 30, int $sleep = 1000): void
    {
        $attempts = 0;

        if ($taskUid === null) {
            return;
        }

        while ($attempts < $maxAttempts) {
            $task = $index->getTask($taskUid);

            if (in_array($task['status'], ['succeeded', 'failed'])) {
                if ($task['status'] === 'failed') {
                    Log::error('Meilisearch task failed', ['task' => $task]);
                }
                return;
            }

            usleep($sleep * 1000);
            $attempts++;
        }

        Log::warning('Meilisearch task timeout', ['taskUid' => $taskUid]);
    }

    /**
     * 创建向量索引（增强版）
     */
    public function createVectorIndex(string $index, int $dimensions = 1536, array $settings = []): bool
    {
        try {
            // 创建索引
            $this->meilisearch->createIndex($index, [
                'primaryKey' => $settings['primaryKey'] ?? 'id',
            ]);

            $indexObj = $this->meilisearch->index($index);

            // 更新索引设置以支持向量
            $indexSettings = [
                'searchableAttributes' => $settings['searchableAttributes'] ?? ['*'],
                'filterableAttributes' => $settings['filterableAttributes'] ?? ['*'],
                'sortableAttributes' => $settings['sortableAttributes'] ?? ['*'],
                'displayedAttributes' => $settings['displayedAttributes'] ?? ['*'],
                'rankingRules' => $settings['rankingRules'] ?? [
                    'words',
                    'typo',
                    'proximity',
                    'attribute',
                    'sort',
                    'exactness',
                ],
                'stopWords' => $settings['stopWords'] ?? [],
                'synonyms' => $settings['synonyms'] ?? [],
                'distinctAttribute' => $settings['distinctAttribute'] ?? null,
                'typoTolerance' => $settings['typoTolerance'] ?? [
                    'enabled' => true,
                    'minWordSizeForTypos' => [
                        'oneTypo' => 5,
                        'twoTypos' => 9,
                    ],
                    'disableOnWords' => [],
                    'disableOnAttributes' => [],
                ],
            ];

            // 如果支持向量，添加嵌入器配置
            if (version_compare($this->meilisearch->version()['pkgVersion'] ?? '0.0.0', '1.3.0', '>=')) {
                $indexSettings['embedders'] = [
                    'default' => [
                        'source' => 'userProvided',
                        'dimensions' => $dimensions,
                        'documentTemplate' => $settings['documentTemplate'] ?? null,
                    ],
                ];
            }

            $indexObj->updateSettings($indexSettings);

            return true;
        } catch (\Exception $e) {
            Log::error('Failed to create Meilisearch vector index', [
                'error' => $e->getMessage(),
                'index' => $index,
                'dimensions' => $dimensions,
            ]);
            return false;
        }
    }

    /**
     * 获取索引名称
     */
    protected function getIndexName($model): string
    {
        $indexName = $model->searchableAs();

        if ($this->prefix) {
            $indexName = $this->prefix . $indexName;
        }

        return $indexName;
    }

    /**
     * 获取引擎信息
     */
    public function getEngineInfo(): array
    {
        try {
            $version = $this->meilisearch->version();
            $stats = $this->meilisearch->stats();

            return [
                'type' => 'meilisearch',
                'version' => $version['pkgVersion'] ?? 'unknown',
                'databaseSize' => $stats['databaseSize'] ?? 0,
                'lastUpdate' => $stats['lastUpdate'] ?? null,
                'indexes' => $stats['indexes'] ?? [],
                'isHealthy' => $this->meilisearch->isHealthy(),
                'supportsVectors' => version_compare($version['pkgVersion'] ?? '0.0.0', '1.3.0', '>='),
            ];
        } catch (\Exception $e) {
            return [
                'type' => 'meilisearch',
                'error' => $e->getMessage(),
                'isHealthy' => false,
            ];
        }
    }

    /**
     * 执行原始搜索
     */
    public function rawSearch(array $params): array
    {
        $index = $this->meilisearch->index($params['index'] ?? '');

        unset($params['index']);

        return $index->search($params['query'] ?? '', $params);
    }

    /**
     * 获取任务状态
     */
    public function getTaskStatus(int $taskUid): array
    {
        $index = $this->meilisearch->index(''); // 任意索引

        return $index->getTask($taskUid);
    }

    /**
     * 批量删除文档
     */
    public function deleteDocuments(array $ids, string $indexName): void
    {
        $index = $this->meilisearch->index($indexName);
        $index->deleteDocuments($ids);
    }

    /**
     * 清空索引
     */
    public function truncateIndex(string $indexName): void
    {
        $index = $this->meilisearch->index($indexName);
        $index->deleteAllDocuments();
    }

    /**
     * 获取文档
     */
    public function getDocument(string $indexName, string $id): array
    {
        $index = $this->meilisearch->index($indexName);

        try {
            return $index->getDocument($id);
        } catch (\Exception $e) {
            Log::error('Failed to get Meilisearch document', [
                'index' => $indexName,
                'id' => $id,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * 批量获取文档
     */
    public function getDocuments(string $indexName, array $ids): array
    {
        $index = $this->meilisearch->index($indexName);

        try {
            return $index->getDocuments([
                'filter' => 'id IN [' . implode(', ', array_map([$this, 'quoteValue'], $ids)) . ']',
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to get Meilisearch documents', [
                'index' => $indexName,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }
}
