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
            $message = (string) ($arguments[0] ?? '');
            $context = $arguments[1] ?? [];
            if (is_array($context) && $context !== []) {
                $message .= ' ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }

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

        return \Illuminate\Support\Facades\Log::$name(...$arguments);
    }
}
