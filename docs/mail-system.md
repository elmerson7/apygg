# Sistema de correo multi-cuenta

Un **único camino**, el estándar de Laravel: todo correo es un **Mailable** o una
**Notification** y se envía con `Mail::` / `->notify()`. Las capacidades propias
del módulo (cuentas en BD, pausa, límite diario, registro, estados por webhook)
no son un camino paralelo: se enganchan al sistema de correo de Laravel, así que
valen para cualquier correo sin que el código que lo envía haga nada especial.

- enviar desde **varias direcciones** (transaccional, notificaciones, soporte);
- **pausar** un tipo de correo concreto sin desplegar;
- saber **si un correo llegó**, rebotó o se abrió, sin entrar al panel del proveedor;
- un **botón de emergencia** que corte todos los envíos.

## Cómo enviar un correo nuevo

1. Crea un **Mailable** (`make art cmd="make:mail PedidoEnviado"`) o una
   **Notification** (`make:notification`) con `ShouldQueue`.
2. Si quieres poder **pausarlo o asignarle cuenta** desde el panel, implementa
   `App\Contracts\Mail\HasMailTemplate` y devuelve su clave:

```php
class PedidoEnviadoNotification extends Notification implements HasMailTemplate, ShouldQueue
{
    use Queueable;

    public static function mailTemplateKey(): string
    {
        return 'pedido_enviado';
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage { /* ... */ }
}

$user->notify(new PedidoEnviadoNotification);           // en cola
Mail::to($email)->queue(new PedidoEnviadoMail($pedido)); // Mailable
```

3. Registra la clave en `MailTemplateConfig::getDefaults()` (o desde el panel,
   `POST /admin/mail/templates/seed-defaults`).

Sin `HasMailTemplate` el correo sale igual (pasa por pausa global, límite y
registro) pero no se puede pausar ni asignar por separado.

Para enviar una **vista Blade** suelta sin escribir clase, `MailService::send()`
construye un `SimpleMail` y lo envía por el mismo camino:

```php
MailService::send('ana@ejemplo.com', 'Aviso', 'emails.test', $data, 'mi_plantilla');
MailService::send(..., queue: false);       // síncrono: devuelve si se envió
MailService::send(..., delaySeconds: 3600); // programado
```

Nunca uses `Mail::mailer('smtp')` ni otro mailer a mano: te saltarías la cuenta
y el registro. Los tests usan `Mail::fake()` / `Notification::fake()` como en
cualquier proyecto Laravel.

## Qué pasa por debajo

`MAIL_DEFAULT_MAILER=accounts` (el mailer por defecto de `config/mail.php`) usa
`AccountsTransport`. En cada envío:

1. `MailSystemListener` escucha `MessageSending`. Resuelve la clave de plantilla
   (la del Mailable/Notification) y consulta `MailSendPolicy`: **pausa global**,
   **plantilla pausada** y **límite diario**. Si deniega, crea un `MailLog` con
   estado `skipped` y el motivo, y devuelve `false`: Laravel cancela el envío.
   Se evalúa **al enviar**, no al encolar: un correo que esperaba en cola
   respeta la pausa que se activó mientras tanto.
2. Si procede, elige la **cuenta** (la de la plantilla → la predeterminada → la de
   menor prioridad), pone su `from` (salvo que el correo fije uno propio) y crea
   el `MailLog` en `pending`.
3. `AccountsTransport` toma la cuenta, resuelve el transport real de su proveedor
   (`resend`, `smtp`, `ses`…) y entrega el mensaje sin las cabeceras internas
   `X-Mail-*`. No guarda estado: cambiar la cuenta activa surte efecto en el
   siguiente envío, también con Octane y workers de larga vida.
4. `MessageSent` marca el registro `sent` con el `message id` del proveedor (el
   de Resend permite correlacionar los webhooks). Si el proveedor falla, el
   transport marca `failed` con el error y relanza: la cola reintenta (cada
   intento deja su propio registro).
5. Con webhooks activos, el proveedor confirma después `delivered` o `bounced`.

Sin ninguna cuenta en la BD, el correo sale por el mailer real de respaldo
(`MAIL_MAILER`, ver `config('mail.fallback')`) y el registro queda sin cuenta.

Limitaciones conocidas: `rate_limit` (por minuto) se guarda pero no se aplica;
el `from` de la cuenta sustituye al de la app (`MAIL_FROM_ADDRESS`) pero no a uno
fijado en el propio Mailable.

## Puesta en marcha

```bash
make art cmd="migrate"
make art cmd="db:seed --class=MailSystemSeeder"
make art cmd="mail:test tu@correo.com"
```

El seeder crea el proveedor y la cuenta a partir de las variables que ya tienes
(`MAIL_MAILER`, `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME`), registra las plantillas
por defecto y deja el sistema sin pausas ni límites. Es idempotente.

## Estados

| Estado | Significado |
|---|---|
| `pending` | Registrado, aún no enviado |
| `scheduled` | Reservado (no lo usa el envío estándar) |
| `sent` | Aceptado por el proveedor |
| `delivered` | Confirmado en el buzón (vía webhook) |
| `bounced` | Rechazado de forma permanente o marcado como spam |
| `failed` | Error de envío |
| `skipped` | Cancelado por pausa global, plantilla pausada o límite diario |

## Piezas

| Tabla | Modelo | Qué guarda |
|---|---|---|
| `mail_providers` | `MailProvider` | Proveedor y driver (resend, smtp, ses…) + config no sensible |
| `mail_accounts` | `MailAccount` | Direcciones de envío (`from_email` / `from_name`) |
| `mail_template_configs` | `MailTemplateConfig` | Qué cuenta usa cada plantilla y si está activa |
| `mail_settings` | `MailSetting` | Pausa global y límites de envío |
| `mail_logs` | `MailLog` | Un registro por intento de correo |
| `mail_events` | `MailEvent` | Eventos del proveedor recibidos por webhook |

## Pausa global, plantillas y límites

```http
PUT /admin/mail/settings
{ "global_pause": true }

POST /admin/mail/templates/{id}/toggle
```

La pausa global es el botón de emergencia cuando un bucle empieza a mandar miles
de correos; la de plantilla corta un tipo concreto. `daily_limit` (0 = sin límite)
protege la cuota del proveedor. Todo lo cancelado queda en el historial como
`skipped`.

## Ver los logs

- Panel/API: `GET /admin/mail/logs` (filtros `status`, `template_key`,
  `to_email`, `from`, `to`), `/logs/stats` y `/logs/{id}` con sus eventos.
- Por consola: `make art cmd="tinker"` → `MailLog::latest()->limit(10)->get()`.
- El motivo de una cancelación o un fallo está en `error_message`.

## Webhooks de entrega

Sin webhooks, un correo figura como `sent` para siempre aunque haya rebotado.

1. Configura `RESEND_WEBHOOK_SECRET` (formato `whsec_...`).
2. Apunta el proveedor a `POST https://tu-dominio/webhooks/mail`.

El endpoint es público por necesidad —lo llama el proveedor— y se protege
verificando la firma del payload:

- Esquema Svix (`svix-id`, `svix-timestamp`, `svix-signature`), implementado en
  `SvixSignatureVerifier` sin dependencias externas.
- Ventana de 5 minutos sobre el timestamp: una petición capturada no se puede
  reproducir indefinidamente.
- Idempotencia por `provider_event_id`: los proveedores reintentan y el mismo
  evento puede llegar dos veces.
- Sin secreto configurado, se rechaza todo.

El estado se recalcula a partir del evento más reciente **por fecha de
ocurrencia**, no por orden de llegada, y solo entre los del envío vigente: si un
correo se reenvía, los eventos del intento anterior ya no lo mueven.

## Endpoints

Todos bajo `/admin/mail`, con JWT. Gestión: permiso `mail.manage`.
Historial: permiso `mail.logs`.

| Método | Ruta | Descripción |
|---|---|---|
| GET/POST | `/providers` | Listar / crear proveedores |
| GET/PUT/DELETE | `/providers/{id}` | Ver / editar / eliminar |
| GET/POST | `/accounts` | Listar / crear cuentas |
| GET/PUT/DELETE | `/accounts/{id}` | Ver / editar / eliminar |
| POST | `/accounts/{id}/test` | Enviar correo de prueba (síncrono) |
| GET | `/templates` | Listar plantillas |
| PUT | `/templates/{id}` | Editar plantilla |
| POST | `/templates/{id}/toggle` | Activar / pausar |
| POST | `/templates/seed-defaults` | Registrar las plantillas por defecto |
| GET/PUT | `/settings` | Pausa global y límites |
| GET | `/logs` | Historial (filtros: `status`, `template_key`, `to_email`, `from`, `to`) |
| GET | `/logs/stats` | Resumen por estado y plantilla |
| GET | `/logs/{id}` | Detalle con sus eventos |
| POST | `/webhooks/mail` | Webhook del proveedor (público, firmado) |

## Comandos

```bash
make art cmd="mail:test tu@correo.com"              # prueba síncrona
make art cmd="mail:test tu@correo.com --queue"      # prueba encolada
make art cmd="mail:test tu@correo.com --account=2"  # desde una cuenta concreta
make art cmd="mail:logs-clean --dry-run"            # ver qué se borraría
make art cmd="mail:logs-clean --days=180"           # limpiar historial
```

`mail:logs-clean` se ejecuta solo cada día a las 2:30 (ver `routes/console.php`)
con la retención de `config/mail-system.php`.

## Seguridad

- Las **credenciales no se guardan en la BD**. `mail_providers.config` solo lleva
  datos no sensibles (host, puerto, cifrado); la clave sale de
  `config/services.php` y `config/mail.php`, es decir, del `.env`.
- Los **datos sensibles no llegan al registro**: `mail_logs.metadata` solo guarda
  la clase que originó el correo (y la lista de destinatarios si hay varios);
  nunca los datos de la vista, que pueden traer tokens o contraseñas.
- El **webhook siempre verifica la firma**.

## Notas de rendimiento

- `mail_logs` es la tabla que más crece. Tiene índices por estado, plantilla,
  destinatario y `provider_message_id` (el que usa el webhook para correlacionar).
- El listado del historial pagina en SQL; nunca cargues la tabla entera.
- Los Mailables y Notifications del sistema (`SimpleMail`, bienvenida, reset) van
  a la cola de `config/mail-system.php` → `queue`. Sepárala de la cola por defecto (`MAIL_QUEUE=mail`) si tienes trabajos pesados:
  evita que un lote de PDFs retrase las notificaciones.

## Referencias

- `app/Mail/Transport/AccountsTransport.php` → transport que elige proveedor por envío
- `app/Listeners/Mail/MailSystemListener.php` → pausa, límites, registro (`MessageSending`/`MessageSent`)
- `app/Services/Mail/MailSendPolicy.php` → reglas de pausa y límite
- `app/Providers/MailSystemServiceProvider.php` → engancha todo a Laravel
- `app/Services/MailService.php` → atajo para enviar una vista sin Mailable
- `config/mail-system.php` → retención, cola, webhooks
- `docs/logging-file-structure.md` → dónde aterrizan los logs de la aplicación
