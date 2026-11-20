<?php

use App\Enums\InvoiceStatus;
use App\Enums\SubscriptionStatus;

test('las transiciones permitidas de la suscripción son exactamente las de la tabla', function () {
    $permitidas = [
        'trialing' => ['active', 'past_due', 'canceled'],
        'active' => ['past_due', 'canceled'],
        'past_due' => ['active', 'canceled', 'expired'],
        'incomplete' => ['active', 'expired', 'canceled'],
        'canceled' => [],
        'expired' => [],
    ];

    foreach (SubscriptionStatus::cases() as $from) {
        $reales = array_map(fn ($s) => $s->value, $from->allowedTransitions());
        expect($reales)->toEqualCanonicalizing($permitidas[$from->value], "desde {$from->value}");
    }
});

test('toda transición que no está en la tabla está prohibida', function (SubscriptionStatus $from, SubscriptionStatus $to) {
    expect($from->canTransitionTo($to))->toBe(in_array($to, $from->allowedTransitions(), true));
})->with(function () {
    foreach (SubscriptionStatus::cases() as $from) {
        foreach (SubscriptionStatus::cases() as $to) {
            yield "{$from->value} -> {$to->value}" => [$from, $to];
        }
    }
});

test('canceled y expired son terminales y no dan acceso; past_due lo conserva', function () {
    expect(SubscriptionStatus::Canceled->isTerminal())->toBeTrue()
        ->and(SubscriptionStatus::Expired->isTerminal())->toBeTrue()
        ->and(SubscriptionStatus::Canceled->grantsAccess())->toBeFalse()
        ->and(SubscriptionStatus::Incomplete->grantsAccess())->toBeFalse()
        ->and(SubscriptionStatus::PastDue->grantsAccess())->toBeTrue()
        ->and(SubscriptionStatus::liveValues())->toEqualCanonicalizing(['trialing', 'active', 'past_due', 'incomplete']);
});

test('una factura pagada o anulada no cambia de estado; una abierta sí', function () {
    expect(InvoiceStatus::Paid->allowedTransitions())->toBe([])
        ->and(InvoiceStatus::Void->allowedTransitions())->toBe([])
        ->and(InvoiceStatus::Open->canTransitionTo(InvoiceStatus::Paid))->toBeTrue()
        ->and(InvoiceStatus::Paid->canTransitionTo(InvoiceStatus::Open))->toBeFalse();
});
