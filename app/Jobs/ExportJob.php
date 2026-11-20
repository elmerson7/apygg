<?php

namespace App\Jobs;

use App\Contracts\ExportServiceInterface;
use App\Models\DataExport;
use App\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Throwable;

/**
 * Genera el archivo de una exportación. Sin reintentos (el servicio ya reclama el trabajo con un UPDATE
 * atómico y deja el error en la fila). Corre en el tenant de la exportación.
 */
class ExportJob extends Job
{
    public function __construct(protected int $exportId)
    {
        parent::__construct();

        $this->tries = 1;
        $this->timeout = (int) config('export.job_timeout', 600);
    }

    public function process(ExportServiceInterface $exports, TenantContext $context): void
    {
        $export = DataExport::query()->find($this->exportId);

        if (! $export instanceof DataExport) {
            return;
        }

        if ($export->tenant_id !== null && $context->enabled() && $context->id() !== $export->tenant_id) {
            $context->run(Tenant::query()->findOrFail($export->tenant_id), fn () => $exports->run($export));

            return;
        }

        $exports->run($export);
    }

    public function failed(Throwable $exception): void
    {
        parent::failed($exception);

        $export = DataExport::query()->find($this->exportId);

        if ($export instanceof DataExport && ! $export->isCompleted()) {
            app(ExportServiceInterface::class)->markFailed($export, class_basename($exception).': '.$exception->getMessage());
        }
    }
}
