# Mantenimiento Docker — Retención de imágenes

Cada deploy deja una imagen nueva en el servidor. Sin una política de retención,
el disco se llena en semanas: 20 imágenes huérfanas de ~200 MB son ~4 GB
irrecuperables. Este documento describe cómo diagnosticar y limpiar sin romper
nada.

> **Regla de oro**: nunca se borran volúmenes. `pgdata`, `redisdata` y
> `meilidata` contienen los datos reales del proyecto.

## Diagnóstico

```bash
# Resumen: cuánto es recuperable
docker system df
# Images       28   11.41GB   3.85GB reclaimable (33%)
# Containers   13
# Build Cache  2.03GB         388MB reclaimable

# Detalle por imagen (detectar huérfanas)
docker images --format 'table {{.Repository}}:{{.Tag}}\t{{.Size}}\t{{.CreatedSince}}'

# Cuáles están EN USO (nunca se tocan)
docker ps --format 'table {{.Names}}\t{{.Image}}\t{{.Status}}'

# Espacio del host
df -h

# Caché del builder
docker builder du
```

## Configuración

`scripts/prune-images.sh` necesita saber qué repositorio de imágenes limpiar.
Se lee de la variable `APP_IMAGE` (entorno o `compose.env`):

```bash
# compose.env
APP_IMAGE=ghcr.io/miorg/apygg
```

Variables opcionales:

| Variable | Default | Descripción |
|---|---|---|
| `APP_IMAGE` | — | Repositorio de la imagen. Obligatorio. |
| `TAG_PREFIXES` | `main dev` | Prefijos de tag a considerar (`main-<hash>`, `dev-<hash>`) |
| `UNTIL` | `72h` | Ventana de retención |
| `MIN_KEEP` | `2` | Tags recientes que nunca se borran (para rollback) |

## Limpieza

### Opción A — Makefile (recomendado)

```bash
# Simular: muestra candidatas y protegidas, no borra nada
make prune-images DRY_RUN=1
make prune-images-dry-run
make prune-images DRY_RUN=1 UNTIL=168h   # ventana de 7 días

# Limpieza real, con confirmación interactiva
make prune-images              # UNTIL=72h por defecto
make prune-images UNTIL=168h   # conserva la última semana

# Solo caché del builder
make prune-builder
make prune-builder UNTIL=168h
```

El target `prune-images`:

1. Muestra `docker system df` y las candidatas detectadas.
2. Pide confirmación `¿Continuar con el borrado? (s/N)`.
3. Ejecuta `scripts/prune-images.sh --until 72h`, que:
   - Protege imágenes con containers asociados (`docker ps` + filtro `ancestor`,
     incluidos containers detenidos).
   - Conserva siempre las `MIN_KEEP` más recientes, aunque superen la ventana.
   - Solo actúa sobre `<repo>:<prefijo>-<hash>`; no toca `:latest` ni tags flotantes.
   - Si no puede determinar la fecha de una imagen, la conserva.
   - Registra todo en `/var/log/prune-images.log` (fallback:
     `storage/logs/prune-images.log`).

### Opción B — Script directo

```bash
./scripts/prune-images.sh --dry-run --until 72h
./scripts/prune-images.sh --until 72h
DRY_RUN=1 ./scripts/prune-images.sh          # alternativa por env var
./scripts/prune-images.sh --image ghcr.io/otroorg/otro --dry-run

tail -f /var/log/prune-images.log
```

**Es idempotente**: dos ejecuciones seguidas → la segunda sale con
`Nada para borrar`.

### Qué NO hacer

```bash
# ❌ PROHIBIDO — borra volúmenes (pgdata, meilidata, redisdata)
docker system prune --volumes
docker system prune -a --volumes

# ❌ PROHIBIDO — sin filtro borra la imagen en uso y la de rollback
docker image prune -a --force
docker rmi $(docker images -q)

# ✅ Aceptable — con filtro de tiempo
docker image prune -a --filter "until=72h" --force
docker builder prune -f --filter "until=72h"

# ✅ Mejor — scripts/prune-images.sh añade la protección de imágenes en uso
./scripts/prune-images.sh --until 72h
```

## Retención automática

### 1. Tras cada deploy

Añade un paso al final del job de deploy en `.github/workflows/cd.yml`:

```yaml
- name: Prune old images on server (retención local 72h)
  run: ssh ... "cd /ruta/al/proyecto && bash scripts/prune-images.sh --until 72h"
```

Así cada deploy limpia las huérfanas anteriores a 72 h y deja las 2-3 últimas
disponibles para rollback.

### 2. Cron en el servidor (opcional)

```bash
# /etc/cron.d/prune-images  (como el usuario de deploy)
0 4 * * *  cd /var/www/apygg && /usr/bin/bash scripts/prune-images.sh --until 72h >> /var/log/prune-images.log 2>&1

# O semanal, conservando 7 días:
# 0 4 * * 0  cd /var/www/apygg && /usr/bin/bash scripts/prune-images.sh --until 168h >> /var/log/prune-images.log 2>&1
```

Verificar: `grep prune /var/log/prune-images.log | tail`.

### 3. Registry (GHCR)

La retención local no borra nada del registry. Para limpiar GHCR, añade un job
que conserve las últimas N versiones:

```yaml
- uses: actions/delete-package-versions@v5
  with:
    package-name: apygg
    package-type: container
    min-versions-to-keep: 5
    delete-only-untagged-versions: false
```

## Verificación post-prune

```bash
docker system df
# Esperado: Images reclaimable ~0-5%, Build cache reclaimable ~0

docker ps --format 'table {{.Names}}\t{{.Image}}\t{{.Status}}'
# app, horizon, reverb y scheduler deben seguir UP con la misma imagen

df -h
```

## Rollback

```bash
# Tags disponibles localmente
docker images "$APP_IMAGE" --format '{{.Tag}} {{.CreatedSince}}' | head
```

La política conserva `MIN_KEEP=2` tags recientes precisamente para que el
rollback no requiera volver a descargar la imagen.

## Referencias

- `Makefile` → targets `prune-images`, `prune-images-dry-run`, `prune-builder`
- `scripts/prune-images.sh` → lógica idempotente (`--dry-run`, `--until`, `MIN_KEEP`)
- `scripts/deploy/` → `blue-green.sh`, `canary.sh`, `rollback.sh`
- `docs/cd-deployment-guide.md`, `docs/cd-pipeline-configuration.md`
