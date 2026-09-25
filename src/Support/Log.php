<?php

/**
 * Copyright (c) erik <erik@erik.xyz> (https://erik.xyz). All Rights Reserved.
 */

namespace Erikwang2013\WebmanScout\Support;

use Psr\Log\LoggerInterface;

/**
 * Cross-framework logging adapter.
 * Delegates to Webman's support\Log when available, otherwise falls back to
 * the Illuminate Log facade. Under Yii2 logs via Yii::info/warning/error;
 * under Yii3 delegates to the PSR-3 logger registered by the ConfigProvider.
 * Hosts with no logger at all (plain PHP, Hyperf, ThinkPHP) write to error_log
 * instead of throwing — hook a PSR-3 logger with Log::setLoggerResolver().
 */
class Log
{
    /**
     * @var callable|null fn(): ?LoggerInterface — set by the Yii3 ConfigProvider.
     */
    protected static $loggerResolver;

    public static function setLoggerResolver(?callable $resolver): void
    {
        static::$loggerResolver = $resolver;
    }

    public static function __callStatic($name, $arguments)
    {
        if (class_exists('support\Log')) {
            return \support\Log::$name(...$arguments);
        }

        if (class_exists(\yii\base\Application::class) && isset(\Yii::$app)) {
            $message = static::stringify($arguments);

            if ($name === 'warning') {
                return \Yii::warning($message, 'webman-scout');
            }
            if (in_array($name, ['error', 'emergency', 'alert', 'critical'], true)) {
                return \Yii::error($message, 'webman-scout');
            }

            return \Yii::info($message, 'webman-scout');
        }

        $resolver = static::$loggerResolver;
        if ($resolver !== null) {
            $logger = $resolver();
            if ($logger instanceof LoggerInterface) {
                $logger->$name(...$arguments);

                return null;
            }
        }

        // Illuminate 的 Log facade 需要 illuminate/log 与已绑定的 'log'；Webman 之外的宿主
        // （Hyperf / ThinkPHP / Yii / 原生 PHP）通常没有，此时退回 error_log——
        // 日志适配器不该因为宿主没配日志就把调用方打断。
        if (class_exists(\Illuminate\Support\Facades\Log::class)) {
            try {
                return \Illuminate\Support\Facades\Log::$name(...$arguments);
            } catch (\Throwable $e) {
                // 未绑定 'log' → 走下面的兜底
            }
        }

        error_log('[webman-scout] '.$name.': '.static::stringify($arguments));

        return null;
    }

    /**
     * 把 PSR-3 风格的 message + context 压成一行文本（Yii / error_log 兜底用）。
     */
    protected static function stringify(array $arguments): string
    {
        $message = (string) ($arguments[0] ?? '');
        $context = $arguments[1] ?? [];

        if (is_array($context) && $context !== []) {
            $message .= ' ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        return $message;
    }
}
