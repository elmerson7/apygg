<?php

namespace Database\Seeders;

use App\Models\Mail\MailAccount;
use App\Models\Mail\MailProvider;
use App\Models\Mail\MailSetting;
use App\Models\Mail\MailTemplateConfig;
use Illuminate\Database\Seeder;

/**
 * MailSystemSeeder
 *
 * Crea la configuración mínima del sistema de correo a partir de las variables
 * de entorno ya existentes (MAIL_MAILER, MAIL_FROM_ADDRESS, MAIL_FROM_NAME),
 * de modo que el proyecto envía correo desde el primer día sin tocar el panel.
 *
 * Es idempotente: se puede volver a ejecutar sin duplicar nada.
 */
class MailSystemSeeder extends Seeder
{
    public function run(): void
    {
        $driver = (string) config('mail.fallback', 'log');

        $provider = MailProvider::firstOrCreate(
            ['driver' => $driver],
            [
                'name' => ucfirst($driver),
                'is_active' => true,
                'sort_order' => 0,
                'config' => $driver === 'smtp' ? [
                    'host' => config('mail.mailers.smtp.host'),
                    'port' => config('mail.mailers.smtp.port'),
                    'encryption' => config('mail.mailers.smtp.scheme') ?? 'tls',
                ] : null,
            ]
        );

        $account = MailAccount::firstOrCreate(
            ['from_email' => (string) config('mail.from.address')],
            [
                'name' => 'Cuenta principal',
                'mail_provider_id' => $provider->id,
                'from_name' => (string) config('mail.from.name'),
                'is_active' => true,
                'is_default' => true,
                'priority' => 0,
            ]
        );

        MailTemplateConfig::seedDefaults();

        // Asociar a la cuenta principal las plantillas que aún no tengan una
        MailTemplateConfig::query()
            ->whereNull('mail_account_id')
            ->update(['mail_account_id' => $account->id]);

        // Ajustes globales: sin pausa y sin límites por defecto
        MailSetting::setValue(MailSetting::KEY_GLOBAL_PAUSE, false);
        MailSetting::setValue(MailSetting::KEY_DAILY_LIMIT, 0);
        MailSetting::setValue(MailSetting::KEY_RATE_LIMIT, 0);

        $this->command->info("Sistema de correo configurado: proveedor '{$provider->name}', cuenta '{$account->from_email}'");
        $this->command->info('Plantillas registradas: '.MailTemplateConfig::count());
    }
}
