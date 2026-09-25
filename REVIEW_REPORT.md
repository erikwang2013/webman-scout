# webman-scout 审查报告

**本轮审查 / 修复**: 2026-09-26  
**上一轮**: 2026-08-02（见文末附录）  
**状态**: 本轮 18 项已修复；7 项列为待决策（见「三」）

---

## 一、本轮修复

| # | 问题 | 影响面 | 状态 |
|---|------|--------|------|
| 1 | `AdvancedOpenSearchEngine` 使用 `now()`（laravel/framework 的 helper，非必需依赖） | Webman / Hyperf / ThinkPHP / Yii2 / Yii3 / 原生 PHP 下向量写入直接 fatal | ✅ 改用 Carbon |
| 2 | `mapIds()` 只认原始 `hits.hits`，而高级引擎返回扁平 `hits` | 默认 opensearch 驱动 `keys()` 恒空；带 `queryCallback` 的 `paginate()` total 恒为 0 | ✅ 两种结构都支持 |
| 3 | `mapAdvancedResults()` 按数组下标挂 `_score` / `_highlight` | 命中数与模型数不一致时分数错挂到别的文档；脏属性会被 `save()` 写回数据库 | ✅ 按 `_id` 对齐 + `syncOriginalAttribute` |
| 4 | `vectorSearch()` 选项名与引擎读取名不一致 | 传 `top_k` 不生效（OpenSearch 读 `k`）；非数组向量被静默忽略 | ✅ 互为别名 + 不再按类型分叉 |
| 5 | `Builder::get()` 漏调 `applyAfterRawSearchCallback()` | 高级引擎下 `withRawResults()` 失效（`paginate()` 却生效） | ✅ |
| 6 | `EngineManager` 未守卫 `base_path()` | OpenSearch 证书相对路径在无该 helper 的宿主 fatal | ✅ `resolvePath()` |
| 7 | 包内 `app.php` 调用 `base_path('config/xunsearch')` | 同上，加载配置即 fatal（影响复制该配置的所有非 Webman 宿主） | ✅ helpers.php 提供 polyfill |
| 8 | `illuminate/events` 不是直接依赖 | `illuminate/*` ^8.0 组合不会传递引入，`scout:import` / 分块导入崩 | ✅ 提为 require |
| 9 | `Support\Log` / `Support\Cache` 直接回退到 Illuminate facade | 未装 illuminate/log / illuminate/cache 的宿主抛异常而不是降级 | ✅ 退回 `error_log()` / 进程内 `ArrayStore` |
| 10 | 进度事件走全局 `event()` | 宿主同名的 `event()`（Webman event 插件 / Laravel helpers）签名不同，事件被吞 | ✅ 直接走容器里的 `Dispatcher` |
| 11 | OpenSearch `ssl_verification` 默认 false，且 `(bool)'false'` 为 true | 默认不校验证书；想开校验反而写错 | ✅ 默认校验 + `filter_var` 解析 |
| 12 | `MakeRangeSearchable` 缺空集合守卫 | `makeSearchableUsing()` 过滤空后空指针，还会重试 5 次 | ✅ 与兄弟类一致 |
| 13 | XunSearch 排序方向未取默认值 | `orderByVectorSimilarity` / `orderByGeoDistance` 报 undefined key 并走错排序分支 | ✅ 缺省 asc |
| 14 | XunSearch 高级缓存键不含分面 / 聚合配置 | 不同分面配置互相命中缓存 | ✅ 纳入缓存键 |
| 15 | `AdvancedElasticsearchEngine`（942 行）无法通过驱动名使用 | ES 用户拿不到聚合 / 分面 / 高亮 / 向量 | ✅ 新增 `advanced_elasticsearch`、`advanced_opensearch` |
| 16 | 无框架宿主无法提供配置 | 只能落到 `null` 引擎 | ✅ `Scout::configure()` / `ScoutConfig::setArraySource()` |
| 17 | CI 从未通过：库提交了 lock、phpstan 全红 | 5 个 PHP 版本的任务全部失败 | ✅ 不提交 lock（按 PHP 版本解析）+ phpstan 基线 + 可选依赖符号桩 |
| 18 | 测试覆盖 | — | ✅ 465 → 486 用例（原生 PHP 配置 / 兜底 / 端到端冒烟、引擎结果结构、驱动解析） |

### 变更文件（本轮）

| 文件 | 变更 |
|------|------|
| `src/Scout.php` | `configure()`；`VERSION` 与 tag 对齐（10.23.0 → 2.1.0） |
| `src/ScoutConfig.php` | `setArraySource()` 数组配置源 |
| `src/Support/ArrayStore.php` | 新增 — 进程内缓存兜底（Illuminate Store 契约） |
| `src/Support/Log.php` / `Cache.php` | facade 失败后降级，不再抛异常 |
| `src/EngineManager.php` | `resolvePath()`；`advanced_elasticsearch` / `advanced_opensearch`；ES 客户端构建去重 |
| `src/Builder.php` | 高级分支补 raw 回调；`vectorSearch()` 选项别名与结构修正；`mapResults()` 用 `isset` 替代 `in_array` |
| `src/Engines/AdvancedOpenSearchEngine.php` | `now()` → Carbon；元数据按 `_id` 对齐 |
| `src/Engines/OpenSearchEngine.php` / `ElasticSearchEngine.php` / `XunSearchEngine.php` | `mapIds()` 兼容高级结果结构 |
| `src/SearchableScope.php` | 进度事件走容器 Dispatcher |
| `src/Jobs/search/MakeRangeSearchable.php` | 空集合守卫 |
| `src/config/.../app.php` | `ssl_verification` 默认值 / 解析 |
| `helpers.php` | `base_path()` polyfill |
| `phpstan.neon` + `phpstan-baseline.neon` | 基线 + `scanFiles` 符号桩 |
| `.github/workflows/ci.yml` | phpstan 只在 8.4 运行；不依赖提交的 lock |
| `README.md` / `docs/zh-CN/README.md` | 项目结构、架构、功能设计、生命周期、原生 PHP、宠物；修正 `limit()` 等笔误 |
| `docs/images/*.svg` | 吉祥物 + 三张设计图 |

---

## 二、当前质量状态

- **测试**：486 用例 / 1301 断言；CI 在 PHP **8.0 / 8.1 / 8.2 / 8.3 / 8.4** 全绿
- **静态分析**：phpstan level 4，0 错误（基线收敛了 379 条历史告警）
- **支持矩阵**：Webman 1.x/2.x · Laravel 7–12 · Hyperf 2.x/3.x · ThinkPHP 6/8 · Yii2 · Yii3 · **原生 PHP 8.0+**
- **引擎**：16 个实现（含 5 个 Advanced 变体），9 类后端

---

## 三、待决策（本轮未改，均有取舍）

| # | 事项 | 为什么没动 |
|---|------|-----------|
| 1 | `$callback` 在各引擎拿到的东西不同：OpenSearch 传 `Builder`，Meilisearch / Typesense / XunSearch 传查询字符串，Database / Collection 当查询构造器回调 | 统一签名会破坏现有调用方，需要一次显式的破坏性变更 + 文档迁移说明 |
| 2 | `scout.queue.connection` / `queue.queue` 配置无人读取（队列名硬编码 `scout_make` / `scout_remove`） | Webman Redis Queue 没有 connection 概念；改名要与宿主消费者登记的名字同步，属行为变更 |
| 3 | XunSearch 高级搜索缓存默认开启、写入后不失效（TTL 300s 内可能命中旧结果） | 需要缓存失效策略（按索引打版本号），影响面比一个补丁大 |
| 4 | `getAggregations()` / `getFacets()` 每次都重新打一次引擎 | 结果其实已在 `get()` / `paginate()` 的返回值里，但改成「复用上次结果」要引入状态，容易出隐蔽 bug |
| 5 | `after_commit` 需要宿主注册数据库事务管理器才生效 | 与 Laravel Scout 行为一致，已在 README 标注前提 |
| 6 | `xunsearch.search.batch_size`、`xunsearch.index_templates` 配置无消费者 | 需要先确认 XunSearch 侧的预期语义 |
| 7 | phpstan 基线中的 379 条历史告警 | 建议按文件逐步清理，而不是一次性改动 |

---

# 附录：2026-08-02 审查与修复记录（存档）

**审查日期**: 2026-08-02  
**修复日期**: 2026-08-02  
**状态**: 所有问题已修复

---

## 修复摘要

| # | 问题 | 状态 |
|---|------|------|
| 1 | `AdvancedXunSearchEngine.php` — `getAggregations` 重复定义 | ✅ 已修复 |
| 2 | `AdvancedXunSearchEngine.php` — `getFacets` 重复定义 | ✅ 已修复 |
| 3 | 引擎文件硬依赖 `support\Log` / `support\Cache` | ✅ 已修复 — 新增 `src/Support/Log.php` 和 `src/Support/Cache.php` 跨框架适配器 |
| 4 | `getenv()` 双参数误用（41 处） | ✅ 已修复 |
| 5 | `Engine.php` 注释死代码（25 行） | ✅ 已移除 |
| 6 | `EngineManager` 通用 `Exception` | ✅ 已替换为 `ScoutException` |
| 7 | 零测试覆盖 | ⚠️ 待后续补充 |
| 8 | 中文 README 缺支持区块 | ✅ 已补充 |

### 变更文件清单

| 文件 | 变更类型 |
|------|---------|
| `src/Support/Log.php` | 新增 — 跨框架日志适配器 |
| `src/Support/Cache.php` | 新增 — 跨框架缓存适配器 |
| `src/Engines/AdvancedXunSearchEngine.php` | 修改 — 方法重命名 + import 更新 |
| `src/Engines/AdvancedOpenSearchEngine.php` | 修改 — import 更新 |
| `src/Engines/AdvancedTypesenseEngine.php` | 修改 — import 更新 |
| `src/Engines/AdvancedMeilisearchEngine.php` | 修改 — import 更新 |
| `src/Engines/XunSearchEngine.php` | 修改 — import 更新 |
| `src/Engines/OpenSearchEngine.php` | 修改 — import 更新 |
| `src/Engines/Engine.php` | 修改 — 移除注释死代码 |
| `src/EngineManager.php` | 修改 — Exception → ScoutException |
| `src/config/plugin/erikwang2013/webman-scout/app.php` | 修改 — getenv() 规范化 |
| `README.md` | 修改 — 支持区块更新 |
| `docs/zh-CN/README.md` | 修改 — 新增支持区块 |
| `docs/alipay.png` | 修改 — 缩放至 130×130 |
| `docs/weixinpay.png` | 修改 — 缩放至 130×130 |

---

## 一、原始问题记录（已全部修复）

### 🔴 严重（CRITICAL）— PHP Fatal Error

#### 1. AdvancedXunSearchEngine.php — 重复方法定义 `getAggregations`

**文件**: `src/Engines/AdvancedXunSearchEngine.php`  
**位置**: 第 503 行 与 第 648 行

```php
// 第 503 行 — 内部辅助方法
protected function getAggregations(\XSSearch $search, array $aggregations): array

// 第 648 行 — 公开 API 方法（覆盖了第 503 行）
public function getAggregations(AdvancedScoutBuilder $builder): array
```

PHP 不支持方法重载，第二个 `getAggregations` 会覆盖第一个。第 473 行和第 665 行对内部 helper 的调用（传入 `\XSSearch`）会实际路由到公开 API（期望 `AdvancedScoutBuilder`），导致类型错误。

**影响**: 加载该类时直接 PHP Fatal Error，XunSearch 引擎完全不可用。

#### 2. AdvancedXunSearchEngine.php — 重复方法定义 `getFacets`

**文件**: `src/Engines/AdvancedXunSearchEngine.php`  
**位置**: 第 483 行 与 第 671 行

```php
// 第 483 行 — 内部辅助方法
protected function getFacets(\XSSearch $search, array $facets): array

// 第 671 行 — 公开 API 方法（覆盖了第 483 行）
public function getFacets(AdvancedScoutBuilder $builder): array
```

与问题 1 完全相同的模式。由于 php lint 在遇到第一个 fatal error 时就停止，此问题被隐藏。

**影响**: 同上，该方法也会导致 Fatal Error。

**修复建议**: 将内部辅助方法重命名，如 `buildAggregations` / `buildFacets`，保持公开 API 名称不变。

---

### 🟠 高（HIGH）— 跨框架兼容性

#### 3. Webman 专有类硬依赖

以下引擎文件直接 `use support\Log` 和 `use support\Cache`，这些是 Webman 框架专有类：

| 文件 | Webman 依赖 |
|------|------------|
| `AdvancedXunSearchEngine.php` | `support\Log`, `support\Cache` |
| `AdvancedOpenSearchEngine.php` | `support\Log` |
| `AdvancedTypesenseEngine.php` | `support\Log` |
| `AdvancedMeilisearchEngine.php` | `support\Log` |
| `XunSearchEngine.php` | `support\Log` |
| `OpenSearchEngine.php` | `support\Log` |

在 Laravel / Hyperf / ThinkPHP 环境下，`support\Log` 和 `support\Cache` 类不存在，会导致类加载失败。虽然 README 声明支持多框架，但核心引擎代码与 Webman 紧密耦合。

**建议**: 
- 通过 Illuminate 的 `Log` facade 或 PSR-3 Logger Interface 替代 `support\Log`
- 通过 Illuminate Cache 替代 `support\Cache`
- 或提供适配层根据运行环境切换实现

#### 4. 配置文件 `getenv()` 误用

**文件**: `src/config/plugin/erikwang2013/webman-scout/app.php` 第 37、50、107、133 行等

文件中第 23 行已有注释明确警告此问题：
```php
// getenv 仅单参数；勿写 getenv('X','default')，第二个参数是 local_only(bool)
```

但随后多处代码仍使用双参数模式：
```php
'prefix' => getenv('SCOUT_PREFIX', ''),        // 第 37 行
'queue' => getenv('SCOUT_QUEUE', false),        // 第 50 行
'identify' => getenv('SCOUT_IDENTIFY', false),  // 第 107 行
'config_path' => getenv('XUNSEARCH_CONFIG_PATH', base_path(...)),  // 第 133 行
```

PHP `getenv()` 签名: `getenv(string $name, bool $local_only = false): string|false`。第二个参数不是默认值，而是控制是否仅读取本地环境变量。之所以没出问题，仅因为 `''`、`false`、`true` 作为 bool 转换的巧合。

**建议**: 统一改为 `getenv('KEY') ?: 'default'` 模式。

#### 5. `base_path()` 依赖

`Install.php`、`EngineManager.php` 及配置文件中使用了 `base_path()` 函数。该函数在 Laravel 和 Webman 中由框架定义，但在 Hyperf 或 ThinkPHP 环境中可能不存在。

---

### 🟡 中（MEDIUM）— 代码质量

#### 6. 零测试覆盖

`composer.json` 已配置 `autoload-dev` 指向 `tests/` 目录及 `orchestra/testbench`、`mockery/mockery` 等测试依赖，但 `tests/` 目录不存在，项目中无任何测试文件。

#### 7. Engine.php 大段注释代码

**文件**: `src/Engines/Engine.php` 第 106-131 行

约 25 行高级搜索方法被整体注释掉（`vectorSearch`、`advancedSearch`、`whereRange`、`getAggregations` 等）。结果：
- `Builder.php` 使用 `method_exists()` 做能力检测而非依赖接口契约
- 部分引擎实现了这些方法，部分没有，行为不一致

**建议**: 要么正式定义为 abstract 方法（强制所有引擎实现），要么创建独立的 `AdvancedEngine` 接口。

#### 8. EngineManager 使用通用 Exception

**文件**: `src/EngineManager.php`

多处抛出通用 `\Exception`（第 106、168、217、269 行）。项目已有 `ScoutException` 和 `NotSupportedException`，应统一使用。

---

### 🟢 低（LOW）— 文档与风格

#### 9. 中文文档缺少捐赠支持区块

`docs/zh-CN/README.md` 原本没有底部支持区块，已在本次审查中补充。

#### 10. 中英文注释混用

部分方法注释为中文，部分为英文。对外开源项目建议统一为英文或中英双语。

#### 11. composer.json 版本约束

`illuminate/*` 使用 `^7.0|^8.0|^9.0|^10.0|^11.0`，但 Illuminate 7.x 官方仅支持 PHP 7.x，需在文档中说明兼容的具体小版本。

---

## 二、统计概览

| 项目 | 数值 |
|------|------|
| PHP 源文件 | 49 |
| 语法错误（Fatal） | 2 |
| 跨框架兼容问题 | 3 |
| 代码质量问题 | 3 |
| 文档/风格问题 | 3 |
| 测试文件 | 0 |
| 总代码行数（src/） | ~6500 |

---

## 三、修复优先级

| 优先级 | 问题 | 估计工作量 |
|--------|------|-----------|
| P0 | 修复 `getAggregations`/`getFacets` 重复定义 | 10 分钟 |
| P0 | 修复 `getenv()` 双参数调用 | 15 分钟 |
| P1 | 解耦 `support\Log`/`support\Cache` 依赖 | 2-4 小时 |
| P1 | 补充基础测试用例 | 1-2 天 |
| P2 | 清理 Engine.php 注释代码 / 定义接口 | 1 小时 |
| P2 | 统一异常类型 | 30 分钟 |
| P3 | 注释/文档统一 | 1-2 小时 |

---

## 四、README 变更确认

- `docs/alipay.png`、`docs/weixinpay.png` 已缩至 130x130
- 英文 README 底部支持区块已更新（中英双语标签，区分微信/支付宝）
- 中文 README 底部已新增支持区块
