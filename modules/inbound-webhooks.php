<?php

// Manifiesto del módulo de núcleo «inbound-webhooks» (docs/modules.md): no se quita sin rehacer el boilerplate.
return [
    'description' => 'Webhooks entrantes genéricos (proveedores, firma, recibos).',
    'core' => true,
    'depends' => [],
    'files' => [
        'app/Console/Commands/InboundWebhooksSweepCommand.php',
        'app/Contracts/HostResolverInterface.php',
        'app/Contracts/InboundWebhookHandlerInterface.php',
        'app/Contracts/InboundWebhookServiceInterface.php',
        'app/Contracts/InspectsWebhookSignatureInterface.php',
        'app/Contracts/WebhookSignatureVerifierInterface.php',
        'app/Exceptions/InboundWebhookException.php',
        'app/Http/Controllers/Webhooks/InboundWebhookController.php',
        'app/Integrations/Webhooks/HmacSignatureVerifier.php',
        'app/Jobs/ProcessInboundWebhookJob.php',
        'app/Models/InboundWebhookReceipt.php',
        'app/Services/Webhooks/DnsHostResolver.php',
        'app/Services/Webhooks/InboundWebhookService.php',
        'database/migrations/2026_01_01_000310_create_inbound_webhook_receipts_table.php',
        'routes/api/inbound-webhooks.php',
        'tests/Support/FakeHostResolver.php',
        'tests/Support/RecordingInboundHandler.php',
    ],
    'migrations' => [],
    'tables' => [],
    'permissions' => [],
    'env' => [],
    'packages' => [],
];
