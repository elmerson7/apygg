# Cliente de pruebas (frontend-demo)

Una app web **pequeña** que demuestra y prueba la API desde un cliente real: Vite + React + TypeScript con
**tipos generados desde `docs/openapi.json`** y recorridos **Playwright** en escritorio y móvil. No es un
producto: sirve para comprobar que el contrato, los errores y los flujos de negocio funcionan vistos desde un
navegador, y como ejemplo de cómo consumir la API con `openapi-fetch`.

Módulo **opcional y quitable** (`modules/frontend-demo.php`, sin dependencias del núcleo PHP): se quita con
`make module-remove name=frontend-demo` (borra `frontend-demo/`, `make/frontend.mk`, `scripts/frontend-e2e.sh` y
esta doc). Node **nunca** corre en el host: todo va en contenedores efímeros de versión fija.

## Comandos

| Comando | Qué hace |
|---|---|
| `make frontend-install` | `npm ci` en `frontend-demo/node_modules` (fuera de git), imagen `node:22-bookworm-slim` |
| `make frontend-types` | regenera `src/api/schema.d.ts` desde `docs/openapi.json` (misma versión de `openapi-typescript` que `make types`) |
| `make frontend-test` | typecheck (app y Playwright) + tests unitarios (Vitest) |
| `make frontend-build` | typecheck + build de producción en `frontend-demo/dist` |
| `make frontend-dev` | servidor de desarrollo en `http://localhost:5173` (`VITE_API_URL=http://localhost:8000` por defecto) |
| `make frontend-e2e` | Playwright (escritorio y móvil) contra un **stack temporal propio**; lo baja al terminar |

`make frontend-dev VITE_API_URL=http://localhost:8010` cambia la API de destino. El navegador siempre habla con
`/api` (mismo origen) y el proxy de Vite (`vite.config.ts`, también en `preview`) lo reenvía a la API **quitando el
prefijo** (la API no usa `/api`): así no hace falta CORS en desarrollo ni en las pruebas. En un despliegue real,
define `VITE_API_BASE` con la URL absoluta de la API (y configura CORS: `docs/cors-configuration.md`).

Los tipos se **regeneran** cada vez que cambia el contrato (`make openapi` -> `make frontend-types`); si una ruta
cambia de forma, `tsc` falla en la pantalla afectada: es la gracia de tener el cliente tipado.

## Pantallas

| Ruta | Qué muestra | Endpoints |
|---|---|---|
| `/login` | login, registro y desafío 2FA (TOTP o código de recuperación) | `/auth/login`, `/auth/register`, `/auth/2fa/verify` |
| `/empresas` | mis empresas, crear una (alta libre), elegir la activa (`X-Tenant`) | `/tenants/mine`, `/tenants` |
| `/miembros` | miembros (cambiar rol, quitar), invitar con `Idempotency-Key`, reenviar/revocar | `/tenant/members`, `/tenant/invitations` |
| `/roles` | roles de la empresa (con su recuento) y roles globales con sus permisos (403 si falta `roles.read`) | `/tenant/members`, `/roles`, `/roles/{id}/permissions` |
| `/archivos` | subida **directa presignada** (intención -> PUT al bucket -> completar), descarga autenticada, borrado, cuota | `/files/uploads`, `/files/uploads/{uuid}/complete`, `/files`, `/files/usage` |
| `/notificaciones` | bandeja con **sondeo** cada 10 s (insignia de no leídas en la cabecera), marcar leída/todo, borrar | `/user/notifications*` |
| `/suscripcion` | planes, contratar (pasarela simulada), tarjeta de prueba, facturas, PDF, pagar, baja, estado de bloqueo | `/plans`, `/tenant/billing/*` |
| `/seguridad` | sesiones (cerrar una o las demás) y 2FA (activar con secreto TOTP, códigos de recuperación, desactivar) | `/user/sessions`, `/user/2fa*` |

Las notificaciones en vivo con Reverb **no** están: el sondeo es suficiente para la demo y evita levantar el
servicio de WebSockets en las pruebas (`docs/websockets.md` explica cómo conectarlo).

### Cómo maneja los errores

`src/api/errors.ts` convierte cualquier error en un `ApiError` con `status`, `code` (estable: se ramifica por él, no
por el texto), `request_id` (lo que se le da a soporte), `fields` (los `errors` de un 422) y `suggestedAction` (la
acción sugerida de un 402). Toda pantalla pinta el aviso con `ErrorNotice`: mensaje + `code` + `request_id`.

- **Refresh rotado** (`src/api/client.ts`): el `fetch` del cliente renueva el access caducado **una vez** por
  petición y con **un solo refresh en vuelo** (varias peticiones esperan el mismo). Si el refresh responde
  `refresh_superseded` (otra pestaña ya rotó el token, `docs/sessions-and-2fa.md`), **no cierra la sesión**: relee
  los tokens que guardó la otra pestaña (`storage`) y reintenta. Cualquier otro fallo del refresh sí la cierra.
- **Bloqueo por suscripción** (`docs/billing.md`): un 402 (`payment_required`, `subscription_inactive`...) muestra
  una banda roja con el código y enlace a «Suscripción»; en la gracia por impago, la cabecera `X-Subscription-Warning`
  muestra una banda amarilla. La banda se retira con la siguiente escritura correcta.
- **Empresa activa**: `X-Tenant` (slug) se añade a las rutas de empresa y no a las de auth, alta de empresas, perfil ni catálogo.

El token vive en `localStorage` (es una demo): un front de producción preferirá memoria + cookie `httpOnly` o el
almacenamiento seguro del dispositivo.

## Tests

- **Unitarios (Vitest + jsdom)**, `src/**/*.test.ts`: parseo de errores RFC 7807 / 422 / 402, formato de dinero,
  SHA-256 y claves de idempotencia, y el cliente (cabeceras, refresh único, `refresh_superseded`, 402).
- **Playwright** (`frontend-demo/e2e/`, `@playwright/test` fijado y la imagen oficial `mcr.microsoft.com/playwright:v<misma versión>-noble`):

| Archivo | Recorrido |
|---|---|
| `auth.spec.ts` | login erróneo con `code`/`request_id`, registro y salida, contraseña débil (error por campo), 2FA con desafío TOTP, sesiones y revocación |
| `equipo.spec.ts` | crear empresa, **invitar** (correo real a Mailpit por la cola) y aceptar, **cambiar de empresa**, aislamiento A/B, 403 de un miembro raso, roles |
| `archivos.spec.ts` | subida presignada al S3 local, descarga idéntica, borrado; tipo declarado falso rechazado (`upload_mime_mismatch`) |
| `facturacion.spec.ts` | contratar el plan simulado (prueba), baja inmediata (402 `subscription_inactive`), recontratar, **factura** y **PDF** |
| `dunning.spec.ts` | **impago**: aviso en la bandeja, 402 `payment_required` con banda visible, nueva tarjeta, pago de la factura y desbloqueo |
| `prepare.spec.ts` | fase previa del impago (solo API; ver abajo) |

Cada proyecto (`desktop` 1280x800 y `mobile` Pixel 7) corre los mismos recorridos con datos **únicos por
ejecución** (repetible sin limpiar). En un fallo quedan capturas y trazas en `frontend-demo/test-results/` y el
informe HTML en `frontend-demo/playwright-report/` (fuera de git).

### Cómo corre `make frontend-e2e` (`scripts/frontend-e2e.sh`)

1. Node efímero: `npm ci` (si falta), tipos y `npm run build` (typecheck + Vite).
2. Stack temporal con `scripts/e2e-stack.sh up` (**el mismo** `docker-compose.e2e.yml` de `make e2e`: API Octane,
   Horizon, Postgres, Redis, Mailpit y S3 local; pasarela `fake`; sin puertos en el host; ~1,7 GB). Reutiliza la
   imagen local `apygg-app:latest` si existe.
3. Playwright en contenedor (768 MB, en la red del stack) sirve el build con `vite preview` y su proxy a `http://app:8000`.
4. **Fase 1** (`prepare`, solo API): una empresa con suscripción en prueba y la tarjeta `tok_declined`.
   El script adelanta 15 días las fechas de esas empresas (SQL, igual que `e2eViajar()` de `tests/E2E/helpers.php`)
   y lanza `billing:renew` (cobro rechazado -> `past_due`), y otros 8 días (pasada la gracia -> escritura bloqueada).
   Se hace fuera del navegador porque el reloj de la BD y el cron no se alcanzan desde la UI.
5. **Fase 2**: recorridos de escritorio y móvil.
6. `down -v` al salir (también si falla): no deja contenedores, red ni volúmenes.

Variables: `E2E_PROJECT` (nombre único del stack), `FRONTEND_E2E_LOCK=<archivo>` (candado `flock`: un solo stack a la
vez en servidores compartidos), `E2E_KEEP=1` (no bajar, para depurar), `ARGS="--grep invitar"` (filtros de Playwright).

> **Ojo**: el stack E2E relaja los límites de peticiones y el filtro de webhooks (`docker-compose.e2e.yml`); es solo
> para pruebas. La imagen de Playwright pesa ~3,7 GB en disco: `docker rmi mcr.microsoft.com/playwright:v<versión>-noble`
> si hace falta espacio (se vuelve a bajar sola).

## Añadir una pantalla

1. Crea `src/pages/MiPagina.tsx` con `useLoad`/`useAction` de `src/ui.tsx` y llamadas `call(api.GET('/ruta'))` (el tipado sale de `schema.d.ts`).
2. Registra la ruta en `src/App.tsx` y el enlace en la barra.
3. Añade un recorrido en `frontend-demo/e2e/<tema>.spec.ts` con `data-testid` para lo que se afirma.
4. `make frontend-test` y `make frontend-e2e`.

## Límites conocidos

- Scramble no infiere algunos cuerpos (p. ej. el `DELETE /user/2fa` lo documenta como query): el cliente lo manda en JSON sin tipado.
- Sin Reverb ni PWA, sin i18n (textos en español) ni librería de UI: CSS propio, accesible y responsive.
