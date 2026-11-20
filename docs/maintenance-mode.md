# Modo mantenimiento de la API

Responde **503 RFC 7807** con `code: maintenance` y cabecera `Retry-After` a todo el tráfico de la API salvo lo permitido.

## Por qué no `php artisan down`

`artisan down` escribe `storage/framework/down` en el disco **local**: con varias réplicas hay que ejecutarlo en
cada una, Octane/FrankenPHP mantiene la app en memoria y la respuesta no es el formato de errores de la API. Aquí el
estado vive en la **caché compartida** (Redis en producción): un solo `api:down`/`POST` lo activa en todas las réplicas
y workers a la vez, y caduca solo si hay fin estimado.

## Activar y desactivar

| Vía | Cómo |
|---|---|
| Comando | `make art cmd="api:down --message='Migrando BD' --until=2026-12-01T10:00:00Z"` / `api:up` |
| Endpoint | `POST /admin/maintenance` `{message?, until?}`, `DELETE /admin/maintenance`, `GET /admin/maintenance` (permiso `maintenance.manage`) |
| Entorno | `MAINTENANCE_ENABLED=true` (+ `MAINTENANCE_MESSAGE`, `MAINTENANCE_UNTIL`): gana sobre la caché y no se apaga desde la API (`api:up` avisa) |

Activar y desactivar quedan auditados (`maintenance.enable|disable`). `Retry-After` = segundos hasta `until`, o
`MAINTENANCE_RETRY_AFTER` (60) si no hay fin. Con `until`, la entrada caduca 5 min después por si nadie la apaga.
`MAINTENANCE_CACHE_STORE` elige el store (por defecto el de la app; **debe ser compartido**: no `array` ni `file` local).

## Qué pasa siempre (`config/maintenance.php`)

- `allow_paths`: `health`, `health/*`, `webhooks/inbound/*`, `webhooks/mail` (los proveedores no deben perder eventos).
  `/up` de Laravel queda fuera del grupo `api` y siempre responde.
- `allow_ips` (`MAINTENANCE_ALLOW_IPS`, IPs o CIDR): para probar la API y poder hacer login mientras tanto.
- `allow_roles` (`MAINTENANCE_ALLOW_ROLES`, `admin`): quien ya traiga un token válido con ese rol sigue usando la API,
  incluidos los endpoints de `/admin/maintenance` para apagarlo.

El login **no** está exento: un admin sin sesión debe entrar desde una IP permitida. Rutas web (back-office, Horizon)
no pasan por el middleware (grupo `api`).
