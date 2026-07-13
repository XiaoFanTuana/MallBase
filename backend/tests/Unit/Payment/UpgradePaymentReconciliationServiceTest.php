<?php

declare(strict_types=1);

namespace tests\Unit\Payment;

use app\service\admin\order\RefundOrderAdminService;
use app\service\client\payment\WechatPaymentResultService;
use app\service\client\payment\WechatPayClient;
use app\service\client\payment\WechatPayFactory;
use app\service\upgrade\ReconciliationResult;
use app\service\upgrade\UpgradeActivityLease;
use app\service\upgrade\UpgradeActivitySnapshot;
use app\service\upgrade\UpgradeActivityTracker;
use app\service\upgrade\UpgradePaymentReconciliationService;
use app\service\upgrade\UpgradePaymentReconciliationStore;
use app\service\upgrade\UpgradeQueueInventory;
use app\service\upgrade\UpgradeRuntimeInstance;
use app\service\upgrade\UpgradeRuntimeOwnerLiveness;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class UpgradePaymentReconciliationServiceTest extends TestCase
{
    private string $root;
    private int $now = 100;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/mallbase-payment-reconciliation-service-' . bin2hex(random_bytes(8));
        mkdir($this->root, 0700, true);
    }

    protected function tearDown(): void
    {
        $this->remove($this->root);
        parent::tearDown();
    }

    public function testPaginationResumesFromDurableCursorAndRefundSuccessIsApplied(): void
    {
        $store = new UpgradePaymentReconciliationStore($this->root);
        $tracker = new ReconciliationActivityTracker();
        $service = $this->service($store, $tracker, pageSize: 2);
        $service->payments = [
            ['id' => 1, 'out_trade_no' => 'P1'],
            ['id' => 2, 'out_trade_no' => 'P2'],
            ['id' => 3, 'out_trade_no' => 'P3'],
        ];
        $service->refunds = [['id' => 4, 'sn' => 'R4']];
        $service->paymentResponses = [
            'P1' => ['trade_state' => 'NOTPAY'],
            'P2' => ['trade_state' => 'CLOSED'],
            'P3' => ['trade_state' => 'SUCCESS'],
        ];
        $service->refundResponses = ['R4' => ['status' => 'SUCCESS', 'amount' => ['refund' => 500]]];

        $first = $service->run(self::jobId(), 10, 90);
        self::assertSame(ReconciliationResult::RUNNING, $first->status);
        self::assertSame(2, $store->load(self::jobId())['checkpoint']['payment_cursor']);

        $restarted = $this->service($store, $tracker, pageSize: 2);
        $restarted->payments = $service->payments;
        $restarted->refunds = $service->refunds;
        $restarted->paymentResponses = $service->paymentResponses;
        $restarted->refundResponses = $service->refundResponses;
        $second = $restarted->run(self::jobId(), 10, 90);

        self::assertSame(ReconciliationResult::WAITING_QUIET_WINDOW, $second->status);
        self::assertSame(['P3'], $restarted->appliedPayments);
        self::assertSame(['R4'], $restarted->appliedRefunds);
        self::assertSame([3], $restarted->queriedPaymentIds);
    }

    public function testCallbackDuringQuietWindowRestartsSixtySecondObservation(): void
    {
        $store = new UpgradePaymentReconciliationStore($this->root);
        $tracker = new ReconciliationActivityTracker();
        $service = $this->service($store, $tracker);

        $waiting = $service->run(self::jobId(), 10, 90);
        self::assertSame(ReconciliationResult::WAITING_QUIET_WINDOW, $waiting->status);

        $this->now = 130;
        $store->recordCallback(self::jobId(), 'payment', 'P-LATE', $this->now);
        $service->payments = [['id' => 5, 'out_trade_no' => 'P-LATE']];
        $service->paymentResponses['P-LATE'] = ['trade_state' => 'SUCCESS'];
        $this->now = 150;
        $reset = $service->run(self::jobId(), 10, 90);
        self::assertSame(60, $reset->quietRemainingSeconds);

        $this->now = 209;
        $stillWaiting = $service->run(self::jobId(), 10, 90);
        self::assertSame(1, $stillWaiting->quietRemainingSeconds);

        $this->now = 210;
        $complete = $service->run(self::jobId(), 10, 90);
        self::assertTrue($complete->complete());
        self::assertSame(['P-LATE'], $service->appliedPayments);
    }

    public function testNetworkFailureAndUncertainRemoteStateKeepCursorAndMaintenanceBlocked(): void
    {
        $store = new UpgradePaymentReconciliationStore($this->root);
        $service = $this->service($store, new ReconciliationActivityTracker());
        $service->payments = [['id' => 1, 'out_trade_no' => 'P-SECRET']];
        $service->paymentResponses['P-SECRET'] = new RuntimeException('upstream credential leak');

        $failure = $service->run(self::jobId(), 10, 90);
        $bytes = file_get_contents($this->root . '/' . self::jobId() . '/payment-reconciliation.json');

        self::assertSame(ReconciliationResult::RETRY_REQUIRED, $failure->status);
        self::assertSame(0, $store->load(self::jobId())['checkpoint']['payment_cursor']);
        self::assertContains('PAYMENT_QUERY_FAILED', $failure->errorCodes);
        self::assertIsString($bytes);
        self::assertStringNotContainsString('credential leak', $bytes);

        $service->paymentResponses['P-SECRET'] = ['trade_state' => 'USERPAYING'];
        $uncertain = $service->run(self::jobId(), 10, 90);
        self::assertSame(ReconciliationResult::RETRY_REQUIRED, $uncertain->status);
        self::assertContains('PAYMENT_RESULT_UNCERTAIN', $uncertain->errorCodes);
    }

    private function service(
        UpgradePaymentReconciliationStore $store,
        ReconciliationActivityTracker $tracker,
        int $pageSize = 100,
    ): TestUpgradePaymentReconciliationService {
        return new TestUpgradePaymentReconciliationService(
            $store,
            $tracker,
            $this->createStub(WechatPayFactory::class),
            $this->createStub(WechatPayClient::class),
            $this->createStub(WechatPaymentResultService::class),
            $this->createStub(RefundOrderAdminService::class),
            fn(): int => $this->now,
            $pageSize,
        );
    }

    private static function jobId(): string
    {
        return '018f0000-0000-7000-8000-000000000002';
    }

    private function remove(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $child = $path . '/' . $entry;
            is_dir($child) ? $this->remove($child) : unlink($child);
        }
        rmdir($path);
    }
}

final class TestUpgradePaymentReconciliationService extends UpgradePaymentReconciliationService
{
    /** @var list<array{id:int,out_trade_no:string}> */
    public array $payments = [];
    /** @var list<array{id:int,sn:string}> */
    public array $refunds = [];
    /** @var array<string,array<string,mixed>|RuntimeException> */
    public array $paymentResponses = [];
    /** @var array<string,array<string,mixed>|RuntimeException> */
    public array $refundResponses = [];
    /** @var list<string> */
    public array $appliedPayments = [];
    /** @var list<string> */
    public array $appliedRefunds = [];
    /** @var list<int> */
    public array $queriedPaymentIds = [];

    protected function paymentCandidates(int $cursor, int $windowStart, int $cutoff, int $limit): array
    {
        return array_slice(array_values(array_filter(
            $this->payments,
            static fn(array $candidate): bool => $candidate['id'] > $cursor,
        )), 0, $limit);
    }

    protected function refundCandidates(int $cursor, int $windowStart, int $cutoff, int $limit): array
    {
        return array_slice(array_values(array_filter(
            $this->refunds,
            static fn(array $candidate): bool => $candidate['id'] > $cursor,
        )), 0, $limit);
    }

    protected function queryPayment(array $candidate): array
    {
        $this->queriedPaymentIds[] = $candidate['id'];
        $result = $this->paymentResponses[$candidate['out_trade_no']] ?? ['trade_state' => 'NOTPAY'];
        if ($result instanceof RuntimeException) {
            throw $result;
        }

        return $result + ['out_trade_no' => $candidate['out_trade_no']];
    }

    protected function queryRefund(array $candidate): array
    {
        $result = $this->refundResponses[$candidate['sn']] ?? ['status' => 'PROCESSING'];
        if ($result instanceof RuntimeException) {
            throw $result;
        }

        return $result + ['out_refund_no' => $candidate['sn']];
    }

    protected function applyPayment(array $candidate, array $result): void
    {
        $this->appliedPayments[] = $candidate['out_trade_no'];
    }

    protected function applyRefund(array $candidate, array $result): void
    {
        $this->appliedRefunds[] = $candidate['sn'];
    }
}

final class ReconciliationActivityTracker implements UpgradeActivityTracker
{
    public int $activeCallbacks = 0;

    public function tryBeginHttp(string $requestId, UpgradeRuntimeInstance $owner): ?UpgradeActivityLease { return null; }
    public function tryBeginExternalCallback(string $requestId, UpgradeRuntimeInstance $owner): ?UpgradeActivityLease { return null; }
    public function tryBeginCron(string $taskId, UpgradeRuntimeInstance $owner): ?UpgradeActivityLease { return null; }
    public function beginQueuePop(string $workerId, string $connectorType, array $queues, string $executionAttemptId, UpgradeRuntimeInstance $owner): ?UpgradeActivityLease { return null; }
    public function bindQueueJob(UpgradeActivityLease $popLease, string $connection, string $queue, string $jobId): UpgradeActivityLease { return $popLease; }
    public function snapshot(): UpgradeActivitySnapshot { return new UpgradeActivitySnapshot(0, $this->activeCallbacks, 0, 0, 0, false); }
    public function heartbeatWorker(string $workerId, string $connectorType, array $queues, UpgradeRuntimeInstance $owner, int $ttl): void {}
    public function ackPaused(string $workerId, UpgradeRuntimeInstance $owner, int $revision, int $ttl): void {}
    public function liveWorkers(): array { return []; }
    public function reconcileQueueLeases(UpgradeQueueInventory $inventory, UpgradeRuntimeOwnerLiveness $owners): void {}
    public function reconcileOrphanActivityLeases(UpgradeRuntimeOwnerLiveness $owners): void {}
}
