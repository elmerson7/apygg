<?php

namespace App\Console\Commands;

use App\Models\Webhook;
use Illuminate\Console\Command;

/**
 * CleanupOldWebhookSecretsCommand
 *
 * Comando para limpiar secrets antiguos de webhooks después del período de gracia.
 * Debe ejecutarse periódicamente (ej: diariamente) para mantener la seguridad.
 */
class CleanupOldWebhookSecretsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'webhooks:cleanup-old-secrets
                            {--grace-period-days=7 : Días de período de gracia}
                            {--dry-run : Ejecutar sin hacer cambios reales}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Limpiar secrets antiguos de webhooks después del período de gracia';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $gracePeriodDays = (int) $this->option('grace-period-days');
        $dryRun = $this->option('dry-run');

        $this->info("Buscando webhooks con secrets antiguos (período de gracia: {$gracePeriodDays} días)...");

        $webhooks = Webhook::whereNotNull('old_secret')
            ->whereNotNull('secret_rotated_at')
            ->get();

        $expiredCount = 0;
        $cleanedCount = 0;

        foreach ($webhooks as $webhook) {
            if (! $webhook->isOldSecretValid($gracePeriodDays)) {
                $expiredCount++;

                if ($dryRun) {
                    $this->line("  [DRY RUN] Limpiaría secret antiguo del webhook: {$webhook->name} (ID: {$webhook->id})");
                } else {
                    $webhook->clearOldSecret();
                    $cleanedCount++;
                    $this->info("  ✓ Secret antiguo limpiado del webhook: {$webhook->name} (ID: {$webhook->id})");
                }
            }
        }

        if ($dryRun) {
            $this->info("\n[DRY RUN] Se limpiarían {$expiredCount} secret(s) antiguo(s)");
        } else {
            $this->info("\n✓ Limpieza completada: {$cleanedCount} secret(s) antiguo(s) limpiado(s)");
        }

        return Command::SUCCESS;
    }
}
