<?php

declare(strict_types=1);

namespace Tests\Unit\Log;

use mall_base\log\Trace;
use PHPUnit\Framework\TestCase;

final class TraceIsolationContractTest extends TestCase
{
    public function testCoroutineTraceIdsAreStableAndIsolated(): void
    {
        if (!extension_loaded('swoole')) {
            $this->markTestSkipped('当前 PHP 未安装 Swoole 扩展。');
        }

        $ids = [];
        \Swoole\Coroutine\run(function () use (&$ids): void {
            $channels = [new \Swoole\Coroutine\Channel(1), new \Swoole\Coroutine\Channel(1)];
            foreach ($channels as $index => $channel) {
                \Swoole\Coroutine::create(function () use (&$ids, $index, $channel): void {
                    $first = Trace::getCoroutineTraceId();
                    $second = Trace::getCoroutineTraceId();
                    $ids[$index] = [$first, $second];
                    $channel->push(true);
                });
            }
            foreach ($channels as $channel) {
                $channel->pop();
            }
        });

        self::assertSame($ids[0][0], $ids[0][1]);
        self::assertSame($ids[1][0], $ids[1][1]);
        self::assertNotSame($ids[0][0], $ids[1][0]);
    }
}
