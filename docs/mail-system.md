# Sistema de correo multi-cuenta

Laravel sabe enviar correo. Lo que no trae de fábrica es la capa que hace falta
en cuanto el proyecto está en producción:

- enviar desde **varias direcciones** (transaccional, notificaciones, soporte);
- **pausar** un tipo de correo concreto sin desplegar;
- saber **si un correo llegó**, rebotó o se abrió, sin entrar al panel del proveedor;
- un **botón de emergencia** que corte todos los envíos.

Eso es lo que añade este módulo, encima del mailer de Laravel.

## Piezas

| Tabla | Modelo | Qué guarda |
|---|---|---|
| `mail_providers` | `MailProvider` | Proveedor y driver (resend, smtp, ses…) + config no sensible |
| `mail_accounts` | `MailAccount` | Direcciones de envío (`from_email` / `from_name`) |
| `mail_template_configs` | `MailTemplateConfig` | Qué cuenta usa cada plantilla y si está activa |
| `mail_settings` | `MailSetting` | Pausa global y límites de envío |
| `mail_logs` | `MailLog` | Un registro por correo enviado |
| `mail_events` | `MailEvent` | Eventos del proveedor recibidos por webhook |

| Clase | Rol |
|---|---|
| `MailService` | Punto de entrada único para enviar |
| `SendMailJob` | Envío asíncrono con reintentos y actualización del registro |
| `MailEventStatusService` | Traduce los eventos del proveedor al estado del envío |
| `SvixSignatureVerifier` | Verifica la firma de los webhooks |
| `MailWebhookController` | Recibe los eventos de entrega |

## Puesta en marcha

```bash
make art cmd="migrate"
make art cmd="db:seed --class=MailSystemSeeder"
make art cmd="mail:test tu@correo.com"
```

El seeder crea el proveedor y la cuenta a partir de las variables que ya tienes
(`MAIL_MAILER`, `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME`), registra las plantillas
por defecto y deja el sistema sin pausas ni límites. Es idempotente.

## Enviar correo

**Siempre** a través de `MailService`. Nunca `Mail::send()` directo: te saltarías
el registro, la pausa y la selección de cuenta.

```php
use App\Services\MailService;

// Mínimo
MailService::send('ana@ejemplo.com', 'Bienvenida', 'emails.welcome', ['name' => 'Ana']);

// Con plantilla (permite pausarlo y asignarle una cuenta desde el panel)
MailService::send(
    to: ['email' => 'ana@ejemplo.com', 'name' => 'Ana'],
    subject: 'Bienvenida',
    view: 'emails.welcome',
    data: ['name' => 'Ana'],
    templateKey: 'welcome',
);

// Varios destinatarios: un registro y un job por cada uno
MailService::send(['a@ejemplo.com', 'b@ejemplo.com'], 'Aviso', 'emails.notice');

// Programado (1 hora)
MailService::send('ana@ejemplo.com', 'Recordatorio', 'emails.reminder', delaySeconds: 3600);

// Síncrono: solo cuando necesitas el resultado en el acto
MailService::send('ana@ejemplo.com', 'Prueba', 'emails.test', queue: false);
```

### Qué ocurre en cada envío

1. Se comprueba la pausa global, la pausa de la plantilla y el límite diario.
2. Se resuelve la cuenta: la de la plantilla → la predeterminada → la de menor
   prioridad.
3. Se crea el `MailLog` en estado `pending`.
4. Se despacha `SendMailJob`, que vuelve a comprobar la pausa de la plantilla
   (un correo encolado hace una hora puede haberse pausado mientras tanto).
5. El job envía y actualiza el registro a `sent` o `failed`.
6. Si hay webhooks activos, el proveedor confirma después `delivered` o `bounced`.

### Estados

| Estado | Significado |
|---|---|
| `pending` | Registrado, aún no enviado |
| `scheduled` | Programado para más tarde |
| `sent` | Aceptado por el proveedor |
| `delivered` | Confirmado en el buzón (vía webhook) |
| `bounced` | Rechazado de forma permanente o marcado como spam |
| `failed` | Error de envío |

## Plantillas

Una `template_key` es la etiqueta con la que el código identifica un tipo de
correo. Registrarla permite, desde el panel:

- **Pausarla**: `POST /admin/mail/templates/{id}/toggle`. Corta ese correo en el
  acto, incluido el que ya está en cola.
- **Asignarle una cuenta**: los avisos de facturación salen desde
  `facturacion@`, los de producto desde `hola@`.

Añade las tuyas en `MailTemplateConfig::getDefaults()`.

## Pausa global y límites

```http
PUT /admin/mail/settings
{ "global_pause": true }
```

Con la pausa activa, `MailService` descarta cualquier envío y lo deja anotado en
el log de la aplicación. Es el botón de emergencia cuando un bucle empieza a
mandar miles de correos.

`daily_limit` y `rate_limit` (0 = sin límite) protegen la cuota del proveedor.

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
- Los **datos sensibles no llegan al registro**: `MailService` filtra
  `password`, `token`, `reset_url`, `secret`, `api_key` y `content` antes de
  guardar los metadatos.
- El **webhook siempre verifica la firma**.

## Notas de rendimiento

- `mail_logs` es la tabla que más crece. Tiene índices por estado, plantilla,
  destinatario y `provider_message_id` (el que usa el webhook para correlacionar).
- El listado del historial pagina en SQL; nunca cargues la tabla entera.
- `SendMailJob` se despacha a la cola de `config/mail-system.php` → `queue`.
  Sepárala de la cola por defecto (`MAIL_QUEUE=mail`) si tienes trabajos pesados:
  evita que un lote de PDFs retrase las notificaciones.

## Referencias

- `app/Services/MailService.php` → punto de entrada
- `app/Jobs/SendMailJob.php` → envío y reintentos
- `config/mail-system.php` → retención, cola, webhooks
- `docs/logging-file-structure.md` → dónde aterrizan los logs de la aplicación
