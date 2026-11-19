<?php

use App\Models\User;
use App\Notifications\WelcomeNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['app.locale' => 'es', 'i18n.default_locale' => 'es', 'i18n.locales' => ['es', 'en']]);
    App::setLocale('es');
});

/** Captura el idioma y el asunto con los que se renderiza el correo al enviarlo (sin enviarlo). */
function capturaEnvio(): object
{
    $capture = (object) ['locale' => null, 'subject' => null];

    Event::listen(NotificationSending::class, function (NotificationSending $event) use ($capture) {
        $capture->locale = App::getLocale();
        $capture->subject = $event->notification->toMail($event->notifiable)->subject;

        return false; // no enviar de verdad
    });

    return $capture;
}

test('el correo se renderiza en el idioma del destinatario aunque quien lo dispara trabaje en otro', function () {
    $user = User::factory()->create(['locale' => 'en']);
    $capture = capturaEnvio();

    // El proceso (quien dispara / el worker) está en español
    App::setLocale('es');
    $user->notifyNow(new WelcomeNotification);

    expect($capture->locale)->toBe('en')
        ->and($capture->subject)->toStartWith('Welcome')
        ->and(App::getLocale())->toBe('es');
});

test('un destinatario en español recibe el correo en español aunque el proceso esté en inglés', function () {
    $user = User::factory()->create(['locale' => 'es']);
    $capture = capturaEnvio();

    App::setLocale('en');
    $user->notifyNow(new WelcomeNotification);

    expect($capture->locale)->toBe('es')->and($capture->subject)->toStartWith('Bienvenido');
    App::setLocale('es');
});

test('una notificación en cola se encola y al ejecutarse usa el idioma del destinatario', function () {
    Queue::fake();
    $user = User::factory()->create(['locale' => 'en']);

    $user->notify(new WelcomeNotification);

    Queue::assertPushed(SendQueuedNotifications::class);
    expect($user->preferredLocale())->toBe('en');
});

test('sin idioma propio el destinatario recibe el de la instalación', function () {
    $user = User::factory()->create(['locale' => null]);

    expect($user->preferredLocale())->toBe('es');
});
