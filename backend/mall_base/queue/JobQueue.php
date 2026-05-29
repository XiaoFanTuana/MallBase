<?php

declare(strict_types=1);

namespace mall_base\queue;

use mall_base\base\BaseJob;
use think\facade\Queue;

class JobQueue
{
    public static function push(string $job, mixed $data = '', ?string $queue = null): mixed
    {
        return Queue::push($job, $data, self::resolveQueue($job, $queue));
    }

    public static function later(mixed $delay, string $job, mixed $data = '', ?string $queue = null): mixed
    {
        return Queue::later($delay, $job, $data, self::resolveQueue($job, $queue));
    }

    private static function resolveQueue(string $job, ?string $queue): string
    {
        if ($queue !== null && $queue !== '') {
            return $queue;
        }

        if (is_subclass_of($job, BaseJob::class)) {
            /** @var class-string<BaseJob> $job */
            return $job::queueName();
        }

        return BaseJob::QUEUE;
    }
}
