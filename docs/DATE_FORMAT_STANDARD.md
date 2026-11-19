# Estándar de fechas y horas

## Objetivo

Un único formato para todas las fechas que entran y salen de la API. Sin esto,
cada módulo termina devolviendo un formato distinto y el cliente acaba con media
docena de parsers.

## Formato estándar

| Tipo | Formato | Ejemplo | Uso |
|------|---------|---------|-----|
| **Fecha y hora** | ISO 8601 | `2026-01-15T14:30:00+00:00` | Timestamps (`created_at`, `sent_at`) |
| **Fecha sin hora** | `Y-m-d` | `2026-01-15` | Fechas puras (`start_date`, `birth_date`) |

La API trabaja en **UTC** (`config/app.php` → `'timezone' => 'UTC'`). La
conversión a la zona del usuario es responsabilidad del cliente: ISO 8601 lleva
el offset incluido, por lo que el cliente no tiene que adivinarlo.

`BaseResource` ya serializa `created_at` y `updated_at` en ISO 8601 mediante
`toIso8601String()`; si tu Resource hereda de él, no tienes que hacer nada.

Formato de entrada aceptado: cualquier cosa que Laravel entienda como `date`
(ISO 8601 incluido). Para entradas estrictas, usa `date_format`.

---

## 1. Respuestas JSON (salida)

✅ Correcto:

```json
{
  "success": true,
  "data": {
    "id": 42,
    "name": "Informe mensual",
    "start_date": "2026-01-15",
    "created_at": "2026-01-10T08:30:00+00:00",
    "updated_at": "2026-01-12T16:45:00+00:00"
  }
}
```

❌ Incorrecto:

```json
{
  "start_date": "15/01/2026",            // formato local
  "created_at": "2026-01-10 08:30:00",   // sin zona horaria: ambiguo
  "updated_at": 1767000000                // epoch suelto
}
```

Si un consumidor externo exige otro formato (RFC 3339 estricto, epoch), se
convierte en la capa de integración. Nunca se cambia el formato de la API.

---

## 2. Validación de entrada

```php
$validated = $request->validate([
    'start_date' => 'nullable|date',
    'end_date' => 'nullable|date|after:start_date',
    'scheduled_at' => 'nullable|date_format:Y-m-d\TH:i:sP',
]);
```

`date` acepta cualquier formato que `strtotime` entienda; `date_format` es
estricto. Usa `date_format` cuando el formato exacto importe.

---

## 3. Modelos Eloquent

Declara siempre los casts: sin ellos el atributo llega al Resource como string y
cualquier `->toIso8601String()` revienta.

```php
class Report extends Model
{
    protected $casts = [
        'start_date' => 'date',
        'scheduled_at' => 'datetime',
    ];
}
```

El `Model` base (`app/Models/Model.php`) ya castea `created_at`, `updated_at` y
`deleted_at`.

---

## 4. Resources

```php
class ReportResource extends BaseResource
{
    public function toArray(Request $request): array
    {
        return [
            ...$this->getBaseFields(),          // id, created_at, updated_at en ISO 8601
            'name' => $this->name,
            'start_date' => $this->start_date?->format('Y-m-d'),
            'scheduled_at' => $this->scheduled_at?->toIso8601String(),
        ];
    }
}
```

Nota el `?->`: una fecha nullable sin el operador seguro es la causa número uno
de errores 500 en endpoints de listado.

---

## 5. Formateo fuera de la API

Para correos, PDFs o exportaciones (donde el destinatario es una persona) usa
Carbon directamente; no hay helper propio:

```php
$date?->setTimezone($user->getTimezone())->format('Y-m-d H:i');  // 2026-01-15 14:30
$date?->locale('es')->translatedFormat('j \d\e F \d\e Y');     // 15 de enero de 2026
$date?->diffForHumans();                                         // hace 3 horas
```

Usa siempre `?->` con fechas nullable. Estos formatos no van en el JSON de la API.

---

## 6. Excepciones legítimas

- **Tablas del framework** (`jobs`, `sessions`, `failed_jobs`): no se tocan.
- **Campos con zona horaria propia**: guarda el instante en UTC y la zona en una
  columna aparte (`timezone`). Nunca guardes horas locales sin contexto.
- **Correos y PDFs**: ahí sí se usa formato legible (`Y-m-d H:i`), porque el
  destinatario es una persona, no un parser.

---

## 7. Verificación

```bash
# Formateos ad-hoc que se saltan el estándar en respuestas de API
grep -rn "format('d/m/Y" app/Http/Resources/
grep -rn "toDateTimeString()" app/Http/Resources/

make check
```

## Referencias

- [api-response-structure.md](api-response-structure.md) → contrato completo de respuestas
- `app/Http/Resources/BaseResource.php`
