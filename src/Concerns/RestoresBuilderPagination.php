<?php

/**
 * Copyright (c) erik <erik@erik.xyz> (https://erik.xyz). All Rights Reserved.
 */

namespace Erikwang2013\WebmanScout\Concerns;

/**
 * 分页时临时写入 builder 的 limit/offset，结束后恢复，
 * 避免污染后续 get()/keys() 的查询。
 */
trait RestoresBuilderPagination
{
    protected function paginateWithoutMutatingBuilder($builder, int $perPage, int $page, callable $callback)
    {
        $originalLimit = $builder->limit;
        $originalOffset = $builder->offset;
        $builder->limit = $perPage;
        $builder->offset = ($page - 1) * $perPage;

        try {
            return $callback($builder);
        } finally {
            $builder->limit = $originalLimit;
            $builder->offset = $originalOffset;
        }
    }
}
