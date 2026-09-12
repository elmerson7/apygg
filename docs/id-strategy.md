# Estrategia de IDs: auto-incremental vs UUID

## Estado actual del boilerplate

El `Model` base (`app/Models/Model.php`) usa **ID auto-incremental** (`int`) con
soft deletes. El trait `App\Traits\HasUuid` está disponible para los modelos que
deban exponer un identificador no adivinable.

```php
// Modelo con ID entero (por defecto)
class Report extends Model
{
    // hereda $incrementing = true, $keyType = 'int'
}

// Modelo con UUID como primary key
class ApiKey extends Model
{
    use HasUuid;   // setIncrementing(false) + keyType 'string' + generación automática
}
```

Si decides que tu proyecto sea UUID-first, cambia el `Model` base y usa
`$table->uuid('id')->primary()` en las migraciones. Lo importante es **elegir una
política y aplicarla de forma consistente**, no mezclarlas por descuido.

---

## Criterios de decisión

### Usa UUID cuando

- El identificador viaja en URLs públicas o se comparte con terceros.
- La enumeración secuencial es un riesgo (`/users/1`, `/users/2`… permite medir
  cuántos usuarios tienes y recorrerlos).
- Los registros se crean en varios sitios y luego se sincronizan.
- Se generan enlaces públicos, tokens o recursos compartibles.

**Ejemplos típicos**: `users`, `api_keys`, `webhooks`, `files`, `orders`,
`invoices`, recursos expuestos en la API pública.

### Usa ID auto-incremental cuando

- La tabla no se expone directamente por API.
- El volumen es alto y el orden de inserción importa (los logs se ordenan y
  paginan por `id` de forma mucho más barata que por UUID).
- Es una tabla pivot: solo contiene claves foráneas.
- Es configuración interna, métricas o agregados.

**Ejemplos típicos**: `logs_api`, `logs_security`, `logs_activity`, tablas pivot
de Spatie (`model_has_roles`, `role_has_permissions`), `settings`, tablas de
estadísticas.

### Evita ID + UUID en la misma tabla

Mantener las dos columnas duplica el trabajo: hay que decidir en cada consulta
cuál usar, cada relación debe elegir una, y tarde o temprano alguien filtra la
equivocada. Solo se justifica si integras con un sistema legacy que exige IDs
numéricos y a la vez expones el recurso públicamente.

### No toques las tablas nativas de Laravel

`cache`, `jobs`, `failed_jobs`, `sessions`, `migrations`,
`password_reset_tokens` conservan su esquema original.

> **Excepción habitual**: si `users.id` es UUID, la columna `sessions.user_id`
> debe pasar de `foreignId` (bigint) a `uuid` para mantener la integridad
> referencial. Documenta el cambio en la propia migración.

---

## Notas prácticas

1. **Ordenamiento**: con UUID, ordena siempre por `created_at`, nunca por el
   UUID. Un UUIDv4 no tiene orden temporal.
2. **Rendimiento**: PostgreSQL maneja UUID de forma eficiente con el tipo nativo
   `uuid` (16 bytes). Guardarlo como `varchar(36)` cuesta el doble de espacio y
   de índice: usa `$table->uuid()`.
3. **Generación**: `HasUuid` genera el UUID en PHP al crear el modelo. Si
   prefieres delegarlo a la base, usa `DEFAULT gen_random_uuid()` en la
   migración — pero entonces recuerda que el modelo no conoce el ID hasta
   refrescarlo.
4. **No optimices antes de medir**: la elección entre int y UUID rara vez es el
   cuello de botella real. Las consultas sin índice sí lo son.

---

## Referencias

- [PostgreSQL — tipo UUID](https://www.postgresql.org/docs/current/datatype-uuid.html)
- [Laravel — UUID and ULID keys](https://laravel.com/docs/eloquent#uuid-and-ulid-keys)
- `app/Traits/HasUuid.php`
- `app/Models/Model.php`
