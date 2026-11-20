<?php

namespace App\Jobs;

use App\Contracts\AccountDeletionServiceInterface;
use Throwable;

/**
 * Ejecuta una solicitud de borrado de cuenta vencida (docs/privacy.md). Idempotente.
 */
class ExecuteAccountDeletionJob extends Job
{
    public int $timeout = 300;

    public function __construct(public readonly int $requestId)
    {
        parent::__construct();
        $this->timeout = 300;
        $this->onQueue((string) config('privacy.export.queue', 'default'));
    }

    public function process(AccountDeletionServiceInterface $deletions): void
    {
        $deletions->execute($this->requestId);
    }

    public function failed(Throwable $exception): void
    {
        parent::failed($exception);

        app(AccountDeletionServiceInterface::class)->markFailed($this->requestId, $exception::class);
    }
}
