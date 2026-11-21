# Métricas Prometheus (módulo opcional `metrics`)

`GET /metrics` expone el estado de la API en el formato de texto de Prometheus. Es un módulo **opcional y apagado
por defecto** (`METRICS_ENABLED=false`): con el flag apagado la ruta responde 404 y el middleware no escribe nada.
Quitarlo del todo: `make module-remove name=metrics`.

## Configuración (`config/metrics.php`)

| Variable | Defecto | Qué hace |
|---|---|---|
| `METRICS_ENABLED` | `false` | Enciende el registro y la ruta. |
| `METRICS_TOKEN` | vacío | Se envía como `Authorization: Bearer <token>`. |
| `METRICS_ALLOWED_IPS` | vacío | IPs o CIDR separadas por comas (`10.0.0.0/8,172.18.0.5`). |
| `METRICS_QUEUES` | `default` | Colas cuyo tamaño se expone. |
| `METRICS_NAMESPACE` | `app` | Prefijo de los nombres (`<ns>_http_requests_total`). |
| `METRICS_REDIS_CONNECTION` / `METRICS_KEY_PREFIX` | `default` / `metrics:` | Dónde se acumulan los contadores. |

**Nunca es público**: aun encendido, hace falta el token **o** una IP permitida (basta una). Sin ninguno de los
dos configurados responde siempre 403. La IP es la real del cliente (`ResolveClientIpMiddleware`, `TRUSTED_PROXIES`).
Usa un token largo aleatorio y, mejor, limita además el acceso por red (Prometheus dentro de la red del stack).
La ruta cuelga de la raíz aunque haya `API_PREFIX` (está en `config/api.php` → `unprefixed`).

Ejemplo de `scrape_config`:

```yaml
scrape_configs:
  - job_name: api
    metrics_path: /metrics
    authorization: { type: Bearer, credentials_file: /etc/prometheus/api.token }
    static_configs: [{ targets: ["api:8000"] }]
```

## Qué se mide

| Métrica | Tipo | Etiquetas |
|---|---|---|
| `<ns>_http_requests_total` | counter | `method`, `route`, `status` |
| `<ns>_http_request_duration_seconds` | histogram (`_bucket`, `_sum`, `_count`) | `method`, `route` |
| `<ns>_queue_size` | gauge | `queue` |
| `<ns>_failed_jobs` | gauge | — (tabla `failed_jobs`) |
| `<ns>_db_up` | gauge | 1 si la BD responde |
| `<ns>_db_connections`, `<ns>_db_max_connections` | gauge | solo PostgreSQL |

- `route` es la **plantilla** de la ruta (`/users/{user}`), nunca la URL real; lo que no coincide con ninguna ruta
  se agrupa en `unmatched` (cardinalidad acotada). `/metrics` no se cuenta a sí misma.
- Los contadores viven en hashes de Redis (`HINCRBY`, atómico entre workers de Octane, sin estado en PHP) y se
  escriben en `terminate()`, **después** de enviar la respuesta: no suman latencia. Si Redis cae, la API sigue (las métricas son
  best-effort).
- Cubos del histograma: `config('metrics.buckets')` (segundos).
- Los contadores no caducan: si cambias de plantillas de ruta con frecuencia, borra las claves `metrics:*` al desplegar.

## Alertas sugeridas

```yaml
groups:
  - name: api
    rules:
      - alert: ApiErrores5xx
        expr: sum(rate(app_http_requests_total{status=~"5.."}[5m])) / sum(rate(app_http_requests_total[5m])) > 0.02
        for: 5m
        annotations: { summary: "Más del 2 % de respuestas 5xx durante 5 min" }
      - alert: ApiLatenciaP95Alta
        expr: histogram_quantile(0.95, sum by (le) (rate(app_http_request_duration_seconds_bucket[5m]))) > 1
        for: 10m
      - alert: ColaAtascada
        expr: min_over_time(app_queue_size{queue="default"}[15m]) > 100
        annotations: { summary: "La cola no baja de 100 trabajos en 15 min (¿Horizon parado?)" }
      - alert: JobsFallidos
        expr: increase(app_failed_jobs[15m]) > 0
      - alert: BaseDeDatosCaida
        expr: app_db_up == 0
        for: 1m
      - alert: ConexionesBdAltas
        expr: app_db_connections / app_db_max_connections > 0.8
        for: 10m
      - alert: ApiSinMetricas
        expr: up{job="api"} == 0
        for: 2m
```

Ajusta los umbrales a tu carga (ver `docs/performance-baseline.md`). Con `METRICS_NAMESPACE` distinto, cambia el prefijo.
