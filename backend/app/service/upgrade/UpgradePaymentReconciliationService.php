<?php

declare(strict_types=1);

namespace app\service\upgrade;

use app\common\enum\RefundOrderStatus;
use app\model\order\PaymentLog;
use app\model\order\RefundOrder;
use app\service\admin\order\RefundOrderAdminService;
use app\service\client\payment\WechatPaymentResultService;
use app\service\client\payment\WechatPayClient;
use app\service\client\payment\WechatPayFactory;
use Closure;
use mall_base\base\BaseService;
use mall_base\exception\BusinessException;
use RuntimeException;
use Throwable;

/**
 * 升级维护窗口内的微信支付、退款主动对账核心。
 *
 * 每处理一项即持久化游标；完成首轮后观察 60 秒回调静默窗口，再从头执行最终增量轮。
 * @extends BaseService<PaymentLog>
 */
class UpgradePaymentReconciliationService extends BaseService
{
    protected string $modelClass = PaymentLog::class;

    public function __construct(
        private readonly UpgradePaymentReconciliationStore $store,
        private readonly UpgradeActivityTracker $activity,
        private readonly WechatPayFactory $factory,
        private readonly WechatPayClient $client,
        private readonly WechatPaymentResultService $paymentResults,
        private readonly RefundOrderAdminService $refundResults,
        private readonly Closure $clock,
        private readonly int $pageSize = 100,
    ) {
        if ($this->pageSize < 1 || $this->pageSize > 500) {
            throw new RuntimeException('UPGRADE_PAYMENT_RECONCILIATION_CONFIG_INVALID');
        }
    }

    public function run(string $jobId, int $windowStart, int $cutoff): ReconciliationResult
    {
        if (!$this->validJobId($jobId) || !$this->timestamp($windowStart) || !$this->timestamp($cutoff)
            || $windowStart > $cutoff) {
            throw new RuntimeException('UPGRADE_PAYMENT_RECONCILIATION_ARGUMENT_INVALID');
        }

        $document = $this->store->load($jobId);
        $checkpoint = $document['checkpoint'];
        if ($checkpoint === null) {
            $checkpoint = $this->initialCheckpoint($windowStart, $cutoff);
            $document = $this->store->saveCheckpoint($jobId, $document['revision'], $checkpoint);
        } elseif (($checkpoint['window_start'] ?? null) !== $windowStart || ($checkpoint['cutoff'] ?? null) !== $cutoff) {
            throw new RuntimeException('UPGRADE_PAYMENT_RECONCILIATION_WINDOW_CONFLICT');
        }

        while (true) {
            $phase = (string) ($checkpoint['phase'] ?? '');
            if ($phase === 'payments' || $phase === 'final_payments') {
                $outcome = $this->processPaymentPage($jobId, $document, $checkpoint, $phase === 'final_payments');
                if ($outcome instanceof ReconciliationResult) {
                    return $outcome;
                }
                [$document, $checkpoint] = $outcome;
                continue;
            }
            if ($phase === 'refunds' || $phase === 'final_refunds') {
                $outcome = $this->processRefundPage($jobId, $document, $checkpoint, $phase === 'final_refunds');
                if ($outcome instanceof ReconciliationResult) {
                    return $outcome;
                }
                [$document, $checkpoint] = $outcome;
                continue;
            }
            if ($phase === 'quiet_window') {
                $outcome = $this->observeQuietWindow($jobId, $document, $checkpoint);
                if ($outcome instanceof ReconciliationResult) {
                    return $outcome;
                }
                [$document, $checkpoint] = $outcome;
                continue;
            }
            if ($phase === 'completed') {
                return $this->result(ReconciliationResult::COMPLETED, $checkpoint);
            }

            throw new RuntimeException('UPGRADE_PAYMENT_RECONCILIATION_STATE_INVALID');
        }
    }

    /**
     * @param array<string,mixed> $document
     * @param array<string,mixed> $checkpoint
     * @return ReconciliationResult|array{array<string,mixed>,array<string,mixed>}
     */
    private function processPaymentPage(string $jobId, array $document, array $checkpoint, bool $final): ReconciliationResult|array
    {
        $cursorKey = $final ? 'final_payment_cursor' : 'payment_cursor';
        $cursor = (int) ($checkpoint[$cursorKey] ?? 0);
        $candidates = $this->paymentCandidates(
            $cursor,
            (int) $checkpoint['window_start'],
            (int) $checkpoint['cutoff'],
            $this->pageSize,
        );

        foreach ($candidates as $candidate) {
            $candidateId = (int) ($candidate['id'] ?? 0);
            $outTradeNo = trim((string) ($candidate['out_trade_no'] ?? ''));
            if ($candidateId <= $cursor || $outTradeNo === '') {
                return $this->recordError($jobId, $document, $checkpoint, 'PAYMENT_CANDIDATE_INVALID', 'payment:invalid');
            }
            $digest = $this->candidateDigest('payment', $candidateId, $outTradeNo);
            if (($candidate['already_paid'] ?? false) === true) {
                $this->incrementResult($checkpoint, 'payment_already_applied');
            } else {
                try {
                    $response = $this->queryPayment($candidate);
                } catch (Throwable) {
                    return $this->recordError($jobId, $document, $checkpoint, 'PAYMENT_QUERY_FAILED', $digest);
                }
                $state = strtoupper(trim((string) ($response['trade_state'] ?? '')));
                if ($state === 'SUCCESS') {
                    if (!hash_equals($outTradeNo, trim((string) ($response['out_trade_no'] ?? '')))) {
                        return $this->recordError($jobId, $document, $checkpoint, 'PAYMENT_ORDER_MISMATCH', $digest);
                    }
                    try {
                        $this->applyPayment($candidate, $response);
                    } catch (Throwable) {
                        return $this->recordError($jobId, $document, $checkpoint, 'PAYMENT_APPLY_FAILED', $digest);
                    }
                    $this->incrementResult($checkpoint, 'payment_applied');
                } elseif (in_array($state, ['NOTPAY', 'CLOSED', 'PAYERROR'], true)) {
                    $this->incrementResult($checkpoint, 'payment_resolved');
                } else {
                    return $this->recordError($jobId, $document, $checkpoint, 'PAYMENT_RESULT_UNCERTAIN', $digest);
                }
            }

            $checkpoint[$cursorKey] = $candidateId;
            $checkpoint['checked_payment_ids'][] = $digest;
            $checkpoint['checked_payment_ids'] = array_values(array_unique($checkpoint['checked_payment_ids']));
            $checkpoint['errors'] = $this->withoutCandidateError($checkpoint['errors'], $digest);
            $document = $this->store->saveCheckpoint($jobId, $document['revision'], $checkpoint);
            $checkpoint = $document['checkpoint'];
            $cursor = $candidateId;
        }

        if (count($candidates) === $this->pageSize) {
            return $this->result(ReconciliationResult::RUNNING, $checkpoint);
        }

        $checkpoint['phase'] = $final ? 'final_refunds' : 'refunds';
        $document = $this->store->saveCheckpoint($jobId, $document['revision'], $checkpoint);

        return [$document, $document['checkpoint']];
    }

    /**
     * @param array<string,mixed> $document
     * @param array<string,mixed> $checkpoint
     * @return ReconciliationResult|array{array<string,mixed>,array<string,mixed>}
     */
    private function processRefundPage(string $jobId, array $document, array $checkpoint, bool $final): ReconciliationResult|array
    {
        $cursorKey = $final ? 'final_refund_cursor' : 'refund_cursor';
        $cursor = (int) ($checkpoint[$cursorKey] ?? 0);
        $candidates = $this->refundCandidates(
            $cursor,
            (int) $checkpoint['window_start'],
            (int) $checkpoint['cutoff'],
            $this->pageSize,
        );

        foreach ($candidates as $candidate) {
            $candidateId = (int) ($candidate['id'] ?? 0);
            $refundNo = trim((string) ($candidate['sn'] ?? ''));
            if ($candidateId <= $cursor || $refundNo === '') {
                return $this->recordError($jobId, $document, $checkpoint, 'REFUND_CANDIDATE_INVALID', 'refund:invalid');
            }
            $digest = $this->candidateDigest('refund', $candidateId, $refundNo);
            try {
                $response = $this->queryRefund($candidate);
            } catch (Throwable) {
                return $this->recordError($jobId, $document, $checkpoint, 'REFUND_QUERY_FAILED', $digest);
            }
            $status = strtoupper(trim((string) ($response['status'] ?? $response['refund_status'] ?? '')));
            if ($status === 'SUCCESS') {
                if (!hash_equals($refundNo, trim((string) ($response['out_refund_no'] ?? '')))) {
                    return $this->recordError($jobId, $document, $checkpoint, 'REFUND_ORDER_MISMATCH', $digest);
                }
                try {
                    $this->applyRefund($candidate, $response);
                } catch (Throwable) {
                    return $this->recordError($jobId, $document, $checkpoint, 'REFUND_APPLY_FAILED', $digest);
                }
                $this->incrementResult($checkpoint, 'refund_applied');
            } elseif ($status === 'PROCESSING') {
                $this->incrementResult($checkpoint, 'refund_processing');
            } else {
                return $this->recordError($jobId, $document, $checkpoint, 'REFUND_RESULT_UNCERTAIN', $digest);
            }

            $checkpoint[$cursorKey] = $candidateId;
            $checkpoint['checked_refund_ids'][] = $digest;
            $checkpoint['checked_refund_ids'] = array_values(array_unique($checkpoint['checked_refund_ids']));
            $checkpoint['errors'] = $this->withoutCandidateError($checkpoint['errors'], $digest);
            $document = $this->store->saveCheckpoint($jobId, $document['revision'], $checkpoint);
            $checkpoint = $document['checkpoint'];
            $cursor = $candidateId;
        }

        if (count($candidates) === $this->pageSize) {
            return $this->result(ReconciliationResult::RUNNING, $checkpoint);
        }

        if (!$final) {
            $now = ($this->clock)();
            $checkpoint['phase'] = 'quiet_window';
            $checkpoint['quiet_started_at'] = $now;
            $checkpoint['quiet_callback_revision'] = $document['callback_revision'];
            $document = $this->store->saveCheckpoint($jobId, $document['revision'], $checkpoint);

            return [$document, $document['checkpoint']];
        }

        $current = $this->store->load($jobId);
        $checkpoint = $current['checkpoint'];
        $activeCallbacks = $this->activity->snapshot()->activeCallbacks;
        if ($activeCallbacks > 0 || $current['callback_revision'] !== ($checkpoint['final_callback_revision'] ?? null)) {
            $checkpoint['phase'] = 'quiet_window';
            $checkpoint['quiet_started_at'] = ($this->clock)();
            $checkpoint['quiet_callback_revision'] = $current['callback_revision'];
            $current = $this->store->saveCheckpoint($jobId, $current['revision'], $checkpoint);

            return $this->quietResult($current['checkpoint'], 60);
        }

        if ($checkpoint['errors'] !== []) {
            return $this->result(ReconciliationResult::RETRY_REQUIRED, $checkpoint);
        }
        $checkpoint['phase'] = 'completed';
        $checkpoint['completed_at'] = ($this->clock)();
        $current = $this->store->saveCheckpoint($jobId, $current['revision'], $checkpoint);

        return $this->result(ReconciliationResult::COMPLETED, $current['checkpoint']);
    }

    /**
     * @param array<string,mixed> $document
     * @param array<string,mixed> $checkpoint
     * @return ReconciliationResult|array{array<string,mixed>,array<string,mixed>}
     */
    private function observeQuietWindow(string $jobId, array $document, array $checkpoint): ReconciliationResult|array
    {
        $now = ($this->clock)();
        $activeCallbacks = $this->activity->snapshot()->activeCallbacks;
        $observedRevision = (int) ($checkpoint['quiet_callback_revision'] ?? -1);
        if ($activeCallbacks > 0 || $document['callback_revision'] !== $observedRevision) {
            $checkpoint['quiet_started_at'] = $now;
            $checkpoint['quiet_callback_revision'] = $document['callback_revision'];
            $document = $this->store->saveCheckpoint($jobId, $document['revision'], $checkpoint);

            return $this->quietResult($document['checkpoint'], 60);
        }

        $elapsed = max(0, $now - (int) ($checkpoint['quiet_started_at'] ?? $now));
        if ($elapsed < 60) {
            return $this->quietResult($checkpoint, 60 - $elapsed);
        }

        $checkpoint['phase'] = 'final_payments';
        $checkpoint['final_payment_cursor'] = 0;
        $checkpoint['final_refund_cursor'] = 0;
        $checkpoint['final_callback_revision'] = $document['callback_revision'];
        $checkpoint['errors'] = [];
        $document = $this->store->saveCheckpoint($jobId, $document['revision'], $checkpoint);

        return [$document, $document['checkpoint']];
    }

    /** @return list<array<string,mixed>> */
    protected function paymentCandidates(int $cursor, int $windowStart, int $cutoff, int $limit): array
    {
        $rows = $this->model()
            ->where('id', '>', $cursor)
            ->where('event_type', PaymentLog::EVENT_PREPAY)
            ->where('expire_at', '>=', date('Y-m-d H:i:s', $windowStart))
            ->where('create_time', '<=', date('Y-m-d H:i:s', $cutoff))
            ->order('id', 'asc')
            ->limit($limit)
            ->select()
            ->toArray();

        foreach ($rows as &$row) {
            $row['already_paid'] = $this->model()
                ->where('order_id', (int) ($row['order_id'] ?? 0))
                ->where('event_type', PaymentLog::EVENT_PAID)
                ->count() > 0;
        }
        unset($row);

        return $rows;
    }

    /** @return list<array<string,mixed>> */
    protected function refundCandidates(int $cursor, int $windowStart, int $cutoff, int $limit): array
    {
        return $this->model(RefundOrder::class)
            ->where('id', '>', $cursor)
            ->where('status', RefundOrderStatus::REFUNDING)
            ->whereNull('delete_time')
            ->where('create_time', '<=', date('Y-m-d H:i:s', $cutoff))
            ->order('id', 'asc')
            ->limit($limit)
            ->select()
            ->toArray();
    }

    /** @param array<string,mixed> $candidate @return array<string,mixed> */
    protected function queryPayment(array $candidate): array
    {
        $merchantId = trim((string) getSystemSetting('pay_wechat_mchid', ''));
        if ($merchantId === '') {
            throw new BusinessException('WECHAT_PAYMENT_MERCHANT_MISSING');
        }

        return $this->client->queryByOutTradeNo(
            $this->factory->build(),
            $merchantId,
            (string) $candidate['out_trade_no'],
        );
    }

    /** @param array<string,mixed> $candidate @return array<string,mixed> */
    protected function queryRefund(array $candidate): array
    {
        return $this->client->queryRefundByOutRefundNo(
            $this->factory->build(),
            (string) $candidate['sn'],
        );
    }

    /** @param array<string,mixed> $candidate @param array<string,mixed> $result */
    protected function applyPayment(array $candidate, array $result): void
    {
        $merchantId = trim((string) getSystemSetting('pay_wechat_mchid', ''));
        $this->paymentResults->applyVerifiedSuccess($result, $merchantId, (string) $candidate['out_trade_no']);
    }

    /** @param array<string,mixed> $candidate @param array<string,mixed> $result */
    protected function applyRefund(array $candidate, array $result): void
    {
        $amount = (int) ($result['amount']['refund'] ?? $result['amount']['payer_refund'] ?? 0);
        $this->refundResults->completeWechatRefund(
            (string) $candidate['sn'],
            $amount,
            trim((string) ($result['success_time'] ?? '')) ?: null,
        );
    }

    /** @return array<string,mixed> */
    private function initialCheckpoint(int $windowStart, int $cutoff): array
    {
        return [
            'phase' => 'payments',
            'window_start' => $windowStart,
            'cutoff' => $cutoff,
            'payment_cursor' => 0,
            'refund_cursor' => 0,
            'final_payment_cursor' => 0,
            'final_refund_cursor' => 0,
            'checked_payment_ids' => [],
            'checked_refund_ids' => [],
            'results' => [],
            'errors' => [],
            'quiet_started_at' => null,
            'quiet_callback_revision' => null,
            'final_callback_revision' => null,
            'completed_at' => null,
        ];
    }

    /**
     * @param array<string,mixed> $document
     * @param array<string,mixed> $checkpoint
     */
    private function recordError(
        string $jobId,
        array $document,
        array $checkpoint,
        string $code,
        string $candidateDigest,
    ): ReconciliationResult {
        $checkpoint['errors'] = $this->withoutCandidateError($checkpoint['errors'], $candidateDigest);
        $checkpoint['errors'][] = ['code' => $code, 'candidate' => $candidateDigest];
        $document = $this->store->saveCheckpoint($jobId, $document['revision'], $checkpoint);

        return $this->result(ReconciliationResult::RETRY_REQUIRED, $document['checkpoint']);
    }

    /** @param list<array{code:string,candidate:string}> $errors @return list<array{code:string,candidate:string}> */
    private function withoutCandidateError(array $errors, string $candidateDigest): array
    {
        return array_values(array_filter(
            $errors,
            static fn(array $error): bool => ($error['candidate'] ?? null) !== $candidateDigest,
        ));
    }

    /** @param array<string,mixed> $checkpoint */
    private function incrementResult(array &$checkpoint, string $key): void
    {
        $checkpoint['results'][$key] = ((int) ($checkpoint['results'][$key] ?? 0)) + 1;
    }

    /** @param array<string,mixed> $checkpoint */
    private function result(string $status, array $checkpoint): ReconciliationResult
    {
        $processed = array_sum(array_map('intval', $checkpoint['results'] ?? []));
        $errors = array_values(array_unique(array_map(
            static fn(array $error): string => (string) ($error['code'] ?? 'UPGRADE_PAYMENT_RECONCILIATION_FAILED'),
            $checkpoint['errors'] ?? [],
        )));

        return new ReconciliationResult($status, (string) $checkpoint['phase'], $processed, $errors);
    }

    /** @param array<string,mixed> $checkpoint */
    private function quietResult(array $checkpoint, int $remaining): ReconciliationResult
    {
        $result = $this->result(ReconciliationResult::WAITING_QUIET_WINDOW, $checkpoint);

        return new ReconciliationResult(
            $result->status,
            $result->phase,
            $result->processed,
            $result->errorCodes,
            max(0, $remaining),
        );
    }

    private function candidateDigest(string $kind, int $id, string $externalId): string
    {
        return 'sha256:' . hash('sha256', $kind . "\0" . $id . "\0" . $externalId);
    }

    private function validJobId(string $jobId): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $jobId) === 1;
    }

    private function timestamp(int $value): bool
    {
        return $value >= 0 && $value <= 4_102_444_800;
    }
}
