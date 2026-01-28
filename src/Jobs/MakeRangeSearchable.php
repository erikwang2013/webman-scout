<?php

namespace app\queue\redis\search;

use Illuminate\Bus\Queueable;
use Webman\RedisQueue\Consumer;
use Erikwang2013\WebmanScout\Scout;

class MakeRangeSearchable implements Consumer
{
    use Queueable;

    // 要消费的队列名
    public $queue = 'scout_make_range';

    // 连接名，对应 plugin/webman/redis-queue/redis.php 里的连接`
    public $connection = 'default';


    /**
     * Handle the job.
     *
     * @return void
     */
    public function consume($data)
    {

        $model = new $data['model'];

        $models = $model::makeAllSearchableQuery()
            ->whereBetween($model->getScoutKeyName(), [$data['start'], $data['end']])
            ->get()
            ->filter
            ->shouldBeSearchable();

        if ($models->isEmpty()) {
            return;
        }

        dispatch(new Scout::$makeSearchableJob($models))
            ->onQueue($model->syncWithSearchUsingQueue())
            ->onConnection($model->syncWithSearchUsing());
    }
}
