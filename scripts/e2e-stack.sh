#!/usr/bin/env bash
# Stack EFÍMERO de la suite E2E (docker-compose.e2e.yml): lo levanta, corre tests/E2E por HTTP real y
# lo baja con `down -v`. Sin puertos publicados y con memoria acotada (~1,9 GB en total).
#
#   scripts/e2e-stack.sh run [args de pest]   # up + migrar + suite + down (lo normal: `make e2e`)
#   scripts/e2e-stack.sh up                   # solo levanta y prepara (para depurar)
#   scripts/e2e-stack.sh test [args de pest]  # suite contra un stack ya levantado
#   scripts/e2e-stack.sh down                 # baja y borra contenedores, red y volúmenes
#
# Variables: E2E_PROJECT (nombre único; por defecto apygg-e2e-<carpeta>), E2E_REUSE_IMAGES=1 (reutiliza
# la imagen local apygg-app:latest en vez de construirla), E2E_LOCK (fichero de
# candado; si se define, el script espera turno con flock: servidores compartidos), E2E_KEEP=1 (no baja al terminar).
# Necesita `.env` (make init). Cómo funciona y qué prueba: docs/testing.md ("Pruebas E2E").
set -euo pipefail
cd "$(dirname "$0")/.."

[ -f .env ] || { echo "Falta .env: ejecuta primero 'make init NAME=<slug>'." >&2; exit 1; }

# El .env aporta las credenciales que usa la interpolación del compose (DB_*, REDIS_PASSWORD, APP_TITLE).
get() { grep -E "^$1=" .env | tail -1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//'; }
export DB_DATABASE="$(get DB_DATABASE)" DB_USERNAME="$(get DB_USERNAME)" DB_PASSWORD="$(get DB_PASSWORD)"
export REDIS_PASSWORD="$(get REDIS_PASSWORD)" APP_TITLE="$(get APP_TITLE)"
export E2E_UID="$(id -u)" E2E_GID="$(id -g)"
export E2E_PROJECT="${E2E_PROJECT:-apygg-e2e-$(basename "$PWD" | tr -c 'a-z0-9\n' '-' | cut -c1-20)}"
if [ "${E2E_REUSE_IMAGES:-0}" = 1 ]; then
    export E2E_APP_IMAGE="${E2E_APP_IMAGE:-apygg-app:latest}"
    BUILD=(--no-build)
else
    BUILD=()
fi

DC=(docker compose -f docker-compose.e2e.yml --project-name "$E2E_PROJECT")

down() { "${DC[@]}" --profile runner down -v --remove-orphans >/dev/null 2>&1 || true; }

up() {
    # Si quedó algo de una ejecución anterior con este nombre, se parte de cero.
    down
    mkdir -p storage/logs bootstrap/cache
    echo "→ Levantando el stack E2E ($E2E_PROJECT)..."
    "${DC[@]}" up -d --wait --wait-timeout 240 "${BUILD[@]}" postgres redis mailpit s3 app
    "${DC[@]}" up --no-deps "${BUILD[@]}" s3-init >/dev/null
    echo "→ Migraciones y datos base..."
    "${DC[@]}" exec -T app php artisan migrate --force --no-interaction
    "${DC[@]}" exec -T app php artisan db:seed --force --no-interaction
    "${DC[@]}" exec -T app php artisan billing:sync-plans --no-interaction
    # Horizon arranca DESPUÉS de migrar y sembrar: así parte con la BD lista y sin carreras de reinicio.
    "${DC[@]}" up -d --wait --wait-timeout 120 "${BUILD[@]}" horizon
}

test_suite() {
    "${DC[@]}" run --rm --no-deps --name "${E2E_PROJECT}-runner" e2e ./vendor/bin/pest --testsuite=E2E "$@"
}

cmd="${1:-run}"; shift || true

if [ -n "${E2E_LOCK:-}" ]; then
    exec 9>"$E2E_LOCK"
    echo "esperando turno ($E2E_LOCK)..."
    flock 9
fi

case "$cmd" in
    up) up ;;
    down) down ;;
    test) test_suite "$@" ;;
    run)
        [ "${E2E_KEEP:-0}" = 1 ] || trap down EXIT
        up
        rc=0
        test_suite "$@" || rc=$?
        if [ "$rc" -ne 0 ]; then
            echo "── logs de app y horizon (últimas líneas) ──" >&2
            "${DC[@]}" logs --tail=40 app horizon >&2 || true
        fi
        exit "$rc"
        ;;
    *) echo "uso: $0 {run|up|test|down}" >&2; exit 2 ;;
esac
