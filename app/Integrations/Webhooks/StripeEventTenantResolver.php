<?php

namespace App\Integrations\Webhooks;

use App\Contracts\InboundWebhookTenantResolverInterface;
use App\Support\Tenancy\TenantQuery;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Decide a qué empresa pertenece un evento de Stripe, SOLO a partir de lo que APYGG guardó localmente (el payload ya está
 * firmado, pero los ids son lo único fiable): 1) el pago local con ese PaymentIntent, 2) el pago local con la clave de
 * idempotencia de la metadata (la pusimos nosotros), 3) la cuenta de facturación con ese `customer`.
 * Devuelve null si el evento no corresponde a nada nuestro (el handler lo ignora).
 */
class StripeEventTenantResolver implements InboundWebhookTenantResolverInterface
{
    public function resolve(Request $request, array $payload): ?int
    {
        $object = is_array($payload['data']['object'] ?? null) ? $payload['data']['object'] : [];

        $paymentIntent = match ($object['object'] ?? null) {
            'payment_intent' => $object['id'] ?? null,
            default => $object['payment_intent'] ?? null,
        };
        $key = is_array($object['metadata'] ?? null) ? ($object['metadata']['idempotency_key'] ?? null) : null;
        $customer = $object['customer'] ?? null;

        return TenantQuery::withoutTenant(function () use ($paymentIntent, $key, $customer): ?int {
            if (is_string($paymentIntent) && $paymentIntent !== '') {
                $id = DB::table('payments')->where('gateway', 'stripe')->where('gateway_payment_id', $paymentIntent)->value('tenant_id');

                if ($id !== null) {
                    return (int) $id;
                }
            }

            if (is_string($key) && $key !== '') {
                $id = DB::table('payments')->where('gateway', 'stripe')->where('idempotency_key', $key)->value('tenant_id');

                if ($id !== null) {
                    return (int) $id;
                }
            }

            if (is_string($customer) && $customer !== '') {
                $id = DB::table('billing_accounts')->where('gateway', 'stripe')->where('gateway_customer_id', $customer)->value('tenant_id');

                return $id !== null ? (int) $id : null;
            }

            return null;
        }, 'webhook de Stripe: resolver la empresa del evento');
    }
}
