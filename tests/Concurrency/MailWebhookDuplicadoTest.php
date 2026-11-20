<?php

use App\Models\InboundWebhookReceipt;
use App\Models\Mail\MailEvent;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\Support\ConcurrentRunner;

afterEach(function () {
    DB::table('mail_events')->where('provider_event_id', 'like', 'msg_carrera_%')->delete();
    DB::table('inbound_webhook_receipts')->where('delivery_id', 'like', 'msg_carrera_%')->delete();
});

test('dos entregas simultaneas del mismo evento de correo dan 200 y una sola fila', function () {
    $secret = 'whsec_'.base64_encode(str_repeat('k', 24));
    $eventId = 'msg_carrera_'.bin2hex(random_bytes(4));

    $results = ConcurrentRunner::run(2, function (int $i) use ($secret, $eventId) {
        config(['services.resend.webhook_secret' => $secret, 'queue.default' => 'sync']);

        $body = json_encode(['type' => 'email.delivered', 'data' => ['email_id' => 'em_'.$eventId]]);
        $ts = (string) time();
        $firma = 'v1,'.base64_encode(hash_hmac('sha256', "{$eventId}.{$ts}.{$body}", base64_decode(substr($secret, 6)), true));

        $response = app(Kernel::class)->handle(
            Request::create('/webhooks/mail', 'POST', [], [], [], [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_SVIX_ID' => $eventId,
                'HTTP_SVIX_TIMESTAMP' => $ts,
                'HTTP_SVIX_SIGNATURE' => $firma,
            ], $body)
        );

        return ['status' => $response->getStatusCode(), 'body' => substr((string) $response->getContent(), 0, 200)];
    });

    foreach ($results as $r) {
        expect($r['ok'])->toBeTrue($r['error'] ?? '');
    }

    expect(collect($results)->pluck('result.status')->all())->toBe([200, 200], json_encode($results));
    expect(InboundWebhookReceipt::where('delivery_id', $eventId)->count())->toBe(1);
    expect(MailEvent::where('provider_event_id', $eventId)->count())->toBe(1);
});
