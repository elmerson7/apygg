# Facturación y suscripciones

Planes, suscripción del tenant, facturas, ciclo de cobro y pasarela de pago (simulada o nula). **Es dinero**: importes
siempre enteros en unidad menor + moneda (`docs/money.md`), numeración con `SequenceInterface` (`docs/sequences.md`),
cobros con clave de idempotencia (`docs/integrations.md` §6) y todo cambio de estado auditado (`docs/audit.md`).
Genérico: ningún negocio concreto. Stripe y la conciliación están en §Stripe y §Conciliación; el bloqueo de escritura por estado en §Bloqueo por estado y los avisos en §Avisos de facturación.

Módulo **quitable**: `app/Services/Billing/`, `app/Models/{Plan,PlanPrice,BillingAccount,Subscription,Invoice,InvoiceLine,Payment}.php`,
`app/Integrations/Payments/`, `app/Events/Billing/`, `app/Http/*/Billing/`, `routes/api/billing.php`, `config/billing.php`, las
migraciones `2026_11_20_0004xx` y los comandos `billing:*`. `PlanLimits` solo consulta la tabla `subscriptions`.

## Modelo de datos

| Tabla | Qué guarda | Notas |
|---|---|---|
| `plans` | código, nombre, `is_public`, `is_active`, `trial_days` | Global (sin `tenant_id`). Se declara en `config/billing.php` y se copia con `billing:sync-plans` |
| `plan_prices` | precio por (plan, moneda, intervalo `month`/`year`) en `unit_amount_minor` | Único por (plan, moneda, intervalo) |
| `billing_accounts` | cliente y método de pago por defecto del tenant en la pasarela | Un registro por tenant. **Nunca datos de tarjeta**: id de la pasarela + marca/últimos 4 |
| `subscriptions` | estado, periodo actual, `cancel_at_period_end`, plan y **precio congelados** | Índice único parcial: **una sola suscripción viva por tenant** |
| `invoices` + `invoice_lines` | factura con `subtotal/tax/total_minor`, número, estado, periodo | Inmutable tras emitirse. Único (subscription_id, period_start) para alta y ciclo |
| `payments` | un intento de cobro (o un pago manual) de una factura | Única por (invoice_id, attempt); una sola `succeeded` y una sola `pending` por factura; estado `refunded` tras un reembolso |

**Catálogo en config + BD** (y no solo BD con CRUD): el catálogo cambia con un deploy revisado y no con un clic, `billing:sync-plans`
es idempotente (lo que sale de la config se desactiva, no se borra) y las filas existen para poder referenciarlas desde suscripciones y
facturas. Los **límites** de cada plan siguen en `config/plans.php` bajo el mismo código. Un cambio de precio **no altera** lo que ya paga cada cliente: la
suscripción copia plan, intervalo, moneda e importe al contratar.

`GET /plans` (público, `throttle:60,1`) devuelve los planes públicos y activos con sus precios (`unit_amount_minor`). Un plan **privado**
(`public => false`, acuerdos a medida) no sale y **el tenant no puede contratarlo ni cambiarse a él por sí mismo** (403 `plan_private`,
aunque conozca el código; tampoco en `preview-change`): solo la plataforma lo asigna con `POST /admin/billing/tenants/{id}/plan`. Quien ya
está en un precio privado puede seguir en él (p. ej. volver a él para cancelar una bajada programada).

## Estados de la suscripción

`SubscriptionStatus` es la **única** tabla de transiciones; `SubscriptionLifecycle::transition()` la aplica (y el modelo `Subscription` la vuelve a comprobar al guardar: ningún camino puede persistir una transición prohibida), audita
(`billing.subscription-status-changed`), refresca los límites y, al terminar, despacha `SubscriptionCanceled`. Cualquier otra transición lanza
409 `invalid_subscription_transition`.

| Desde | Hacia | Cuándo |
|---|---|---|
| `incomplete` | `active` | el primer pago se cobra (o se marca pagado a mano) |
| `incomplete` | `expired` | pasan `incomplete_expire_hours` (24) sin pagar (`billing:renew`) |
| `incomplete` | `canceled` | el cliente desiste |
| `trialing` | `active` | termina la prueba y se cobra |
| `trialing` | `past_due` | termina la prueba y el cobro falla (o no hay método de pago) |
| `trialing`, `active` | `canceled` | baja inmediata, o al llegar el fin del periodo con `cancel_at_period_end` |
| `active` | `past_due` | una renovación se rechaza |
| `past_due` | `active` | un reintento (o el cliente, o un pago manual) cobra la factura |
| `past_due` | `canceled` / `expired` | reintentos agotados (`billing.dunning.final_status`) o baja |
| `canceled`, `expired` | — | terminales: volver a suscribirse crea una fila nueva |

Da acceso al plan (`grantsAccess`): `trialing`, `active` y `past_due` (gracia mientras duran los reintentos). `PlanLimits` lee el plan de la
suscripción con acceso y, si no hay, cae a `tenants.plan` (compatibilidad: sin suscripciones todo funciona como antes).
Cada cambio de estado refresca la caché de límites (`PlanLimitsInterface::refresh`).

## Operaciones (rutas del tenant, `X-Tenant`)

Permisos nuevos `tenant-billing.read` y `tenant-billing.manage` (`tenant-owner` y `tenant-admin`; el miembro raso **no** ve la facturación). Como
con equipos de Spatie apagados el `permission:` de la ruta no basta, cada servicio vuelve a comprobar el rol del actor **en ese tenant**
(`TenantRoles::actorRankFor`, `docs/tenant-onboarding.md`).

| Ruta | Permiso | Qué hace |
|---|---|---|
| `GET /tenant/billing/subscription` | read | suscripción viva (o la última) |
| `POST /tenant/billing/subscription` | manage | contratar: `{plan, interval, currency?, payment_token?}` |
| `POST /tenant/billing/subscription/preview-change` | read | qué pasaría al cambiar (modo y prorrateo), sin hacerlo |
| `POST /tenant/billing/subscription/change` | manage | cambiar de plan |
| `POST /tenant/billing/subscription/cancel` | manage | `{immediately?}`; idempotente |
| `POST /tenant/billing/subscription/resume` | manage | deshace una baja pendiente |
| `GET|PUT /tenant/billing/payment-method` | read / manage | método de pago (solo marca y últimos 4) |
| `GET /tenant/billing/invoices`, `GET .../{id}` | read | facturas paginadas en SQL (`?status=`) con líneas e intentos |
| `POST /tenant/billing/invoices/{id}/pay` | manage | reintenta el cobro (p. ej. tras cambiar de tarjeta) |
| `POST /admin/billing/invoices/{id}/mark-paid` | `billing.manage` | **pago manual**: `{reference, reason}` |
| `POST /admin/billing/tenants/{id}/free-period` | `billing.manage` | **periodo gratis**: `{days, reason}` |
| `POST /admin/billing/tenants/{id}/plan` | `billing.manage` | **asignar un plan** (también privado): `{plan, interval, currency?, reason}` |
| `GET /tenant/billing/invoices/{id}/pdf` | read | PDF de la factura (`application/pdf`, descarga `attachment`) |

Las POST de contratar, cambiar, pagar y asignar plan admiten `Idempotency-Key` (`docs/idempotency.md`).

- **Contratar.** Con prueba (si el plan la tiene y el tenant no usó ya una; `trial_requires_payment_method` para exigir tarjeta) no cobra y queda `trialing`. Plan gratuito:
  `active` sin factura. Plan de pago sin prueba: exige método de pago, emite la factura del alta y cobra; **si el banco rechaza, la
  suscripción queda `incomplete`, la factura `open` y la respuesta es 402 `payment_failed`**; se recupera pagando la factura (otra tarjeta + `/pay`).
- **Cambiar de plan.** Subida (misma moneda e intervalo y más cara) **inmediata**: se prorratea el resto del periodo en enteros
  (crédito por lo no usado del plan actual, cargo por lo que queda al precio nuevo; cada línea se redondea una vez, mitad hacia fuera de cero; `net = cargo - crédito` exacto),
  se emite una factura de ajuste (`subscription_update`) y el plan solo cambia **si se cobra**; si el cobro falla se anula el ajuste y nada cambia. Cualquier otro cambio (más barato, otro intervalo o moneda) se
  **programa para el final del periodo** (`scheduled_plan_price_id`; volver al plan actual lo cancela). En prueba el cambio no cobra.
- **Cancelar.** Al final del periodo (`cancel_at_period_end`; el estado no cambia hasta entonces) o inmediata. Una alta `incomplete` se cancela al instante;
  las facturas abiertas pasan a `void` (alta) o `uncollectible` (renovación impagada).
- **Pago manual** (plataforma): factura `open` o `uncollectible` -> `paid` con una fila `payments` `method=manual` (referencia, motivo, quién), con los mismos efectos que un cobro por
  la pasarela (activa la suscripción, avanza el periodo). Motivo obligatorio (`billing.manual-payment` en `audit.reason_required`); 409 `payment_in_progress` si la pasarela está cobrando y 409 `payment_pending` si queda un intento a medias (corte o timeout): ese intento PUDO cobrar, así que no se da por fallido; `billing:renew` lo resuelve con su misma clave y, si no cobró, ya se puede pagar a mano.
- **Asignar un plan** (plataforma): si el tenant no tiene suscripción viva, la contrata; si la tiene, le cambia el plan. Mismas reglas que
  el tenant (prueba, prorrateo, cobro con SU método de pago: sin él, 422 `payment_method_required`), pero admite planes privados. Motivo
  obligatorio (`billing.plan-assigned` en `audit.reason_required`), auditado en la misma transacción con el actor, el modo y el precio.
- **Periodo gratis** (plataforma): alarga `current_period_end` N días (máx. `billing.free_period_max_days`) en una suscripción `trialing` o `active`; la nueva fecha pasa a ser el
  ancla de cobro. Motivo obligatorio (`billing.free-period`).

## Facturas

- **Número** con `SequenceInterface` (secuencia `billing_invoice` de `config/sequences.php`, nombre en `billing.invoice.sequence`: `F-{year}-000001`, reinicio anual; nombre propio para no compartir contador con una secuencia `invoice` del proyecto derivado) **reservado en la misma transacción que la factura**: si se revierte, el número vuelve a estar libre (sin huecos).
  `BILLING_INVOICE_SEQUENCE_SCOPE=global` (**por defecto**: quien emite es la plataforma, así que una sola serie para todas las empresas; serializa la emisión entre tenants durante la transacción, que es corta) o `tenant` (cada empresa cliente numera aparte).
- **Importes** con `Money`: líneas (`unit_amount_minor` con signo, `amount_minor = quantity * unit`), subtotal, **impuesto simple** (`BILLING_TAX_RATE` como cadena, `"18"`, redondeado una vez sobre el subtotal; sin lógica fiscal de país) y total. CHECK en BD: `total = subtotal + tax`.
- **Inmutable** una vez emitida: `Invoice` solo deja cambiar `status`, `paid_at` y `voided_at` (con transición válida de `InvoiceStatus`); no se borra; sus líneas no se editan (`409 invoice_immutable`). Los importes se guardan, no se recalculan.
- Estados: `draft`, `open`, `paid`, `void`, `uncollectible`.
- **PDF**: `GET /tenant/billing/invoices/{id}/pdf` (`tenant-billing.read` + rango en el tenant; la de otro tenant da 404) con `PdfRendererInterface` (dompdf) y la vista
  `resources/views/billing/invoice-pdf.blade.php`: emisor = marca de la **plataforma** (`BrandResolverInterface` resuelto sin el ajuste del tenant, para que el cliente no pueda
  cambiar al emisor), cliente = nombre del tenant, líneas, subtotal, impuesto y total formateados con `Money::format` en el idioma de la petición. Se genera al vuelo (no se guarda).

## Bloqueo por estado

`ResolveTenant` (alias `tenant`) consulta `SubscriptionAccessInterface::enforce()` tras fijar el tenant, así que **toda ruta con `tenant` queda cubierta sin declarar nada por ruta** (fail-closed). Sin estado entre peticiones ni caché: una lectura indexada por tenant, y un cambio de estado se nota en la petición siguiente. Config en `billing.enforcement` (`BILLING_ENFORCEMENT_ENABLED`, `BILLING_NO_SUBSCRIPTION`, `BILLING_PAST_DUE_GRACE_DAYS`).

| Estado | Lectura (GET/HEAD/OPTIONS) | Escritura |
|---|---|---|
| `trialing`, `active` | si | si |
| `past_due` dentro de la gracia (`past_due_grace_days`, 7, contados desde que se emitió la factura impagada más antigua; sin factura, desde el inicio del periodo) | si | si, con cabecera `X-Subscription-Warning: past_due; grace_ends_at=<ISO 8601>` |
| `past_due` pasada la gracia | si | **402 `payment_required`** |
| `incomplete` (primer pago sin cobrar) | si | **402 `payment_required`** |
| `canceled`, `expired` | si | **402 `subscription_inactive`** |
| sin suscripción | si | `no_subscription=allow` (por defecto, plan gratuito): si; `require`: **402 `subscription_required`** |

El 402 incluye `subscription_status`, `access_mode`, `grace_ends_at`, `invoice_id` y `suggested_action` (`{action, method, path}`: `pay_invoice` -> `POST /tenant/billing/invoices/{id}/pay`, `resubscribe`/`choose_plan` -> `POST /tenant/billing/subscription`). Los clientes ramifican por `code`.

Siempre permitido aunque haya bloqueo (`billing.enforcement.allow_paths`, patrones de `Request::is`): `tenant/billing*` (para poder pagar o recontratar), `auth/*`, `user*` (perfil, consentimientos, exportar y borrar datos personales), `health*` y `webhooks/inbound/*`. Un proyecto derivado añade ahi lo suyo (p. ej. «exportar mis datos»). Las rutas sin `tenant` (login, perfil, `/admin/*` de plataforma) no pasan por el bloqueo. `BILLING_ENFORCEMENT_ENABLED=false` lo apaga. Quitar el modulo de facturacion: quita tambien la dependencia de `ResolveTenant` en `SubscriptionAccessInterface`.

### Límites y features al cambiar de plan

- `PlanLimits` lee el plan de la suscripción con acceso (`trialing`/`active`/`past_due`); en solo lectura cae a `tenants.plan` o `plans.default`. Se aplica a **miembros** (`max_members`: miembros más invitaciones pendientes al invitar, y miembros al aceptar, con el tenant bloqueado), **API keys**, **almacenamiento** y **tamaño de archivo**.
- **Al bajar de plan no se borra nada.** Lo que excede el nuevo tope se conserva y se puede leer y borrar; solo se frena el alta nueva (403 `plan_limit_reached`) hasta volver dentro del tope. Los límites solo cuentan al crear.
- **Features por plan**: `config/plans.php` -> `plans.<plan>.features` (lista de claves de `config/features.php`, o `['*']`). Una feature que algun plan declara solo la tienen los tenants de esos planes; las que ningún plan declara dependen solo de la flag. `FeatureFlagService` (y `feature:` en rutas) lo respetan; la flag global apagada manda siempre y un override de plataforma por tenant/usuario concede la feature aunque el plan no la incluya.

## Ciclo de cobro: `billing:renew`

Programado cada 15 minutos (`routes/console.php`, `withoutOverlapping`). `RenewalService::due()` busca (sin filtro de tenant, auditado) las suscripciones con algo que
hacer y `renew()` procesa **cada una dentro de su tenant** (`TenantContext::run`):

1. Fin de periodo (`trialing`/`active`): si hay baja pendiente -> `canceled`; si el importe es 0 -> el periodo avanza sin factura; si no, emite la factura del nuevo periodo (con el precio programado si lo hay) y la cobra.
2. `past_due` con `next_retry_at` vencido: reintenta cobrar **la misma factura**.
3. `incomplete` más viejo que `incomplete_expire_hours`: `expired` y factura `void`.
4. Un cobro `pending` con el arrendamiento vencido (corte o error transitorio): se retoma con la misma clave, **también si la factura se cerró después** (baja, alta caducada): si la pasarela cobró, se reembolsa (factura `void`) o salda la deuda (factura `uncollectible` -> `paid`). Si la pasarela confirma que el intento original nunca llegó y la factura está `void`, **no se cobra** (falla `invoice_void`, sin aviso de pago fallido); sobre una `uncollectible` sí se cobra (es deuda).

**Calendario de morosidad** (`BILLING_DUNNING_RETRY_DAYS=1,3,5`): fallo 1 -> `past_due` y reintento a +1 día; fallo 2 -> +3; fallo 3 -> +5; fallo 4 -> se agota: factura `uncollectible` y la suscripción pasa a
`BILLING_DUNNING_FINAL_STATUS` (`canceled` o `expired`). Un reintento que cobra devuelve la suscripción a `active` con el periodo de esa factura. Un reintento manual del cliente que falla **no** consume reintentos.

### Atomicidad e idempotencia (por qué no hay doble cobro ni dos facturas)

| Riesgo | Defensa |
|---|---|
| Dos `billing:renew` sobre la misma suscripción | `SELECT ... FOR UPDATE SKIP LOCKED` en la fase 1: el segundo la salta (`locked`) |
| Dos facturas del mismo periodo | índice único parcial `invoices_one_per_period` (subscription_id, period_start); si existe, se reutiliza |
| Dos cobros de la misma factura | fila `payments` `pending` reservada **antes** de llamar a la pasarela, con arrendamiento (`lease_seconds`); un segundo proceso la ve y no cobra (`in_progress`). Índices únicos: una `pending` y una `succeeded` por factura |
| Proceso que muere tras cobrar | el pago queda `pending`; al vencer el arrendamiento se retoma con **la misma clave** `inv-<factura>-a<intento>` y la pasarela devuelve el resultado original |
| Error transitorio (timeout, 5xx, 429) | el intento sigue `pending`, se reintenta con la misma clave tras `transient_backoff_seconds`; agotados `max_transient_tries`, la pasada SIGUIENTE (sin enviar nada) consulta la pasarela y solo si confirma que no existe lo da por fallido (`gateway_unavailable`) y entra en la morosidad |
| Bloqueos durante la red | el cobro se hace **fuera de toda transacción**; reservar y registrar son transacciones cortas |
| Interbloqueos | orden fijo de bloqueos en todo el módulo: suscripción -> factura -> pago |
| Dos altas a la vez del mismo tenant | bloqueo de la fila del tenant + índice único parcial `subscriptions_one_live_per_tenant` |
| Número de factura repetido o con hueco | `SequenceInterface` (fila bloqueada) dentro de la transacción de la factura |
| Cliente duplicado en la pasarela | clave de idempotencia estable por tenant + `insertOrIgnore` sobre `billing_accounts.tenant_id` único |

Pruebas con procesos reales en `tests/Concurrency/FacturacionConcurrenteTest.php`.

## Cobros que vuelven con la factura ya cerrada

- Factura **`void`** (baja inmediata o alta caducada mientras la pasarela cobraba, o ajuste de plan que ya no aplica porque la suscripción renovó entre medias): el cobro **no cuenta**. La operación síncrona (alta, cambio de plan, `/pay`) responde 409 `invoice_not_open` con `refund: refunded|pending` y la suscripción no se activa ni cambia.
- Factura **`uncollectible`** (baja inmediata o morosidad agotada con un reintento en vuelo): la deuda quedó cobrada, así que pasa a `paid` (como un pago manual tardío); una suscripción terminada no revive.

### Reembolso automático

Si una factura queda `void` teniendo un cobro `succeeded` de la pasarela, `PaymentProcessor::refund()` devuelve al momento **lo que queda por devolver** (importe menos `refunded_amount_minor`, por si ya se devolvió una parte en Stripe) con clave
de idempotencia `refund-<id del pago>`, pasa el pago a `refunded` (`refunded_at`, `refund_id`) y audita `billing.payment-refunded`. Si la pasarela falla (transitorio), el pago queda `succeeded` y `billing:renew` lo reintenta en cada ejecución con **la misma clave**
(`orphanedPayments()`), también para tenants no activos. Los pagos manuales nunca se reembolsan por la pasarela.

## Tenants no activos

`billing:renew` **no factura a tenants suspendidos o pendientes**: los omite, lo cuenta en la salida (`skipped_inactive_tenant`) y lo registra en el log de seguridad. `BILLING_RENEW_INACTIVE_TENANTS=true` (`billing.renew_inactive_tenants`) lo desactiva.

## Alcance de la numeración (`tenant` / `global`)

Cada factura guarda el alcance con el que se numeró (`invoices.number_scope`). **No se puede cambiar `BILLING_INVOICE_SEQUENCE_SCOPE` con facturas ya emitidas**: repetiría números (`unique (tenant_id, number)`) o mezclaría series. Al emitir lanza 409
`invoice_numbering_scope_changed` y `billing:renew` se niega a arrancar (FAILURE) con el mismo mensaje. Decídelo antes de la primera factura; si de verdad hay que migrar, hazlo con una migración explícita y auditada de `sequences` y `number_scope`.

## Pasarela: `PaymentGatewayInterface`

Crear cliente, adjuntar método de pago (por **token** que la pasarela da al front; nunca datos de tarjeta), cobrar con clave de idempotencia, reembolsar y consultar. Un **rechazo del banco no es una excepción**: vuelve en `ChargeResult(succeeded: false, failureCode)`.
Los fallos de infraestructura son los errores tipados de integraciones (`docs/integrations.md` §4): `Transient`/`RateLimited` se reintentan con la misma clave; el resto no.

`PAYMENTS_MODE` (`config/integrations.php`): `fake` (por defecto), `null` o `real`.

- **`FakePaymentGateway`**: determinista, sin red ni reloj ni estado estático (binding `scoped`), registra llamadas (`calls('charge')`, `charges()`). Tokens: `tok_visa` y cualquier `tok_*` cobran; `tok_declined`, `tok_insufficient_funds` y
  `tok_expired` son rechazados; `declineNext('card_declined', times: 2)` fuerza rechazos; `failNext(TransientIntegrationError::class)` simula una caída. Misma clave de idempotencia = mismo resultado sin repetir el cobro.
- **`NullPaymentGateway`**: sin pasarela, solo pago manual. Todo cobro falla con un error permanente claro; las renovaciones caen en morosidad y un admin las recupera con el pago manual.
- **`real`**: la pasarela de `PAYMENTS_PROVIDER` (hoy `stripe`, §Stripe); un proveedor desconocido falla claro al resolverla.

### Añadir una pasarela (p. ej. Stripe, ola 6.2)

1. Clase en `app/Integrations/Payments/` que implemente `PaymentGatewayInterface`: usa `IntegrationHttp::client('payments')` (timeout explícito), traduce los errores a los cinco tipos y devuelve un rechazo del banco como `ChargeResult(false, ...)`. Pasa `amountMinor` tal cual (Stripe usa unidades menores) y la `idempotencyKey` como cabecera.
2. Credenciales en `config/services.php` / `config/integrations.php` (+ `.env.example`), nunca con `env()` en el servicio.
3. Sustituye la fábrica `real` de `IntegrationsServiceProvider` (`bindByMode(PaymentGatewayInterface::class, 'payments', [...])`).
4. Webhook entrante del proveedor registrado en `integrations.inbound_webhooks.providers` (`docs/integrations.md` §7), con un handler **idempotente** que aplique el estado del pago (la verdad la da el webhook, no solo la respuesta síncrona).
5. Tests con `Http::fake` para cada código HTTP -> error tipado y para el rechazo del banco.

## Stripe

`PAYMENTS_MODE=real` + `PAYMENTS_PROVIDER=stripe` + `STRIPE_SECRET` (+ `STRIPE_WEBHOOK_SECRET`, `STRIPE_API_VERSION` opcional; credenciales en `config/services.php`, nunca en el repo ni en logs: `Redactor` enmascara `sk_/rk_/whsec_` y los `client_secret`). `StripePaymentGateway` habla HTTP con `IntegrationHttp` (sin SDK: son seis llamadas, y así `Http::fake` cubre todo sin red).

- **Método de pago**: el front crea un `PaymentMethod` con Stripe.js y manda su id `pm_...` como `token` (`PUT /tenant/billing/payment-method`, o `payment_token` al contratar); se adjunta al cliente. Un `tok_...` o cualquier otra cosa da error permanente.
- **Cobro**: `PaymentIntent` creado y confirmado en una llamada, con `Idempotency-Key` = clave del intento (`inv-<factura>-a<n>`) y la misma clave en `metadata.idempotency_key` (es lo que permite encontrarlo luego). Sin cliente delante (renovaciones) va `off_session`; con él (alta, cambio de plan, `/pay`) no.
- **Errores**: HTTP 402 `card_error` = rechazo del banco, resultado `ChargeResult(false, failureCode = decline_code)`, no excepción; 429 -> `RateLimitedError`; 5xx, 408 y 409 (clave en uso) -> `Transient`; resto de 4xx (incluida `idempotency_error`) -> `Permanent`. Los mensajes no incluyen el cuerpo de Stripe.
- **3DS/SCA**: con el cliente delante, si el banco lo pide el intento pasa a **`requires_action`** (cuenta como intento en curso: índice único, no se abre otro) y la API responde **402 `payment_requires_action`** con `client_secret` y `payment_intent_id` en el error. El front llama a `stripe.confirmCardPayment(client_secret)`; el resultado llega por webhook (`payment_intent.succeeded` activa la suscripción; `payment_failed` falla el intento y deja la factura del alta abierta para reintentar). `POST /tenant/billing/invoices/{id}/pay` devuelve de nuevo el mismo `client_secret` (consulta el PaymentIntent, no cobra otra vez). El cliente tiene `BILLING_REQUIRES_ACTION_HOURS` (24); pasado, `payments:reconcile` cancela el PaymentIntent y el intento falla con `action_expired`. Una renovación `off_session` que exige 3DS se rechaza con `authentication_required` y entra en la morosidad; el cliente la resuelve con «pagar ahora» (on-session).
- **3DS fallido**: cuando llega `payment_intent.payment_failed` de un intento `requires_action`, el PaymentIntent se **cancela en Stripe** antes de marcarlo fallido. Si no, el front (que tiene su `client_secret`) podría volver a confirmarlo con otra tarjeta mientras la morosidad o «pagar ahora» abren otro intento: doble cobro. Si Stripe ya lo había cobrado, se aplica el cobro; si no responde, el webhook se reintenta y el intento sigue `requires_action`.
- **Plan asignado por la plataforma**: cobro `off_session` (el titular no está delante): nunca se pide 3DS ni se entrega un `client_secret` al admin; si la tarjeta exige 3DS se rechaza (`authentication_required`).
- **Monedas**: Stripe usa la unidad menor ISO salvo **ISK y UGX**, que exige con dos decimales `00` (5 ISK = `500`): el adaptador multiplica/divide por 100 al enviar y al leer (cobros, PaymentIntents, `amount_refunded`). En las monedas de tres decimales (BHD, JOD, KWD, OMR, TND) Stripe exige un importe múltiplo de 10: si no lo es, el cobro falla con error permanente antes de enviarse.
- **Parámetros fijos por intento**: `payments.customer_present` se guarda al crear el intento, porque reenviar la misma clave con otros parámetros hace que Stripe responda `idempotency_error`.

### Webhook `POST /webhooks/inbound/stripe`

Por el receptor genérico (`docs/integrations.md` §7): firma `Stripe-Signature: t=...,v1=...` (HMAC-SHA256 de `t.cuerpo`, `hash_equals` contra todos los `v1`, ventana `STRIPE_WEBHOOK_TOLERANCE` 300 s evaluada solo con firma válida), **rotación** con `STRIPE_WEBHOOK_SECRET=whsec_nuevo,whsec_viejo`, recibo único por `event.id`, respuesta rápida y proceso en cola. `StripeEventTenantResolver` decide la empresa **solo con datos locales** (pago por PaymentIntent o clave, o cuenta por `customer`); lo que no es nuestro se ignora. Eventos: `payment_intent.succeeded|payment_failed`, `charge.refunded` (importe acumulado, monótono) y `charge.dispute.created`; cualquier otro tipo se acepta y se ignora.

El `client_secret` del PaymentIntent no se guarda en el recibo (`strip_payload`). Un reembolso o una disputa de **otro** PaymentIntent con la misma clave (el duplicado de una `duplicate_charge`) no toca el pago local: queda como discrepancia.

**Idempotente y sin orden**: webhook y respuesta síncrona pasan por la misma `PaymentProcessor::applyGatewayResult` (mismos bloqueos: suscripción -> factura -> pago). Si el webhook llega primero, aplica el éxito y la respuesta síncrona (o `billing:renew`) ve el pago ya resuelto; si llega después, no cambia nada; un `payment_failed` tardío no deshace un cobro exitoso; un reembolso o una disputa que llegan antes que el éxito fallan de forma reintentable (backoff del job) hasta que el cobro esté reflejado. Un importe, moneda o cliente que no cuadran con lo local **no se aplican** y quedan en la bandeja de discrepancias.

## Conciliación y doble cobro

**Riesgos cerrados**

1. *Las claves de Stripe caducan a las 24 h.* Reenviar la misma clave pasado ese plazo crea OTRO cobro. Por eso ya no se reenvía nunca a ciegas: en cada reintento de un intento con resultado incierto (`tries > 1`) se consulta primero la pasarela (`findCharge`: busca la clave en `metadata.idempotency_key` de los PaymentIntents del cliente; o `retrieve` si ya se conoce el id) y solo se reenvía si la pasarela **confirma que no existe**. Si la consulta falla, el cobro sigue `pending` (no se reenvía).
2. *Tras `max_transient_tries` el cobro ya no se da por fallido a ciegas* (abrir entonces el intento `a+1` con otra clave podía cobrar dos veces si el primero sí llegó). Se confirma en la pasarela en la pasada siguiente, tras el backoff y **sin reenviar** (consultar justo después de un envío con timeout no prueba nada: Stripe puede seguir procesándolo): existe -> se aplica su resultado; confirmado que no existe -> `gateway_unavailable` y morosidad; no se pudo confirmar -> sigue `pending` y se reintenta.
3. *Cobros de Stripe sin reflejo local*, dobles cobros, reembolsos y disputas externos: se detectan y registran, nunca se «arreglan» solos.

**`payments:reconcile`** (cada 10 min, `withoutOverlapping`; seguro en paralelo y en cualquier orden con `billing:renew`). Por cada tenant afectado, dentro de su contexto:

| Fase | Qué hace | Contador |
|---|---|---|
| Inciertos | Pagos `pending` con arrendamiento vencido hace más de `BILLING_RECONCILE_STALE_MINUTES` (10): consulta por id/clave y aplica el resultado. Si no existe, lo deja a `billing:renew` (reenvío seguro: ya está confirmado) | `stale_*` |
| 3DS vencidos | `requires_action` pasados `BILLING_REQUIRES_ACTION_HOURS` (o con la factura ya cerrada): cancela el PaymentIntent y falla el intento `action_expired`; si Stripe dice que en realidad se cobró, se respeta el cobro | `action_*` |
| Cobros recientes | Lista los PaymentIntents de las últimas `BILLING_RECONCILE_LOOKBACK_HOURS` (48) con nuestra clave y estado `succeeded` y los aplica: pendiente -> cobrado; sin intento local -> discrepancia. El tenant sale del pago local o, si no hay, de `metadata.tenant_id` **solo si el `customer` coincide** con la cuenta de esa empresa | `recent_*`, `foreign_charge`, `list_failed` |

**Discrepancias** (`payment_discrepancies`, única por pasarela + referencia + tipo; alerta una sola vez en el log `security`): `succeeded_without_local`, `duplicate_charge` (otro PaymentIntent cobrado para el mismo intento), `succeeded_after_failure`, `data_mismatch`, `refunded_externally`, `dispute`. Plataforma (`billing.manage`): `GET /admin/billing/discrepancies?status=&tenant_id=` y `POST /admin/billing/discrepancies/{id}/resolve` `{note}` (auditado `billing.discrepancy-resolved`). No se reembolsa ni cancela nada automáticamente por una discrepancia: decide una persona.

Límites: la conciliación no lista reembolsos ni disputas de Stripe (dependen del webhook; el recibo fallido se reintenta con backoff y `webhooks:inbound-sweep` reencola los atascados); un cliente de Stripe huérfano (si `createCustomer` tuvo éxito y la fila local falló más de 24 h después) es inofensivo (no cobra).

## Eventos de dominio

Se despachan **después del commit** (`ShouldDispatchAfterCommit`), con solo ids y escalares (seguros para encolar; el listener abre el tenant con `TenantContext::run`):
`SubscriptionStarted`, `SubscriptionRenewed`, `SubscriptionPlanChanged`, `SubscriptionCanceled` (`status` `canceled|expired`, `reason` `user|period_end|dunning_exhausted|incomplete_timeout`), `InvoiceIssued`, `InvoicePaid` (`method` `gateway|manual`) y `PaymentFailed` (`attempt`, `nextRetryAt`).
Las olas siguientes (bloqueo 6.2-B; los avisos 6.2-C ya escuchan `InvoicePaid`, `PaymentFailed` y `SubscriptionCanceled`) se enganchan aquí sin tocar el módulo.

## Avisos de facturación

`BillingNoticeService` (escucha `InvoicePaid`, `PaymentFailed` y `SubscriptionCanceled` vía `SendBillingNotices`, y el comando `billing:notify`, cada hora) avisa por `NotificationServiceInterface::notify` **solo a dueños y administradores del tenant** (`billing.notices.recipient_roles`, membresía activa), nunca a todos los miembros.

| Tipo | Cuándo | Obligatorio |
|---|---|---|
| `billing_trial_ending` | `billing:notify`: la prueba termina en <= `BILLING_NOTICE_TRIAL_DAYS` (3) | no |
| `billing_renewal_upcoming` | `billing:notify`: renovación en <= `BILLING_NOTICE_RENEWAL_DAYS` (3); solo `active`, de pago y sin baja programada | no |
| `billing_invoice_paid` | `InvoicePaid` (gasto > 0), con enlace a la factura | no |
| `billing_payment_failed` | `PaymentFailed` de una renovación con reintentos pendientes (enlace para pagar) | si |
| `billing_final_warning` | el fallo tras el que solo queda el último reintento | si |
| `billing_subscription_canceled` / `billing_subscription_expired` | `SubscriptionCanceled` (`status` `canceled`/`expired`; incluye reintentos agotados) | si |

Idempotencia: la tabla `billing_notices` tiene UNIQUE (`subscription_id`, `type`, `period_key`); el aviso se **reclama** con `insertOrIgnore` y se encola en la misma transacción (si encolar falla, el reclamo se revierte). Claves: `trial:<fin>`, `renewal:<fin de periodo>`, `paid:inv<id>`, `failed:inv<id>:a<intento>`, `final:inv<id>`, `ended:<estado>`. Sin destinatarios no se reclama nada. Los fallos de aviso se registran y **no** se propagan al cobro. El barrido omite tenants no activos. Los fallos del primer pago y de ajustes de plan no generan aviso de morosidad (el cliente ya recibe el error en la petición). Enlaces del front: `billing.notices.*_url`.

## Auditoría

Todo cambio queda en `audit_logs` por tenant: `billing.subscription-started`, `billing.subscription-status-changed` (de/a/causa), `billing.subscription-plan-changed`, `billing.change-scheduled`, `billing.scheduled-change-canceled`,
`billing.cancel-scheduled`, `billing.subscription-resumed`, `billing.invoice-issued`, `billing.manual-payment` y `billing.free-period` (estas dos con motivo obligatorio), `billing.stale-upgrade-payment`, `billing.payment-refunded`, `billing.payment-refunded-externally`, `billing.payment-disputed`, `billing.discrepancy-resolved`.

## Errores con `code` estable

`plan_not_found` 404 · `price_unavailable` 422 · `subscription_exists` 409 · `subscription_not_found` 404 · `subscription_not_active` 409 · `subscription_not_resumable` 409 · `invalid_subscription_transition` 409 ·
`plan_private` 403 · `payment_method_required` 422 · `payment_failed` 402 (con `failure_code`) · `payment_pending` 409 · `payment_requires_action` 402 (con `client_secret`) · `payment_in_progress` 409 · `change_in_progress` 409 · `same_plan` 422 · `invoice_not_open` 409 · `invoice_immutable` 409 · `invoice_numbering_scope_changed` 409 · `invalid_free_period` 422 · `not_found` 404 (factura).

## Límites conocidos

- **Notas de crédito** no existen. El reembolso automático es total (factura anulada); un reembolso parcial o total hecho en Stripe se registra (`payments.refunded_amount_minor`, discrepancia `refunded_externally`) pero la factura y la suscripción no se tocan solas.
- Un ajuste por subida de plan cobrado por la pasarela cuando la suscripción ya cambió (una renovación entre medias) no aplica el cambio: la factura se anula, el cobro se reembolsa y queda `billing.stale-upgrade-payment` en la auditoría. Si ese ajuste se paga A MANO, solo queda la auditoría (revisión humana).
- Sin lógica fiscal de país: un solo porcentaje sobre el subtotal; el PDF es un documento simple (sin datos fiscales del cliente ni logo remoto).
- Cantidades por asiento y precios por uso no existen: una suscripción = un precio fijo por periodo.
- `TENANT_TRIAL_DAYS` (`tenants.trial_ends_at`) sigue siendo un dato informativo del alta; la prueba que cobra es la del plan.
