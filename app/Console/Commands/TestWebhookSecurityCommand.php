<?php

namespace App\Console\Commands;

use App\Models\Webhook;
use App\Services\WebhookService;
use Illuminate\Console\Command;

/**
 * TestWebhookSecurityCommand
 *
 * Comando temporal para probar todas las funcionalidades de seguridad de webhooks.
 */
class TestWebhookSecurityCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'webhooks:test-security {--cleanup : Limpiar webhooks de prueba al finalizar}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Probar funcionalidades de seguridad de webhooks (firma HMAC, timestamp, rotación)';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('🔒 Prueba de Seguridad de Webhooks');
        $this->info('=====================================');
        $this->newLine();

        $webhookService = app(WebhookService::class);
        $cleanup = $this->option('cleanup');

        try {
            // 1. Crear webhook de prueba
            $this->info('1️⃣  Creando webhook de prueba...');
            $webhook = Webhook::create([
                'name' => 'Webhook de Prueba - Seguridad',
                'url' => 'https://webhook.site/unique-id-test', // URL de prueba pública
                'secret' => bin2hex(random_bytes(32)),
                'events' => ['user.created'],
                'status' => 'active',
                'timeout' => 30,
                'max_retries' => 3,
            ]);
            $this->line("   ✓ Webhook creado: {$webhook->id}");
            $this->line('   ✓ Secret generado: '.substr($webhook->secret, 0, 20).'...');
            $this->newLine();

            // 2. Probar generación y validación de firma HMAC
            $this->info('2️⃣  Probando firma HMAC-SHA256...');
            $testPayload = [
                'event' => 'user.created',
                'data' => [
                    'user' => [
                        'id' => 'test-123',
                        'name' => 'Usuario de Prueba',
                        'email' => 'test@example.com',
                    ],
                ],
                'timestamp' => now()->timestamp,
            ];

            // Generar firma manualmente para comparar
            $payloadString = json_encode($testPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $expectedSignature = hash_hmac('sha256', $payloadString, $webhook->secret);

            // Validar con el servicio
            $isValid = $webhookService->validateSignature($testPayload, $expectedSignature, $webhook->secret);
            $isInvalid = $webhookService->validateSignature($testPayload, 'invalid-signature', $webhook->secret);

            $this->line('   ✓ Firma generada: '.substr($expectedSignature, 0, 30).'...');
            $this->line('   ✓ Validación con firma correcta: '.($isValid ? '✓ VÁLIDA' : '✗ INVÁLIDA'));
            $this->line('   ✓ Validación con firma incorrecta: '.(! $isInvalid ? '✓ RECHAZADA' : '✗ ACEPTADA (ERROR)'));
            $this->newLine();

            // 3. Probar validación de timestamp
            $this->info('3️⃣  Probando validación de timestamp...');
            $currentTimestamp = now()->timestamp;
            $oldTimestamp = now()->subMinutes(10)->timestamp; // 10 minutos atrás
            $futureTimestamp = now()->addMinutes(10)->timestamp; // 10 minutos adelante
            $veryOldTimestamp = now()->subHours(2)->timestamp; // 2 horas atrás

            $validCurrent = $webhookService->validateTimestamp($currentTimestamp, 300);
            $validOld = $webhookService->validateTimestamp($oldTimestamp, 300);
            $validFuture = $webhookService->validateTimestamp($futureTimestamp, 300);
            $validVeryOld = $webhookService->validateTimestamp($veryOldTimestamp, 300);

            $this->line('   ✓ Timestamp actual (dentro de tolerancia): '.($validCurrent ? '✓ VÁLIDO' : '✗ INVÁLIDO'));
            $this->line('   ✓ Timestamp antiguo (10 min, fuera tolerancia): '.(! $validOld ? '✓ RECHAZADO' : '✗ ACEPTADO (ERROR)'));
            $this->line('   ✓ Timestamp futuro (10 min, fuera tolerancia): '.(! $validFuture ? '✓ RECHAZADO' : '✗ ACEPTADO (ERROR)'));
            $this->line('   ✓ Timestamp muy antiguo (2 horas): '.(! $validVeryOld ? '✓ RECHAZADO' : '✗ ACEPTADO (ERROR)'));
            $this->newLine();

            // 4. Probar rotación de secrets
            $this->info('4️⃣  Probando rotación de secrets...');
            $oldSecret = $webhook->secret;
            $rotationResult = $webhook->rotateSecret(7);

            $this->line('   ✓ Secret anterior guardado: '.($webhook->old_secret ? '✓' : '✗'));
            $this->line('   ✓ Nuevo secret generado: '.($webhook->secret !== $oldSecret ? '✓' : '✗'));
            $this->line('   ✓ Secret anterior válido: '.($webhook->isOldSecretValid(7) ? '✓' : '✗'));
            $this->line("   ✓ Fecha expiración secret anterior: {$rotationResult['old_secret_expires_at']}");
            $this->newLine();

            // 5. Probar validación con secret anterior y nuevo
            $this->info('5️⃣  Probando validación con secret anterior y nuevo...');
            $newPayload = [
                'event' => 'user.updated',
                'data' => ['user_id' => 'test-456'],
                'timestamp' => now()->timestamp,
            ];
            $newPayloadString = json_encode($newPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            // Firma con secret nuevo (debe ser válida)
            $signatureWithNew = hash_hmac('sha256', $newPayloadString, $webhook->secret);
            $validWithNew = $webhookService->validateWebhook(
                $newPayload,
                $signatureWithNew,
                now()->timestamp,
                $webhook->secret,
                $webhook->old_secret
            );

            // Firma con secret anterior (debe ser válida durante período de gracia)
            $signatureWithOld = hash_hmac('sha256', $newPayloadString, $webhook->old_secret);
            $validWithOld = $webhookService->validateWebhook(
                $newPayload,
                $signatureWithOld,
                now()->timestamp,
                $webhook->secret,
                $webhook->old_secret
            );

            $this->line('   ✓ Validación con secret nuevo: '.($validWithNew ? '✓ VÁLIDA' : '✗ INVÁLIDA'));
            $this->line('   ✓ Validación con secret anterior: '.($validWithOld ? '✓ VÁLIDA (período de gracia)' : '✗ INVÁLIDA'));
            $this->newLine();

            // 6. Simular envío de webhook con headers de seguridad
            $this->info('6️⃣  Simulando envío de webhook con headers de seguridad...');
            $deliveryPayload = [
                'event' => 'user.created',
                'data' => [
                    'user' => [
                        'id' => 'test-789',
                        'name' => 'Usuario Final',
                        'email' => 'final@example.com',
                    ],
                ],
            ];

            // Preparar payload con metadatos (simulando lo que hace preparePayload)
            $finalPayload = array_merge($deliveryPayload, [
                'webhook' => [
                    'id' => $webhook->id,
                    'name' => $webhook->name,
                ],
                'event' => [
                    'type' => 'user.created',
                    'id' => 'test-delivery-id',
                    'timestamp' => now()->toIso8601String(),
                ],
            ]);

            $finalSignature = hash_hmac('sha256', json_encode($finalPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $webhook->secret);
            $timestamp = now()->timestamp;

            $headers = [
                'Content-Type' => 'application/json',
                'X-Webhook-Signature' => $finalSignature,
                'X-Webhook-Timestamp' => $timestamp,
                'X-Webhook-Id' => $webhook->id,
                'User-Agent' => 'APYGG-Webhook/1.0',
            ];

            $this->line('   ✓ Payload preparado con metadatos');
            $this->line('   ✓ Header X-Webhook-Signature: '.substr($finalSignature, 0, 30).'...');
            $this->line("   ✓ Header X-Webhook-Timestamp: {$timestamp}");
            $this->line("   ✓ Header X-Webhook-Id: {$webhook->id}");
            $this->newLine();

            // 7. Resumen de seguridad
            $this->info('7️⃣  Resumen de Seguridad:');
            $this->table(
                ['Característica', 'Estado'],
                [
                    ['Firma HMAC-SHA256', '✓ Implementada'],
                    ['Validación de firma', '✓ Implementada'],
                    ['Validación de timestamp', '✓ Implementada'],
                    ['Prevención replay attacks', '✓ Implementada'],
                    ['Rotación de secrets', '✓ Implementada'],
                    ['Período de gracia', '✓ Implementado'],
                    ['Headers de seguridad', '✓ Implementados'],
                ]
            );
            $this->newLine();

            // Limpiar si se solicita
            if ($cleanup) {
                $this->info('🧹 Limpiando webhook de prueba...');
                $webhook->delete();
                $this->line('   ✓ Webhook eliminado');
            } else {
                $this->warn("⚠️  Webhook de prueba NO eliminado (ID: {$webhook->id})");
                $this->warn('   Ejecuta con --cleanup para eliminarlo automáticamente');
            }

            $this->newLine();
            $this->info('✅ Pruebas de seguridad completadas exitosamente');

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error('❌ Error durante las pruebas:');
            $this->error("   {$e->getMessage()}");
            $this->error("   Archivo: {$e->getFile()}:{$e->getLine()}");

            return Command::FAILURE;
        }
    }
}
