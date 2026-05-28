<?php

declare(strict_types=1);

namespace app\cron\tasks;

use app\cron\CronTaskInterface;
use app\job\AutoReceiveOrdersJob;
use app\job\CloseExpiredOrdersJob;
use Swoole\Timer;
use think\facade\Cache;
use think\facade\Queue;
use Throwable;

class OrderMaintenanceCron implements CronTaskInterface
{
    private const LOCK_KEY = 'cron:order-maintenance:dispatch';
    private const LOCK_TTL = 55;

    public function register(): void
    {
        Timer::tick(60000, function (): void {
            if (!$this->acquireLock()) {
                return;
            }

            Queue::push(CloseExpiredOrdersJob::class, ['limit' => 500], 'default');
            Queue::push(AutoReceiveOrdersJob::class, ['limit' => 500], 'default');
        });
    }

    private function acquireLock(): bool
    {
        try {
            $handler = Cache::handler();
            if (is_object($handler) && method_exists($handler, 'setnx') && method_exists($handler, 'expire')) {
                $acquired = (bool) $handler->setnx(self::LOCK_KEY, 1);
                if (!$acquired) {
                    return false;
                }
                $handler->expire(self::LOCK_KEY, self::LOCK_TTL);
                return true;
            }
        } catch (Throwable) {
            return true;
        }

        if (Cache::has(self::LOCK_KEY)) {
            return false;
        }
        Cache::set(self::LOCK_KEY, 1, self::LOCK_TTL);
        return true;
    }
}
