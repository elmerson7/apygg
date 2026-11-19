<?php

namespace App\Console\Commands;

use App\Contracts\MailServiceInterface;
use App\Models\Mail\MailAccount;
use Illuminate\Console\Command;

/**
 * MailTestCommand
 *
 * Envía un correo de prueba para verificar la configuración sin tener que
 * disparar un flujo real de la aplicación.
 *
 * Uso:
 *   php artisan mail:test alguien@ejemplo.com
 *   php artisan mail:test alguien@ejemplo.com --account=2
 *   php artisan mail:test alguien@ejemplo.com --queue
 */
class MailTestCommand extends Command
{
    protected $signature = 'mail:test
                            {email : Dirección de destino}
                            {--account= : ID de la cuenta de envío (por defecto, la predeterminada)}
                            {--queue : Encolar en lugar de enviar de forma síncrona}';

    protected $description = 'Enviar un correo de prueba para verificar la configuración';

    public function handle(MailServiceInterface $mail): int
    {
        $email = (string) $this->argument('email');

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error("Dirección inválida: {$email}");

            return Command::FAILURE;
        }

        $account = $this->option('account')
            ? MailAccount::find($this->option('account'))
            : MailAccount::getActiveForSending();

        if (! $account) {
            $this->error('No hay ninguna cuenta de correo configurada.');
            $this->line('Ejecuta: php artisan db:seed --class=MailSystemSeeder');

            return Command::FAILURE;
        }

        $this->table(['Propiedad', 'Valor'], [
            ['Cuenta', $account->name],
            ['Remitente', "{$account->from_name} <{$account->from_email}>"],
            ['Driver', $account->getDriver()],
            ['Destino', $email],
            ['Modo', $this->option('queue') ? 'encolado' : 'síncrono'],
        ]);

        $sent = $mail->send(
            to: $email,
            subject: 'Correo de prueba — '.config('app.name'),
            view: 'emails.test',
            data: [
                'app_name' => config('app.name'),
                'account_name' => $account->name,
                'driver' => $account->getDriver(),
                'sent_at' => now()->format('Y-m-d H:i:s'),
            ],
            queue: (bool) $this->option('queue'),
            account: $account,
        );

        if (! $sent) {
            $this->error('No se pudo enviar. Revisa storage/logs y la tabla mail_logs.');

            return Command::FAILURE;
        }

        $this->info($this->option('queue')
            ? '✓ Correo encolado. Verifica el worker de colas y mail_logs.'
            : '✓ Correo enviado.');

        return Command::SUCCESS;
    }
}
