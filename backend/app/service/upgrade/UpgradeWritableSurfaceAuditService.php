<?php

declare(strict_types=1);

namespace app\service\upgrade;

use Closure;
use Throwable;

/** Stateless pre-maintenance audit that never returns configured host paths. */
final readonly class UpgradeWritableSurfaceAuditService
{
    /** @var Closure():string */
    private Closure $localRootProvider;

    public function __construct(
        private LocalUploadRootPolicy $policy,
        private string $publicRoot,
        Closure $localRootProvider,
    ) {
        $this->localRootProvider = $localRootProvider;
    }

    /** @return array{supported:bool,code:string,artifact_count:int,path_category_count:int} */
    public function audit(): array
    {
        try {
            $provider = $this->localRootProvider;
            $configured = $provider();
            if (!is_string($configured)) {
                throw new \RuntimeException('UPGRADE_LOCAL_UPLOAD_ROOT_UNAVAILABLE');
            }
            $this->policy->assertSupported($configured, $this->publicRoot);

            return [
                'supported' => true,
                'code' => 'WRITABLE_SURFACE_SUPPORTED',
                'artifact_count' => 1,
                'path_category_count' => 1,
            ];
        } catch (Throwable $exception) {
            return [
                'supported' => false,
                'code' => in_array($exception->getMessage(), [
                    'UPGRADE_LOCAL_UPLOAD_ROOT_UNSUPPORTED',
                    'UPGRADE_LOCAL_UPLOAD_ROOT_UNAVAILABLE',
                ], true) ? $exception->getMessage() : 'UPGRADE_WRITABLE_SURFACE_UNAVAILABLE',
                'artifact_count' => 0,
                'path_category_count' => 1,
            ];
        }
    }
}
