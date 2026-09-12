# Auditoría de seguridad

Un escaneo que revisa la configuración y los logs de la aplicación, resume lo
encontrado y —si hay un LLM configurado— lo interpreta y propone acciones.

No sustituye a un pentest. Sirve para lo que nadie hace a mano: mirar todos los
días si APP_DEBUG se quedó encendido, si hubo una ráfaga de logins fallidos, si
alguien creó un administrador de madrugada o si se borraron 200 registros de
golpe.

## Qué revisa

| Chequeo | Busca |
|---|---|
| `environment` | APP_DEBUG en producción, APP_ENV, JWT_SECRET débil, APP_URL sin HTTPS, Telescope habilitado |
| `security_logs` | Logins fallidos, actividad sospechosa, accesos denegados, cuentas bloqueadas |
| `api_errors` | Errores 5xx y las rutas que los concentran |
| `activity_logs` | Borrados masivos (posible limpieza de evidencia) |
| `recent_users` | Usuarios nuevos, y sobre todo **administradores** nuevos |
| `api_keys` | Claves sin caducidad o caducadas sin revocar |

Ventana de análisis: 24 horas (`SecurityAuditService::WINDOW_HOURS`).

## Anonimización

El orden del flujo es lo que importa:

```
recolectar → anonimizar → analizar con LLM
```

Los identificadores sensibles (`ip_address`, `user_id`, `user_agent`, `email`,
`trace_id`) se hashean **antes** de salir hacia el modelo, con SHA-256 y una sal
**propia de cada escaneo**:

- Dentro de un escaneo, el mismo valor produce el mismo hash: se pueden
  correlacionar eventos ("estas 12 peticiones vienen del mismo sitio").
- Entre escaneos, el mismo valor produce hashes distintos: no se construye un
  identificador estable que persiga a una persona a lo largo del tiempo.

Los conteos y las rutas sí viajan en claro: son lo que el modelo analiza y no
identifican a nadie.

La sal se guarda en la fila del escaneo y **nunca se serializa** (`$hidden`).
Publicarla permitiría deshacer la anonimización por fuerza bruta: una IPv4 son
2^32 candidatos, cuestión de segundos.

## Uso

```http
POST /admin/security/scans          # lanzar (permiso security.audit)
GET  /admin/security/scans          # historial
GET  /admin/security/scans/{id}     # hallazgos + análisis
POST /admin/security/scans/{id}/stop
GET  /admin/security/scans/preview  # hallazgos locales, sin IA y sin registrar nada
```

Solo puede haber un escaneo activo a la vez: dos simultáneos analizarían la
misma ventana y duplicarían el gasto de IA. La segunda petición responde 409 con
el id del que ya está en curso.

El escaneo corre en cola (`RunSecurityScanJob`, sin reintentos): la llamada al
LLM sola ya se va a decenas de segundos.

`preview` es el atajo: devuelve los hallazgos locales al instante, sin LLM y sin
crear registro. Vale para un widget de estado o para un health check.

## Sin LLM

Si no hay modelo configurado, el escaneo **no falla**. Los hallazgos locales se
recolectan igual y la severidad global se calcula a partir de ellos; el análisis
queda con `llm_available: false`. La parte que aporta la IA es la
interpretación, no la detección.

## Severidades

`ok` · `low` · `medium` · `high` · `critical`

La severidad global es la peor de todos los hallazgos. Un administrador creado
en las últimas 24 h es `critical` por sí solo: es la señal más clara de una
intrusión con éxito.

## Qué NO hace (y por qué)

**No escanea el host**: procesos, puertos abiertos, permisos de ficheros,
crontabs. Hacerlo exige ejecutar comandos en la máquina, y un boilerplate no
debe traer de serie una ruta de ejecución de comandos dirigida por un LLM.

Si tu proyecto lo necesita, la herramienta adecuada es un auditor especializado
(Lynis, OpenSCAP) ejecutado por el sistema, no por la aplicación.

## Automatizarlo

```php
// routes/console.php
Schedule::call(fn () => app(SecurityAuditService::class)->start(User::find(1)))
    ->dailyAt('06:00');
```

O solo los hallazgos locales, sin gastar IA:

```php
Schedule::call(function () {
    $service = app(SecurityAuditService::class);
    $findings = $service->collectFindings();

    if (in_array($service->localSeverity($findings), ['high', 'critical'], true)) {
        // notificar
    }
})->hourly();
```

## Referencias

- `app/Services/SecurityAuditService.php`
- `app/Models/SecurityScan.php`
- `docs/llm.md` → el área `security` del gateway
- `docs/logging-file-structure.md` → de dónde salen los logs que analiza
