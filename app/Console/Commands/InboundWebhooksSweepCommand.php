<?php

namespace App\Console\Commands;

use App\Contracts\InboundWebhookServiceInterface;
use Illuminate\Console\Command;

class InboundWebhooksSweepCommand extends Command
{
    protected $signature = 'webhooks:inbound-sweep {--stuck-minutes=10 : Minutos sin procesar para reencolar un recibo}';

    protected $description = 'Reencola los webhooks entrantes atascados y borra los antiguos (retención configurable)';

    public function handle(InboundWebhookServiceInterface $service): int
    {
        $result = $service->sweep((int) $this->option('stuck-minutes'));

        $this->info("Reencolados: {$result['redispatched']} · borrados: {$result['pruned']}");

        return self::SUCCESS;
    }
}
