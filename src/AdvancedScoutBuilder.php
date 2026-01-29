<?php

namespace Erikwang2013\WebmanScout;

use Erikwang2013\WebmanScout\Builder as ScoutBuilder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\LazyCollection;

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
     * 结果处理回调
     */
    protected array $resultProcessors = [];

    /**
     * 聚合查询配置
     */
    protected array $aggregations = [];

    /**
     * 分面搜索配置
     */
    protected array $facets = [];

    /**
     * 启用向量相似度搜索
     *
     * @param array|string $vector 向量数据或向量字段名
     * @param string|null $vectorField 向量字段名（如果第一个参数是数组）
     * @param array $options 向量搜索选项
     * @return $this
     */
    public function vectorSearch($vector, ?string $vectorField = null, array $options = []): self
    {
        if (is_array($vector)) {
            $this->vectorSearch = [
                'vector' => $vector,
                'field' => $vectorField,
                'options' => array_merge([
                    'metric' => 'cosine', // cosine, euclidean, dotproduct
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
     *
     * @param string $field
     * @param string $operator
     * @param mixed $value
     * @param string $boolean 'and'|'or'
     * @param bool $nested 是否嵌套查询
     * @return $this
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
     *
     * @param string $field
     * @param array $range [min, max]
     * @param bool $inclusive 是否包含边界
     * @return $this
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
     *
     * @param string $field
     * @param float $lat 纬度
     * @param float $lng 经度
     * @param float $radius 半径（公里）
     * @return $this
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
     *
     * @param string $query
     * @param array $fields 搜索字段
     * @param array $options 搜索选项
     * @return $this
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
     *
     * @param string $field 排序字段
     * @param string $direction asc|desc
     * @param array $options 排序选项
     * @return $this
     */
    public function orderBy(string $field, string $direction = 'asc', array $options = []): self
    {
        $this->sorts[] = [
            'field' => $field,
            'direction' => $direction,
            'options' => $options,
        ];

        return $this;
    }

    /**
     * 按向量相似度排序
     *
     * @param array $vector 参考向量
     * @param string|null $vectorField 向量字段
     * @return $this
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
     * 添加结果处理器
     *
     * @param callable $processor
     * @return $this
     */
    public function addResultProcessor(callable $processor): self
    {
        $this->resultProcessors[] = $processor;

        return $this;
    }

    /**
     * 添加聚合查询
     *
     * @param string $name 聚合名称
     * @param string $type 聚合类型：terms, range, histogram, etc.
     * @param string $field 聚合字段
     * @param array $options 聚合选项
     * @return $this
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
     *
     * @param string $field 分面字段
     * @param array $options 分面选项
     * @return $this
     */
    public function facet(string $field, array $options = []): self
    {
        $this->facets[$field] = $options;

        return $this;
    }

    /**
     * 执行搜索并获取结果
     *
     * @return Collection
     */
    public function get(): Collection
    {
        $results = parent::get();

        // 应用结果处理器
        foreach ($this->resultProcessors as $processor) {
            $results = $processor($results);
        }

        return $results;
    }

    /**
     * 执行搜索并获取原始引擎结果
     *
     * @return array
     */
    public function raw(): array
    {
        $engine = $this->engine();

        // 检查引擎是否支持高级功能
        if (method_exists($engine, 'advancedSearch')) {
            return $engine->advancedSearch($this);
        }

        // 回退到原始搜索
        return parent::raw();
    }

    /**
     * 获取聚合结果
     *
     * @return array
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
     *
     * @return array
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
     * 获取向量搜索参数
     */
    public function getVectorSearch(): array
    {
        return $this->vectorSearch;
    }

    /**
     * 获取高级查询条件
     */
    public function getAdvancedWheres(): array
    {
        return $this->advancedWheres;
    }

    /**
     * 获取排序配置
     */
    public function getSorts(): array
    {
        return $this->sorts;
    }

    /**
     * 获取聚合配置
     */
    public function getAggregationConfig(): array
    {
        return $this->aggregations;
    }

    /**
     * 获取分面配置
     */
    public function getFacetConfig(): array
    {
        return $this->facets;
    }

    /**
     * 清空高级查询条件
     *
     * @return $this
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
}