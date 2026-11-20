# Privacidad (RGPD / LGPD)

Cuatro piezas, todas del **usuario como persona** (sin `tenant_id`: valen en todas sus empresas):
consentimientos versionados, exportación de datos (portabilidad), borrado de cuenta (supresión) y retención.
Configuración en `config/privacy.php`.

## Consentimientos versionados

- **Documentos** (`legal_documents`): `key` (`terms`, `privacy`, `marketing`...), `version`, `locale`, `title`, `body`,
  `effective_at` y hash del contenido. **Inmutables**: una corrección es una versión nueva (`privacy_immutable`). Una
  versión tiene una fila por idioma; publicar otro idioma de una versión existente hereda su `effective_at`.
  Vigente = la versión con `effective_at` más reciente que ya empezó. Idioma: el pedido, el de reserva
  (`app.fallback_locale`) o el primero disponible.
- **Consentimientos** (`user_consents`): registro de solo añadir (usuario, clave, versión, idioma, `accepted|withdrawn`,
  IP, user-agent, fecha). El estado actual es la última fila de cada clave. Aceptar la misma versión dos veces no
  duplica; solo vale la versión vigente (`409 consent_version_outdated`).
- **Obligatorios** (`PRIVACY_CONSENTS_REQUIRED`, por defecto `terms,privacy`): no se pueden retirar
  (`422 consent_mandatory`). Una versión nueva de un obligatorio deja al usuario con ese documento *pendiente*.
- **Exigirlos**: `PRIVACY_CONSENTS_ENFORCE=true` activa `EnsureLegalConsents` (grupo `api`): con pendientes, `409
  consent_required` con `pending: [{key, version, title}]`. Nunca bloquea `privacy.consents.except` (auth, `/legal`,
  `/user/consents`, `/user/data-export`, `/user/account-deletion`, health). Apagado por defecto.

| Ruta | Permiso |
|---|---|
| `GET /legal`, `GET /legal/{key}?locale=` | público (versión vigente) |
| `GET /user/consents` · `POST /user/consents` `{documents:[{key,version}]}` · `DELETE /user/consents/{key}` | sesión; cada uno lo suyo |
| `GET/POST /admin/legal-documents` | `legal-documents.read` / `legal-documents.manage` (la publicación se audita) |

## Exportación de datos

> No es `docs/export-import.md`: aquello exporta listados CSV/XLSX de un tenant (`data_exports`, `ExportService`); esto empaqueta los datos personales de UNA persona (`personal_data_exports`, `PersonalDataExport`, `PersonalDataExportService`). Tipos de notificación distintos: `export_ready` frente a `data_export_ready`.

`POST /user/data-export` (202) encola `GenerateDataExportJob`; reúne los datos con los **contribuidores**, aplica
`SecretStripper` (última barrera: claves `password`, `secret`, `token`, `key`, `2fa`, `recovery_code`...) y guarda un ZIP
(`<contribuidor>.json` + `manifest.json`) en el disco privado (`PRIVACY_EXPORT_DISK`). Avisa con
`NotificationServiceInterface` (`data_export_ready`, enlace firmado). Caduca a las `PRIVACY_EXPORT_TTL_HOURS` (72):
`privacy:purge` borra el ZIP.

- Idempotente: con una exportación en curso devuelve la misma; el job repetido sobre una lista no la rehace.
- Límite: una por `PRIVACY_EXPORT_MIN_INTERVAL_HOURS` (24) → `429 data_export_rate_limited` (`retry_after`); más `throttle`.
- `GET /user/data-export` (las suyas), `GET /user/data-export/{id}/link` (enlace nuevo; id ajeno = 404).
  `GET /user/data-export/{id}/download` es una ruta **firmada** (`signed`) que sirve solo la exportación del usuario de la
  firma; caducada = 410 `data_export_expired`.
- Solo datos **del propio usuario**: consultas por `user_id`, nunca por tenant; incluye todos sus tenants.

## Borrado de cuenta

1. `POST /user/account-deletion {password, second_factor?}` (202). Exige contraseña y, si el usuario tiene 2FA activa,
   un código válido (`TwoFactorDeletionSecondFactor`; el gancho es `DeletionSecondFactorInterface`). Programa el borrado
   a `PRIVACY_DELETION_GRACE_DAYS` (14) y avisa (`account_deletion_scheduled`, por si no fue él). Idempotente.
2. `DELETE /user/account-deletion` cancela durante la gracia (`404 deletion_not_pending` si no hay).
3. `privacy:process-deletions` (cada hora) encola `ExecuteAccountDeletionJob` por solicitud vencida. El job bloquea la
   solicitud, el usuario y sus tenants (`FOR UPDATE`) y, en **una transacción**, llama a `erase()` de cada contribuidor.
   Idempotente. Si falla definitivamente, la solicitud queda `failed`.

**Salvaguardas** (se comprueban al pedir y otra vez al ejecutar): `422 last_owner` (`tenant_ids`) si es el único dueño
activo de una empresa (transfiere la titularidad o elimínala), `422 last_admin` si es el último administrador. Si al
ejecutar ya no puede, la solicitud queda `failed` (`failure_reason`), no se borra nada y se avisa
(`account_deletion_blocked`).

**Qué hace `erase()`**: anonimiza la cuenta (nombre, correo `deleted-<id>@deleted.invalid`, contraseña aleatoria,
2FA, `token_version+1` que invalida todos los JWT) y la da de baja (soft delete); borra perfil, membresías, roles,
bandeja, preferencias, dispositivos, entregas, API keys, sesiones (revocadas antes) y subidas pendientes; archivos:
`PRIVACY_ERASE_FILES=delete` (fila y objeto) o `detach` (se conservan en el tenant sin autor). **Se conserva** lo que la
ley exige: la auditoría (el actor pasa a `null` y se borra la IP) y la prueba de consentimiento (versión y fecha; se
borran IP y navegador). Las acciones `privacy.*` se auditan.

## Contribuidores: cómo aporta un módulo

```php
class FacturasContributor implements PersonalDataContributor
{
    public function key(): string { return 'invoices'; }
    public function export(User $user): array { return ['invoices' => DB::table('invoices')->where('user_id', $user->id)->get([...])->all()]; }
    public function erase(User $user): void { /* anonimiza o borra; idempotente; sin transacciones propias */ }
}
// en un provider:
$this->app->tag([FacturasContributor::class], 'privacy.contributors');
```

Reglas: `export()` solo datos de ese usuario y **nunca secretos** (usa columnas explícitas, no `SELECT *`); `erase()`
corre dentro de la transacción del borrado (lo externo, como borrar objetos de S3, va en `DB::afterCommit`); el
contribuidor `profile` siempre se ejecuta el último. Núcleo: `profile`, `tenant_memberships`, `consents`,
`notifications`, `files`, `api_keys`, `sessions`, `audit`. Un módulo con datos personales que no añada el suyo
incumple la norma.

## Retención

`privacy:purge` (diario, 03:45) caduca exportaciones y borra registros de exportación y solicitudes de borrado
terminados (`PRIVACY_EXPORT_RECORDS_RETENTION_DAYS` 90, `PRIVACY_DELETION_REQUESTS_RETENTION_DAYS` 365; 0 = no purgar).
El resto ya tiene comando y **no se duplica**:

| Dato | Comando / ajuste |
|---|---|
| Auditoría | `audit:prune`, `AUDIT_RETENTION_DAYS` |
| Entregas, bandeja leída, dispositivos | `notifications:purge`, `config/notifications.php` |
| Logs técnicos | `logs:clean` |
| Historial de correo | `mail:logs-clean` |
| Subidas directas caducadas | `files:purge-intents` |
| Claves de idempotencia | `idempotency:clean` |

## Códigos de error

`consent_required` (409), `consent_version_outdated` (409), `consent_mandatory` (422), `legal_document_not_found` (404),
`data_export_rate_limited` (429), `data_export_not_ready` (409), `data_export_expired` (410),
`deletion_password_invalid` (422), `deletion_second_factor_invalid` (422), `deletion_not_pending` (404),
`last_owner` (422), `last_admin` (422), `privacy_immutable` (500).

## Límites conocidos

- El registro de usuarios no recoge consentimientos: el front llama a `POST /user/consents` tras registrarse.
- Los datos que el usuario introdujo en recursos compartidos de un tenant (p. ej. un pedido) no son suyos: cada módulo
  decide en su contribuidor si los anonimiza.
- Los logs técnicos y las copias de seguridad se rigen por su retención; un backup restaurado debe volver a ejecutar los
  borrados completados (`account_deletion_requests`).

## Pruebas

`tests/Feature/Privacy/` y `tests/Feature/Tenancy/Aislamiento/PrivacidadAislamientoTest.php`.
