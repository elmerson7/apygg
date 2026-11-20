<?php

namespace App\Jobs;

use App\Contracts\ImportServiceInterface;
use App\Models\DataImport;
use App\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Throwable;

/**
 * Preview de una importación (docs/export-import.md). Sin reintentos: el servicio cambia el estado de forma
 * atómica y el importador es idempotente, así que repetir no duplica nada. Corre en el tenant de la importación.
 */
class PreviewImportJob extends Job
{
    public function __construct(protected int $importId)
    {
        parent::__construct();

        $this->tries = 1;
        $this->timeout = (int) config('export.job_timeout', 600);
    }

    public function process(ImportServiceInterface $imports, TenantContext $context): void
    {
        $import = DataImport::query()->find($this->importId);

        if (! $import instanceof DataImport) {
            return;
        }

        if ($import->tenant_id !== null && $context->enabled() && $context->id() !== $import->tenant_id) {
            $context->run(Tenant::query()->findOrFail($import->tenant_id), fn () => $imports->preview($import));

            return;
        }

        $imports->preview($import);
    }

    public function failed(Throwable $exception): void
    {
        parent::failed($exception);

        $import = DataImport::query()->find($this->importId);

        if ($import instanceof DataImport && ! in_array($import->status, [DataImport::STATUS_COMPLETED, DataImport::STATUS_FAILED], true)) {
            app(ImportServiceInterface::class)->markFailed($import, class_basename($exception).': '.$exception->getMessage());
        }
    }
}
