<?php

namespace App\Http\Controllers\Webhooks;

use App\Contracts\InboundWebhookServiceInterface;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Webhooks entrantes de proveedores (`POST /webhooks/inbound/{provider}`, y el alias histórico
 * `POST /webhooks/mail` = Resend). Ruta pública: la credencial es la firma, no un login.
 * Toda la lógica vive en `InboundWebhookService`; aquí solo se responde 200 rápido.
 */
class InboundWebhookController extends Controller
{
    public function __construct(private readonly InboundWebhookServiceInterface $service) {}

    /**
     * Recibe un webhook de un proveedor registrado en `config/integrations.php`.
     *
     * Verifica la firma sobre el cuerpo crudo, guarda el recibo y encola su proceso. Una entrega repetida
     * (mismo `delivery_id`) responde 200 sin reprocesar.
     */
    public function receive(Request $request, string $provider): JsonResponse
    {
        $result = $this->service->receive($provider, $request);

        return $this->sendSuccess(
            ['status' => $result['status']],
            $result['status'] === 'duplicate' ? 'Evento duplicado, ignorado' : 'OK',
        );
    }
}
