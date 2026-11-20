<?php

namespace App\Listeners\Billing;

use App\Contracts\BillingNoticeServiceInterface;
use App\Events\Billing\InvoicePaid;
use App\Events\Billing\PaymentFailed;
use App\Events\Billing\SubscriptionCanceled;

/**
 * Avisos de facturación a dueños/admins ante los eventos de dominio de la 6.1 (docs/billing.md §Avisos).
 * Los eventos llegan tras el commit; el servicio es idempotente y no propaga errores al cobro.
 */
class SendBillingNotices
{
    public function handleInvoicePaid(InvoicePaid $event): void
    {
        app(BillingNoticeServiceInterface::class)->invoicePaid($event->tenantId, $event->invoiceId);
    }

    public function handlePaymentFailed(PaymentFailed $event): void
    {
        app(BillingNoticeServiceInterface::class)->paymentFailed($event->tenantId, $event->invoiceId, $event->attempt, $event->nextRetryAt);
    }

    public function handleSubscriptionCanceled(SubscriptionCanceled $event): void
    {
        app(BillingNoticeServiceInterface::class)->subscriptionEnded($event->tenantId, $event->subscriptionId, $event->status, $event->reason);
    }
}
