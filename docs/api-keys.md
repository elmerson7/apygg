# API Keys (máquina a máquina)

Gestión por el usuario con JWT en `/api-keys`. Uso por la integración:

```php
Route::middleware(['auth:api-key', 'api-key-scope:profile.read'])->get('/api-key/whoami', ...);
```

- `auth:api-key` es el middleware estándar `auth` con el guard `api-key` (`config/auth.php`, `Auth::viaRequest` en `AuthServiceProvider`). La key va en `X-API-Key` o `Authorization: Bearer`.
- `api-key-scope:` **falla en cerrado**: sin API Key da 401; una key **sin scopes no accede a nada**; el acceso total es el scope explícito `*`.
- Las API Keys autorizan solo por scopes, no por permisos RBAC.
- Se guardan con SHA-256; la key completa solo se muestra al crearla o rotarla.
- Una key revocada o caducada deja de valer en el acto. Rotar una caducada se rechaza (422); la antigua sigue 7 días como máximo y nunca más allá de su propia caducidad.
- Las keys `test` no se crean ni se aceptan en producción (`API_KEYS_ALLOW_TEST_IN_PRODUCTION`).
- Ejemplo mínimo probado: `GET /api-key/whoami` (scope `profile.read`).
