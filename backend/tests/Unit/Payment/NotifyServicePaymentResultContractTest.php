<?php

declare(strict_types=1);

namespace Tests\Unit\Payment;

use app\service\SystemSettingService;
use app\service\admin\order\RefundOrderAdminService;
use app\service\client\payment\NotifyService;
use app\service\client\payment\WechatPaymentResultService;
use app\service\client\payment\WechatPayFactory;
use app\service\upgrade\UpgradeGateRepository;
use app\service\upgrade\UpgradeGateSnapshot;
use app\service\upgrade\UpgradePaymentReconciliationStore;
use app\service\upgrade\UpgradeRuntimeIdentity;
use app\service\upgrade\UpgradeState;
use EasyWeChat\Pay\Application as PayApplication;
use EasyWeChat\Pay\Contracts\Validator as ValidatorInterface;
use EasyWeChat\Pay\Message as PayMessage;
use EasyWeChat\Pay\Server as PayServer;
use PHPUnit\Framework\TestCase;
use think\App;
use think\facade\Config;

final class NotifyServicePaymentResultContractTest extends TestCase
{
    private const JOB_ID = '22222222-2222-4222-8222-222222222222';

    private string $root;
    private App $app;
    /** @var array<string,mixed> */
    private array $upgradeConfig;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/mallbase-notify-result-contract-' . bin2hex(random_bytes(8));
        mkdir($this->root, 0700, true);
        $this->app = new App(dirname(__DIR__, 3));
        $this->app->initialize();
        $this->upgradeConfig = (array) Config::get('upgrade', []);
        Config::set(array_replace($this->upgradeConfig, ['enabled' => true]), 'upgrade');
        $this->app->instance(SystemSettingService::class, new NotifyContractSettingService());
    }

    protected function tearDown(): void
    {
        Config::set($this->upgradeConfig, 'upgrade');
        $this->removeTree($this->root);
        parent::tearDown();
    }

    public function testVerifiedPaymentDelegatesToSharedResultServiceThenWritesSanitizedCallbackLedger(): void
    {
        $sequence = [];
        $store = new UpgradePaymentReconciliationStore($this->root);
        $this->bindUpgradeLedger($store, UpgradeState::Draining);
        $payload = $this->paymentPayload();
        $paymentResults = $this->createMock(WechatPaymentResultService::class);
        $paymentResults->expects(self::once())
            ->method('applyVerifiedSuccess')
            ->with(
                self::callback(function (array $actual) use ($payload, &$sequence): bool {
                    $sequence[] = 'apply';

                    return $actual === $payload;
                }),
                'merchant-1',
                'MB100-ABC',
            )
            ->willReturn([
                'applied' => true,
                'duplicate' => false,
                'transaction_id' => 'wx-transaction-secret',
                'out_trade_no' => 'MB100-ABC',
            ]);
        $service = new NotifyService(
            $this->factory($payload, $sequence),
            $paymentResults,
        );

        $response = $service->handle($this->headers('payment-nonce-1'), '{"encrypted":"payment"}');
        $ledger = $store->load(self::JOB_ID);
        $bytes = file_get_contents($this->root . '/' . self::JOB_ID . '/payment-reconciliation.json');

        self::assertSame(['validate', 'decrypt', 'apply'], $sequence);
        self::assertSame(200, $response['status']);
        self::assertSame(['code' => 'SUCCESS', 'message' => '成功'], $response['body']);
        self::assertSame(1, $ledger['callback_revision']);
        self::assertSame(['payment' => 1, 'refund' => 0], $ledger['callback_counts']);
        self::assertMatchesRegularExpression('/^sha256:[0-9a-f]{64}$/D', $ledger['last_callback_digest']);
        self::assertIsString($bytes);
        self::assertStringNotContainsString('wx-transaction-secret', $bytes);
        self::assertStringNotContainsString('MB100-ABC', $bytes);
    }

    public function testSharedPaymentResultFailureReturnsRetryableFailureAndDoesNotAdvanceCallbackLedger(): void
    {
        $sequence = [];
        $store = new UpgradePaymentReconciliationStore($this->root);
        $this->bindUpgradeLedger($store, UpgradeState::Draining);
        $paymentResults = $this->createMock(WechatPaymentResultService::class);
        $paymentResults->expects(self::once())
            ->method('applyVerifiedSuccess')
            ->willThrowException(new \RuntimeException('WECHAT_PAYMENT_AMOUNT_MISMATCH'));
        $service = new NotifyService(
            $this->factory($this->paymentPayload(), $sequence),
            $paymentResults,
        );

        $response = $service->handle($this->headers('payment-nonce-2'), '{"encrypted":"payment"}');

        self::assertSame(500, $response['status']);
        self::assertSame('FAIL', $response['body']['code']);
        self::assertSame(0, $store->load(self::JOB_ID)['callback_revision']);
        self::assertFileDoesNotExist($this->root . '/' . self::JOB_ID . '/payment-reconciliation.json');
    }

    public function testSuccessfulRefundWritesTheSameJobLedgerOnlyWhileReconciling(): void
    {
        $sequence = [];
        $store = new UpgradePaymentReconciliationStore($this->root);
        $this->bindUpgradeLedger($store, UpgradeState::Reconciling);
        $refundResults = $this->createMock(RefundOrderAdminService::class);
        $refundResults->expects(self::once())
            ->method('completeWechatRefund')
            ->with('RF100-SECRET', 500, '2026-07-14T10:00:00+08:00');
        $this->app->instance(RefundOrderAdminService::class, $refundResults);
        $paymentResults = $this->createMock(WechatPaymentResultService::class);
        $paymentResults->expects(self::never())->method('applyVerifiedSuccess');
        $service = new NotifyService(
            $this->factory([
                'out_refund_no' => 'RF100-SECRET',
                'refund_status' => 'SUCCESS',
                'amount' => ['refund' => 500],
                'success_time' => '2026-07-14T10:00:00+08:00',
            ], $sequence),
            $paymentResults,
        );

        $response = $service->handleRefund($this->headers('refund-nonce-1'), '{"encrypted":"refund"}');
        $ledger = $store->load(self::JOB_ID);
        $bytes = file_get_contents($this->root . '/' . self::JOB_ID . '/payment-reconciliation.json');

        self::assertSame(['validate', 'decrypt'], $sequence);
        self::assertSame(200, $response['status']);
        self::assertSame(['payment' => 0, 'refund' => 1], $ledger['callback_counts']);
        self::assertIsString($bytes);
        self::assertStringNotContainsString('RF100-SECRET', $bytes);
    }

    public function testSuccessfulPaymentOutsideDrainOrReconciliationDoesNotCreateAnUpgradeLedger(): void
    {
        $sequence = [];
        $store = new UpgradePaymentReconciliationStore($this->root);
        $this->bindUpgradeLedger($store, UpgradeState::Normal);
        $paymentResults = $this->createMock(WechatPaymentResultService::class);
        $paymentResults->expects(self::once())
            ->method('applyVerifiedSuccess')
            ->willReturn([
                'applied' => true,
                'duplicate' => false,
                'transaction_id' => 'wx-normal',
                'out_trade_no' => 'MB100-ABC',
            ]);
        $service = new NotifyService(
            $this->factory($this->paymentPayload(), $sequence),
            $paymentResults,
        );

        $response = $service->handle($this->headers('payment-nonce-normal'), '{"encrypted":"payment"}');

        self::assertSame(200, $response['status']);
        self::assertSame(0, $store->load(self::JOB_ID)['callback_revision']);
        self::assertFileDoesNotExist($this->root . '/' . self::JOB_ID . '/payment-reconciliation.json');
    }

    /** @param array<string,mixed> $payload @param list<string> $sequence */
    private function factory(array $payload, array &$sequence): WechatPayFactory
    {
        $validator = $this->createStub(ValidatorInterface::class);
        $validator->method('validate')->willReturnCallback(static function () use (&$sequence): void {
            $sequence[] = 'validate';
        });
        $server = $this->createStub(PayServer::class);
        $server->method('getRequestMessage')->willReturnCallback(static function () use ($payload, &$sequence): PayMessage {
            $sequence[] = 'decrypt';

            return new PayMessage(
                $payload,
                '{"event_type":"TRANSACTION.SUCCESS","resource":{"ciphertext":"never-business-data"}}',
            );
        });
        $application = $this->createStub(PayApplication::class);
        $application->method('getValidator')->willReturn($validator);
        $application->method('getServer')->willReturn($server);
        $factory = $this->createStub(WechatPayFactory::class);
        $factory->method('build')->willReturn($application);

        return $factory;
    }

    private function bindUpgradeLedger(UpgradePaymentReconciliationStore $store, UpgradeState $state): void
    {
        $this->app->instance(UpgradePaymentReconciliationStore::class, $store);
        $this->app->instance(UpgradeGateRepository::class, new NotifyContractGate($this->snapshot($state)));
    }

    private function snapshot(UpgradeState $state): UpgradeGateSnapshot
    {
        return new UpgradeGateSnapshot(
            $state,
            10,
            $state === UpgradeState::Normal ? null : self::JOB_ID,
            '1.1.0',
            'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
            1,
            1,
            1,
            1,
            str_repeat('a', 40),
            false,
            [],
            false,
            null,
            1000,
        );
    }

    /** @return array<string,string> */
    private function headers(string $nonce): array
    {
        return [
            'Wechatpay-Signature' => 'signature',
            'Wechatpay-Serial' => 'serial',
            'Wechatpay-Timestamp' => '1000',
            'Wechatpay-Nonce' => $nonce,
            'Content-Type' => 'application/json',
        ];
    }

    /** @return array<string,mixed> */
    private function paymentPayload(): array
    {
        return [
            'mchid' => 'merchant-1',
            'out_trade_no' => 'MB100-ABC',
            'transaction_id' => 'wx-transaction-secret',
            'trade_state' => 'SUCCESS',
            'amount' => ['total' => 1200],
            'payer' => ['openid' => 'openid-secret'],
            'success_time' => '2026-07-14T10:00:00+08:00',
        ];
    }

    private function removeTree(string $path): void
    {
        if (is_file($path) || is_link($path)) {
            @chmod($path, 0600);
            @unlink($path);

            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $this->removeTree($path . '/' . $entry);
            }
        }
        @chmod($path, 0700);
        @rmdir($path);
    }
}

final class NotifyContractSettingService extends SystemSettingService
{
    public function __construct()
    {
    }

    public function getSystemSetting(string|array $codeOrCodes, mixed $default = null): mixed
    {
        return $codeOrCodes === 'pay_wechat_mchid' ? 'merchant-1' : $default;
    }
}

final readonly class NotifyContractGate implements UpgradeGateRepository
{
    public function __construct(private UpgradeGateSnapshot $snapshot)
    {
    }

    public function snapshot(): UpgradeGateSnapshot
    {
        return $this->snapshot;
    }

    public function compareAndSet(int $expectedRevision, UpgradeState $expectedState, UpgradeState $nextState, string $jobId): UpgradeGateSnapshot
    {
        throw new \LogicException('not used');
    }

    public function returnToNormal(int $expectedRevision, UpgradeState $terminalState, string $jobId, bool $platformSyncPending): UpgradeGateSnapshot
    {
        throw new \LogicException('not used');
    }

    public function advanceRuntimeFence(int $expectedRevision, UpgradeRuntimeIdentity $current, UpgradeRuntimeIdentity $target, string $jobId): UpgradeGateSnapshot
    {
        throw new \LogicException('not used');
    }

    public function forceMaintenance(int $expectedRevision, string $jobId, string $failureCode): UpgradeGateSnapshot
    {
        throw new \LogicException('not used');
    }

    public function recordActivityUncertainty(int $expectedRevision, array $taintedBoots): UpgradeGateSnapshot
    {
        throw new \LogicException('not used');
    }

    public function acknowledgeRuntimeRegistration(int $expectedRevision, array $runtimeRecord): UpgradeGateSnapshot
    {
        throw new \LogicException('not used');
    }

    public function beginActivityRecovery(int $expectedRevision, string $redisIncarnation): UpgradeGateSnapshot
    {
        throw new \LogicException('not used');
    }

    public function recordRetiredTaintedOwner(int $expectedRevision, string $ownerKey): UpgradeGateSnapshot
    {
        throw new \LogicException('not used');
    }

    public function clearActivityUncertainty(int $expectedRevision, array $requiredRoles, array $cleanRoleRecords): UpgradeGateSnapshot
    {
        throw new \LogicException('not used');
    }
}
