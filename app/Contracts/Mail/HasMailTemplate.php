<?php

namespace App\Contracts\Mail;

/**
 * HasMailTemplate
 *
 * Lo implementa cualquier Mailable o Notification que quiera aparecer en
 * `mail_template_configs`: así el panel puede pausarlo o asignarle una cuenta
 * de envío propia, y el registro `mail_logs` lo etiqueta con esa clave.
 *
 * Es estático a propósito: el listener de `MessageSending` solo conoce el
 * nombre de la clase (Laravel lo pasa en `__laravel_mailable` /
 * `__laravel_notification`), no la instancia.
 */
interface HasMailTemplate
{
    public static function mailTemplateKey(): string;
}
