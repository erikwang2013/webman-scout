<?php

namespace Erikwang2013\WebmanScout;

use Erikwang2013\WebmanScout\Searchable;

trait AdvancedSearchable
{
    use Searchable;

    /**
     * 重写新建搜索构建器
     */
    protected function newSearchableBuilder($query, $model)
    {
        return new AdvancedScoutBuilder($model, $query);
    }

    /**
     * 高级搜索方法
     */
    public static function advancedSearch(string $query = ''): AdvancedScoutBuilder
    {
        return (new static())
            ->search($query)
            ->asAdvancedBuilder();
    }

    /**
     * 转换为基础 Scout Builder
     */
    public function asAdvancedBuilder(): AdvancedScoutBuilder
    {
        return new AdvancedScoutBuilder($this, $this->query);
    }

    /**
     * 获取向量字段
     */
    public function getVectorField(): string
    {
        return property_exists($this, 'vectorField') ? $this->vectorField : 'embedding';
    }

    /**
     * 获取向量维度
     */
    public function getVectorDimensions(): int
    {
        return property_exists($this, 'vectorDimensions') ? $this->vectorDimensions : 1536;
    }

    /**
     * 获取搜索字段
     */
    public function searchableFields(): array
    {
        if (property_exists($this, 'searchableFields')) {
            return $this->searchableFields;
        }

        return ['*'];
    }

    /**
     * 获取搜索索引配置
     */
    public function searchableIndexConfig(): array
    {
        if (property_exists($this, 'searchableIndexConfig')) {
            return $this->searchableIndexConfig;
        }

        return [];
    }
}