<?php

/**
 * Copyright (c) erik <erik@erik.xyz> (https://erik.xyz). All Rights Reserved.
 */

namespace Erikwang2013\WebmanScout;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Events\Dispatcher;
use Erikwang2013\WebmanScout\Events\ModelsFlushed;
use Erikwang2013\WebmanScout\Events\ModelsImported;

class SearchableScope implements Scope
{
    /**
     * Apply the scope to a given Eloquent query builder.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $builder
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return void
     */
    public function apply(EloquentBuilder $builder, Model $model)
    {
        //
    }

    /**
     * Extend the query builder with the needed functions.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $builder
     * @return void
     */
    /**
     * chunkById 的第 4 个 $alias 参数为 Laravel 8 新增，Laravel 7 只有 3 参，
     * 按运行时版本决定是否传递，保证 illuminate ^7.0 兼容。
     */
    protected static ?bool $chunkByIdSupportsAlias = null;

    protected function chunkByIdArguments(EloquentBuilder $builder, string $scoutKeyName): array
    {
        $args = [$builder->qualifyColumn($scoutKeyName)];

        if (static::$chunkByIdSupportsAlias ??= (new \ReflectionMethod($builder->getQuery(), 'chunkById'))->getNumberOfParameters() >= 4) {
            $args[] = $scoutKeyName;
        }

        return $args;
    }

    /**
     * 派发进度事件。
     *
     * 不走全局 event()：Webman 的 event 插件、Laravel 的 helpers 都会定义同名函数，
     * 且签名各不相同（按事件名派发），会把事件对象当成字符串事件名。
     * 容器里的 Illuminate\Events\Dispatcher 在各宿主上都是同一个实例。
     */
    protected static function dispatch($event): void
    {
        app(Dispatcher::class)->dispatch($event);
    }

    public function extend(EloquentBuilder $builder)
    {
        $builder->macro('searchable', function (EloquentBuilder $builder, $chunk = null) {
            $scoutKeyName = $builder->getModel()->getScoutKeyName();

            $builder->chunkById($chunk ?: scout_config('chunk.searchable', 500), function ($models) {
                $models->filter->shouldBeSearchable()->searchable();

                static::dispatch(new ModelsImported($models));
            }, ...$this->chunkByIdArguments($builder, $scoutKeyName));
        });

        $builder->macro('unsearchable', function (EloquentBuilder $builder, $chunk = null) {
            $scoutKeyName = $builder->getModel()->getScoutKeyName();

            $builder->chunkById($chunk ?: scout_config('chunk.unsearchable', 500), function ($models) {
                $models->unsearchable();

                static::dispatch(new ModelsFlushed($models));
            }, ...$this->chunkByIdArguments($builder, $scoutKeyName));
        });

        if (method_exists(HasManyThrough::class, 'chunkById')) {
            HasManyThrough::macro('searchable', function ($chunk = null) {
                /** @var HasManyThrough $this */
                $this->chunkById($chunk ?: scout_config('chunk.searchable', 500), function ($models) {
                    $models->filter->shouldBeSearchable()->searchable();

                    static::dispatch(new ModelsImported($models));
                });
            });

            HasManyThrough::macro('unsearchable', function ($chunk = null) {
                /** @var HasManyThrough $this */
                $this->chunkById($chunk ?: scout_config('chunk.unsearchable', 500), function ($models) {
                    $models->unsearchable();

                    static::dispatch(new ModelsFlushed($models));
                });
            });
        }
    }
}
