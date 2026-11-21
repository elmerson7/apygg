<?php

// Manifiesto del módulo de núcleo «webhooks» (docs/modules.md): núcleo porque las pruebas de auditoría y aislamiento lo usan
// de recurso de ejemplo y migraciones del núcleo (jsonb, tenant_id) tocan sus tablas.
return [
    'description' => 'Webhooks salientes: suscripciones por tenant, firma HMAC, entregas y reintentos.',
    'core' => true,
    'depends' => [],
    'files' => [
        'app/Services/WebhookService.php',
        'app/Contracts/WebhookServiceInterface.php',
        'app/Enums/WebhookStatusEnum.php',
        'app/Http/Controllers/Webhooks/WebhookController.php',
        'app/Http/Requests/Webhooks',
        'app/Http/Resources/Webhooks',
        'app/Jobs/SendWebhookJob.php',
        'app/Listeners/WebhookListener.php',
        'app/Models/Webhook.php',
        'app/Models/WebhookDelivery.php',
        'app/Observers/WebhookObserver.php',
        'app/Rules/SafeWebhookUrl.php',
        'app/Services/Webhooks/WebhookUrlGuard.php',
        'app/Console/Commands/CleanupOldWebhookSecretsCommand.php',
        'config/webhooks.php',
        'routes/api/webhooks.php',
        'database/factories/WebhookDeliveryFactory.php',
        'database/factories/WebhookFactory.php',
        'tests/Feature/Webhooks/WebhookJobReintentosTest.php',
        'tests/Feature/Webhooks/WebhooksSeguridadTest.php',
        'tests/Feature/Tenancy/Aislamiento/WebhooksAislamientoTest.php',
        'tests/Unit/Requests/UpdateWebhookRequestTest.php',
        'tests/Unit/Webhooks/WebhookUrlGuardTest.php',
        'tests/Feature/WarmCacheCommandTest.php',
        'docs/webhooks.md',
    ],
    'migrations' => [
        'database/migrations/2026_01_01_000160_create_webhooks_table.php',
        'database/migrations/2026_01_01_000170_create_webhook_deliveries_table.php',
    ],
    'tables' => [],
    'permissions' => [],
    'env' => [],
    'packages' => [],
];
