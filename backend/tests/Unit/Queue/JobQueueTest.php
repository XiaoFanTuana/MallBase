<?php

declare(strict_types=1);

namespace Tests\Unit\Queue;

use mall_base\base\BaseJob;
use mall_base\queue\JobQueue;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class JobQueueTest extends TestCase
{
    public function testExplicitQueueOverridesJobDefault(): void
    {
        $this->assertSame('sms', $this->resolveQueue(JobQueueCustomQueueJob::class, 'sms'));
    }

    public function testJobDeclaredQueueIsUsedWhenQueueIsNotExplicit(): void
    {
        $this->assertSame('custom', $this->resolveQueue(JobQueueCustomQueueJob::class, null));
    }

    public function testBaseJobDefaultQueueIsUsedWhenJobDoesNotOverrideQueue(): void
    {
        $this->assertSame('default', $this->resolveQueue(JobQueueDefaultQueueJob::class, null));
    }

    public function testNonBaseJobFallsBackToDefaultQueue(): void
    {
        $this->assertSame('default', $this->resolveQueue(JobQueuePlainJob::class, null));
    }

    private function resolveQueue(string $job, ?string $queue): string
    {
        $method = new ReflectionMethod(JobQueue::class, 'resolveQueue');
        $method->setAccessible(true);

        return $method->invoke(null, $job, $queue);
    }
}

final class JobQueueCustomQueueJob extends BaseJob
{
    public const QUEUE = 'custom';

    public function handle(): void
    {
    }
}

final class JobQueueDefaultQueueJob extends BaseJob
{
    public function handle(): void
    {
    }
}

final class JobQueuePlainJob
{
}
