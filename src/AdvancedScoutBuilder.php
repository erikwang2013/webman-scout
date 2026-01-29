<?php

namespace Erikwang2013\WebmanScout;

use Erikwang2013\WebmanScout\Builder as ScoutBuilder;
use Illuminate\Database\Eloquent\Collection;


class AdvancedScoutBuilder extends ScoutBuilder
{
    /**
     * 向量搜索参数
     */
    protected array $vectorSearch = [];

    /**
     * 高级查询条件
     */
    protected array $advancedWheres = [];

    /**
     * 排序配置
     */
    protected array $sorts = [];

    /**
     * 结果处理器
     */
    protected array $resultProcessors = [];

    /**
     * 聚合配置
     */
    protected array $aggregations = [];

    /**
     * 分面配置
     */
    protected array $facets = [];

    /**
     * 启用向量相似度搜索
     */
    public function vectorSearch($vector, ?string $vectorField = null, array $options = []): self
    {
        if (is_array($vector)) {
            $this->vectorSearch = [
                'vector' => $vector,
                'field' => $vectorField,
                'options' => array_merge([
                    'metric' => 'cosine',
                    'top_k' => 10,
                    'threshold' => 0.7,
                ], $options),
            ];
        } else {
            $this->vectorSearch = [
                'field' => $vector,
                'options' => $options,
            ];
        }

        return $this;
    }

    /**
     * 添加嵌套/复杂查询条件
     */
    public function whereAdvanced(
        string $field,
        string $operator,
        $value,
        string $boolean = 'and',
        bool $nested = false
    ): self {
        $this->advancedWheres[] = [
            'field' => $field,
            'operator' => $operator,
            'value' => $value,
            'boolean' => $boolean,
            'nested' => $nested,
        ];

        return $this;
    }

    /**
     * 范围查询
     */
    public function whereRange(string $field, array $range, bool $inclusive = true): self
    {
        return $this->whereAdvanced($field, 'range', [
            'range' => $range,
            'inclusive' => $inclusive,
        ]);
    }

    /**
     * 地理位置查询
     */
    public function whereGeoDistance(string $field, float $lat, float $lng, float $radius): self
    {
        return $this->whereAdvanced($field, 'geo_distance', [
            'lat' => $lat,
            'lng' => $lng,
            'radius' => $radius,
        ]);
    }

    /**
     * 全文搜索增强
     */
    public function fulltextSearch(string $query, array $fields = [], array $options = []): self
    {
        $this->advancedWheres[] = [
            'type' => 'fulltext',
            'query' => $query,
            'fields' => $fields ?: $this->model->searchableFields(),
            'options' => array_merge([
                'operator' => 'and',
                'fuzziness' => 'auto',
                'boost' => 1.0,
            ], $options),
        ];

        return $this;
    }

    /**
     * 添加排序
     */
   /*  public function orderBy(string $field, string $direction = 'asc', array $options = []): self
    {
        $this->sorts[] = [
            'field' => $field,
            'direction' => $direction,
            'options' => $options,
        ];

        return $this;
    } */

    /**
     * 按向量相似度排序
     */
    public function orderByVectorSimilarity(array $vector, ?string $vectorField = null): self
    {
        $this->sorts[] = [
            'type' => 'vector_similarity',
            'vector' => $vector,
            'field' => $vectorField,
        ];

        return $this;
    }

    /**
     * 按地理位置距离排序
     */
    public function orderByGeoDistance(string $field, float $lat, float $lng, string $direction = 'asc'): self
    {
        $this->sorts[] = [
            'type' => 'geo_distance',
            'field' => $field,
            'location' => ['lat' => $lat, 'lng' => $lng],
            'direction' => $direction,
        ];

        return $this;
    }

    /**
     * 添加结果处理器
     */
    public function addResultProcessor(callable $processor): self
    {
        $this->resultProcessors[] = $processor;

        return $this;
    }

    /**
     * 添加聚合查询
     */
    public function aggregate(string $name, string $type, string $field, array $options = []): self
    {
        $this->aggregations[$name] = [
            'type' => $type,
            'field' => $field,
            'options' => $options,
        ];

        return $this;
    }

    /**
     * 添加分面搜索
     */
    public function facet(string $field, array $options = []): self
    {
        $this->facets[$field] = $options;

        return $this;
    }

    /**
     * 执行搜索并获取结果
     */
    public function get(): Collection
    {
        $engine = $this->engine();

        // 检查引擎是否支持高级搜索
        if (method_exists($engine, 'advancedSearch')) {
            $results = $engine->advancedSearch($this);
            
            // 应用结果处理器
            foreach ($this->resultProcessors as $processor) {
                $results = $processor($results);
            }
            
            return $this->mapResults($results);
        }

        // 回退到基础搜索
        return parent::get();
    }

    /**
     * 执行搜索并获取原始结果
     */
    public function raw(): array
    {
        $engine = $this->engine();

        if (method_exists($engine, 'advancedSearch')) {
            return $engine->advancedSearch($this);
        }

        return parent::raw();
    }

    /**
     * 获取聚合结果
     */
    public function getAggregations(): array
    {
        $engine = $this->engine();

        if (method_exists($engine, 'getAggregations')) {
            return $engine->getAggregations($this);
        }

        return [];
    }

    /**
     * 获取分面结果
     */
    public function getFacets(): array
    {
        $engine = $this->engine();

        if (method_exists($engine, 'getFacets')) {
            return $engine->getFacets($this);
        }

        return [];
    }

    /**
     * 映射搜索结果
     */
    protected function mapResults(array $results): Collection
    {
        if (empty($results['hits'])) {
            return $this->model->newCollection();
        }

        $objectIds = collect($results['hits'])->pluck('_id')->all();
        
        if (empty($objectIds)) {
            return $this->model->newCollection();
        }

        $objectIdPositions = array_flip($objectIds);

        $models = $this->model->getScoutModelsByIds($this, $objectIds)
            ->filter(fn($model) => in_array($model->getScoutKey(), $objectIds))
            ->sortBy(fn($model) => $objectIdPositions[$model->getScoutKey()])
            ->values();

        // 添加元数据
        foreach ($models as $index => $model) {
            if (isset($results['hits'][$index])) {
                $hit = $results['hits'][$index];
                $model->_score = $hit['_score'] ?? null;
                $model->_highlight = $hit['_highlight'] ?? null;
                $model->_vector_score = $hit['_vector_score'] ?? null;
            }
        }

        return $models;
    }

    /**
     * Getter 方法
     */

    public function getVectorSearch(): array
    {
        return $this->vectorSearch;
    }

    public function getAdvancedWheres(): array
    {
        return $this->advancedWheres;
    }

    public function getSorts(): array
    {
        return $this->sorts;
    }

    public function getResultProcessors(): array
    {
        return $this->resultProcessors;
    }

    public function getAggregationConfig(): array
    {
        return $this->aggregations;
    }

    public function getFacetConfig(): array
    {
        return $this->facets;
    }

    public function getOptions(): array
    {
        return $this->options;
    }

    /**
     * Setter 方法
     */

    public function setOption(string $key, $value): self
    {
        $this->options[$key] = $value;
        return $this;
    }

    public function setOptions(array $options): self
    {
        $this->options = array_merge($this->options, $options);
        return $this;
    }

    /**
     * 清空高级查询条件
     */
    public function clearAdvancedConditions(): self
    {
        $this->vectorSearch = [];
        $this->advancedWheres = [];
        $this->sorts = [];
        $this->aggregations = [];
        $this->facets = [];
        $this->resultProcessors = [];

        return $this;
    }

    /**
     * 条件构造辅助方法
     */

    /* public function when($condition, callable $callback, ?callable $default = null): self
    {
        if ($condition) {
            return $callback($this, $condition) ?? $this;
        } elseif ($default) {
            return $default($this, $condition) ?? $this;
        }

        return $this;
    }

    public function unless($condition, callable $callback, ?callable $default = null): self
    {
        return $this->when(!$condition, $callback, $default);
    } */

    /**
     * 调试方法
     */
    public function debug(): array
    {
        return [
            'model' => get_class($this->model),
            'query' => $this->query,
            'index' => $this->index,
            'wheres' => $this->wheres,
            'whereIns' => $this->whereIns,
            'whereNotIns' => $this->whereNotIns,
            'advancedWheres' => $this->advancedWheres,
            'vectorSearch' => $this->vectorSearch,
            'sorts' => $this->sorts,
            'aggregations' => $this->aggregations,
            'facets' => $this->facets,
            'options' => $this->options,
            'limit' => $this->limit,
            'offset' => $this->offset,
        ];
    }
}