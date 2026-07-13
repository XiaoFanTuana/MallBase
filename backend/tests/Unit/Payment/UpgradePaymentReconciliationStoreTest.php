<?php

declare(strict_types=1);

namespace tests\Unit\Payment;

use app\service\upgrade\UpgradePaymentReconciliationStore;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class UpgradePaymentReconciliationStoreTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/mallbase-payment-reconciliation-' . bin2hex(random_bytes(8));
        mkdir($this->root, 0700, true);
    }

    protected function tearDown(): void
    {
        $this->remove($this->root);
        parent::tearDown();
    }

    public function testCallbackLedgerPersistsOnlyDigestAndMonotonicRevision(): void
    {
        $store = new UpgradePaymentReconciliationStore($this->root);

        $first = $store->recordCallback(self::jobId(), 'payment', 'MB100-RAW-ORDER', 100);
        $second = $store->recordCallback(self::jobId(), 'refund', 'R100-RAW-REFUND', 105);
        $bytes = file_get_contents($this->root . '/' . self::jobId() . '/payment-reconciliation.json');

        self::assertSame(1, $first['callback_revision']);
        self::assertSame(2, $second['callback_revision']);
        self::assertSame(105, $second['last_callback_at']);
        self::assertSame(['payment' => 1, 'refund' => 1], $second['callback_counts']);
        self::assertIsString($bytes);
        self::assertStringNotContainsString('MB100-RAW-ORDER', $bytes);
        self::assertStringNotContainsString('R100-RAW-REFUND', $bytes);
        self::assertStringContainsString('sha256:', $bytes);
    }

    public function testCheckpointSaveUsesRevisionCompareAndSet(): void
    {
        $store = new UpgradePaymentReconciliationStore($this->root);
        $initial = $store->load(self::jobId());

        $saved = $store->saveCheckpoint(self::jobId(), $initial['revision'], [
            'phase' => 'payments',
            'payment_cursor' => 10,
        ]);

        self::assertSame(1, $saved['revision']);
        self::assertSame(10, $store->load(self::jobId())['checkpoint']['payment_cursor']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('UPGRADE_PAYMENT_RECONCILIATION_REVISION_CONFLICT');
        $store->saveCheckpoint(self::jobId(), 0, ['phase' => 'refunds']);
    }

    private static function jobId(): string
    {
        return '018f0000-0000-7000-8000-000000000001';
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
