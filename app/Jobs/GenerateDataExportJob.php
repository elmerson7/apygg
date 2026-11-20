<?php

namespace App\Jobs;

use App\Contracts\PersonalDataExportServiceInterface;
use Throwable;

/**
 * Genera el ZIP de datos personales de una exportación (docs/privacy.md). Idempotente.
 */
class GenerateDataExportJob extends Job
{
    public int $timeout = 300;

    public function __construct(public readonly int $exportId)
    {
        parent::__construct();
        $this->timeout = 300;
        $this->onQueue((string) config('privacy.export.queue', 'default'));
        $this->afterCommit();
    }

    public function process(PersonalDataExportServiceInterface $exports): void
    {
        $exports->generate($this->exportId);
    }

    public function failed(Throwable $exception): void
    {
        parent::failed($exception);

        // Sin el mensaje de la excepción (puede contener datos personales): solo la clase.
        app(PersonalDataExportServiceInterface::class)->markFailed($this->exportId, $exception::class);
    }
}
