<?php

namespace App\Providers;

use App\Listeners\Mail\MailSystemListener;
use App\Mail\Transport\AccountsTransport;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\ServiceProvider;

/**
 * Engancha el sistema de correo (cuentas en BD, pausa, límites, registro) al
 * de Laravel: un transport propio y listeners de MessageSending/MessageSent.
 */
class MailSystemServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Mail::extend('accounts', fn () => new AccountsTransport(fn () => app('mail.manager')));

        Event::listen(MessageSending::class, [MailSystemListener::class, 'handleSending']);
        Event::listen(MessageSent::class, [MailSystemListener::class, 'handleSent']);
    }
}
