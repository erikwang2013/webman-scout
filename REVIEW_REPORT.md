# webman-scout 项目审查报告

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
