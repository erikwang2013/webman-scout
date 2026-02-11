# webman-scout

erikwang2013/webman-scout是基于laravel/scout并参考shopwwi/webman-scout做的兼容webman全文搜索。
由于shopwwi/webman-scout更新停止不前，业务上设计时间查询，数据聚合及使用开源的opensearch。只能自己开发一个，用于业务上对opensearch的支持，复杂查询，聚合查询等。
使用方法和shopwwi/webman-scout一样，只不过增加一些方法。如下：
whereRange(string $field, array $range, bool $inclusive = true);
whereGeoDistance(string $field, float $lat, float $lng, float $radius);
updateIndexMappings(string $index, array $mappings);
fulltextSearch(string $query, array $fields = [], array $options = []);
orderByVectorSimilarity(array $vector, ?string $vectorField = null);
addResultProcessor(callable $processor);
aggregate(string $name, string $type, string $field, array $options = []);
facet(string $field, array $options = []);
getAggregations();
getFacets();
getVectorSearch();
getAdvancedWheres();
getSorts();
getAggregationConfig();
getFacetConfig();
 clearAdvancedConditions();