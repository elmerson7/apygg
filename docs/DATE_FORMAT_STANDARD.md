# Estándar de fechas y horas

## Objetivo

Un único formato para todas las fechas que entran y salen de la API. Sin esto,
cada módulo termina devolviendo un formato distinto y el frontend acaba con
media docena de parsers.

## Formato estándar

| Tipo | Formato | Ejemplo | Uso |
|------|---------|---------|-----|
| **Fecha** | `Y-m-d` | `2026-01-15` | Fechas sin hora (`start_date`, `birth_date`) |
| **Fecha y hora** | `Y-m-d H:i:s` | `2026-01-15 14:30:00` | Timestamps (`created_at`, `updated_at`) |

Siempre en **formato 24 horas**, nunca AM/PM. La aplicación trabaja en **UTC**
(`config/app.php` → `'timezone' => 'UTC'`); la conversión a la zona del usuario
es responsabilidad del cliente.

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
    "created_at": "2026-01-10 08:30:00",
    "updated_at": "2026-01-12 16:45:00"
  }
}
```

❌ Incorrecto:

```json
{
  "start_date": "15/01/2026",            // formato local
  "created_at": "2026-01-10T08:30:00Z",  // ISO 8601 mezclado con el resto
  "updated_at": "10/01/2026 08:30:00"    // otro formato más
}
```

> Si un endpoint concreto necesita ISO 8601 (por ejemplo, para un consumidor
> externo), usa `DateHelper::toIso8601()` explícitamente y documéntalo. Lo que no
> se admite es mezclar formatos sin criterio.

---

## 2. Validación de entrada

```php
$validated = $request->validate([
    'start_date' => 'nullable|date',
    'end_date' => 'nullable|date|after:start_date',
    'scheduled_at' => 'nullable|date_format:Y-m-d H:i:s',
]);
```

`date` acepta cualquier cosa que `strtotime` entienda; `date_format` es estricto.
Usa `date_format` cuando el formato exacto importe.

---

## 3. Modelos Eloquent

Declara siempre los casts: sin ellos el atributo llega al Resource como string
y cualquier `->format()` revienta.

```php
class Report extends Model
{
    protected $casts = [
        'start_date' => 'date',
        'scheduled_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}
```

El `Model` base del proyecto (`app/Models/Model.php`) ya castea
`created_at`, `updated_at` y `deleted_at`.

---

## 4. Resources

```php
class ReportResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'start_date' => $this->start_date?->format('Y-m-d'),
            'created_at' => $this->created_at?->format('Y-m-d H:i:s'),
        ];
    }
}
```

Nota el `?->`: una fecha nullable sin el operador seguro es la causa número uno
de 500 en endpoints de listado.

---

## 5. DateHelper

`App\Helpers\DateHelper` centraliza el formateo. Úsalo en lugar de repetir
`format()` con literales por todo el código:

```php
DateHelper::format($date);                  // 2026-01-15 14:30:00
DateHelper::toShort($date);                 // 2026-01-15
DateHelper::toShortWithTime($date);         // 2026-01-15 14:30
DateHelper::toIso8601($date);               // 2026-01-15T14:30:00+00:00
DateHelper::toSpanish($date, true);         // 15 de enero de 2026, 14:30
DateHelper::convertTimezone($date, 'America/Lima');
DateHelper::diffForHumans($date);           // hace 3 horas
DateHelper::parse('15/01/2026');            // Carbon, tolerante a formatos
DateHelper::validateFormat($str, 'Y-m-d');  // bool
```

Todos los métodos aceptan `null` y devuelven `null`, de modo que son seguros en
Resources.

---

## 6. Excepciones legítimas

- **Timestamps de Laravel en tablas del framework** (`jobs`, `sessions`,
  `failed_jobs`): no se tocan.
- **Campos con zona horaria propia**: si un registro necesita conservar la zona
  del evento, guarda la zona en una columna aparte (`timezone`) y el instante en
  UTC. No guardes horas locales sin contexto.
- **Integraciones externas**: si un proveedor exige RFC 3339 o epoch, se
  convierte en la capa de integración, nunca cambiando el formato de la API.

---

## 7. Verificación

```bash
# Buscar formateos ad-hoc que se saltan el estándar
grep -rn "format('d/m/Y" app/
grep -rn "->toDateTimeString()" app/Http/Resources/

make check
```
