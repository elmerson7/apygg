# Notificaciones multicanal

Un único camino para avisar a usuarios: `NotificationServiceInterface::notify()`. Canales: **mail** (el camino de correo
existente), **database** (bandeja in-app), **push** (dispositivos del usuario) y **sms** (integración de `docs/integrations.md`).

## Enviar

```php
// 1) Declara el tipo en config/notifications.php
'types' => ['invoice_paid' => ['channels' => ['database', 'mail', 'push']]],   // 'mandatory' => true para seguridad

// 2) Textos en lang/es/notifications.php y lang/en/notifications.php (paridad de claves)
'types' => ['invoice_paid' => ['title' => 'Factura pagada', 'body' => 'La factura :number se pagó']],

// 3) Enviar (en cola, afterCommit). `action_url` añade un botón al correo.
app(NotificationServiceInterface::class)->notify($user, 'invoice_paid', ['number' => 'F-12', 'action_url' => $url], $tenant);
```

- La clave del tipo es `snake_case` sin puntos. Un tipo desconocido lanza `BusinessLogicException` (422).
- `notifyNow()` envía en la misma petición (comandos, pruebas).
- Un solo job por destinatario (`TypedNotification`); el canal `delivery` reparte por canales. El correo lleva la clave de
  plantilla `notification` (`HasMailTemplate`), así que la pausa del panel afecta a todos los correos de este módulo.
- **Multiempresa:** la notificación captura el tenant del contexto (o el `$tenant` que pases) y se restaura en el worker.
  Sin tenant con multiempresa activa, la bandeja se omite (`no_tenant_context`); los demás canales siguen.

## Preferencias y silencio

- Por usuario, tipo y canal (opt-out). Sin fila = activado. Son de la persona: valen en todos sus tenants.
- **Horas de silencio** (`HH:MM` en la zona horaria del usuario, `LocaleResolver`; pueden cruzar la medianoche): los canales de
  `notifications.quiet_hours.channels` (por defecto `push` y `sms`) no se envían dentro de la franja. La bandeja y el correo sí.
- Los tipos **`mandatory`** (p. ej. `security_alert`) ignoran opt-out y silencio, y la API rechaza desactivarlos (422).
- Lo omitido queda en `notification_deliveries` con `status=skipped` y `skipped_reason` (`opt_out`, `quiet_hours`, `no_destination`, `no_tenant_context`).

## Entregas idempotentes

`notification_deliveries` tiene UNIQUE `(notification_id, user_id, channel)`. Antes de enviar cada canal se **reclama** con un
UPDATE condicionado (`pending|failed` o `sending` con arrendamiento vencido, `lease_seconds`): si no lo consigues, no envías.
Un job repetido no duplica nada; un reintento tras un fallo solo reenvía el canal fallido. Un error permanente de
integración no se reintenta. `notifications:sweep` (10 min) marca como fallidas las `sending` abandonadas;
`notifications:purge` (diario) borra entregas terminadas, bandeja leída y dispositivos sin verse según `config/notifications.php`.

## Push y dispositivos

`PushTransportInterface` sigue el patrón de `docs/integrations.md`: `PUSH_MODE=fake` (por defecto, `FakePushTransport`:
`app(PushTransportInterface::class)->sent()`) o `real` con `PUSH_DRIVER=fcm` (Firebase HTTP v1 sin SDK: cuenta de servicio en
`FCM_CREDENTIALS_PATH` y `FCM_PROJECT_ID`) o `log`. Un token que FCM da por inexistente (`PushTokenInvalidError`) borra el dispositivo.
Un token solo está activo en un usuario: registrarlo lo mueve. La API nunca devuelve el token completo.

## API (perfil del usuario; sin permiso propio, cada uno toca solo lo suyo; el id ajeno es 404)

| Ruta | Descripción |
|---|---|
| `GET /user/notifications` | bandeja paginada (`?unread=1`, `?type=`, `?per_page=`); con `X-Tenant` |
| `GET /user/notifications/unread-count` | `{unread}` |
| `POST /user/notifications/{id}/read` · `POST /user/notifications/read-all` | marcar leída / todas |
| `DELETE /user/notifications/{id}` | borrar |
| `GET/PUT /user/notifications/preferences` | matriz tipo/canal y `quiet_hours` |
| `GET/POST /user/devices` · `DELETE /user/devices/{id}` | dispositivos push (sin `tenant`) |

## Pruebas

`tests/Feature/Notifications/` y `tests/Feature/Tenancy/Aislamiento/NotificacionesAislamientoTest.php`. En tests, `config(['queue.default' => 'sync'])`
y `notifyNow()`; el push y el sms son fakes con `sent()`/`failNext()`.
