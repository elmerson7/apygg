#!/usr/bin/env bash
# Recorridos Playwright del cliente de pruebas (frontend-demo/e2e) contra un stack TEMPORAL propio.
#
#   scripts/frontend-e2e.sh run [args de playwright]   # build + stack + prepare + reloj + recorridos + down
#   scripts/frontend-e2e.sh down                       # baja el stack si quedó algo
#
# Reutiliza docker-compose.e2e.yml / scripts/e2e-stack.sh (API Octane + Horizon + Postgres + Redis + Mailpit + S3,
# pasarela fake, sin puertos en el host). Node y Playwright corren en contenedores efímeros de versión fija:
# nunca Node en el host. Memoria: stack ~1,7 GB + Playwright 768 MB (< 2,5 GB). Termina con `down -v`.
#
# Fases (el impago necesita adelantar el reloj de la BD y lanzar el cron, que el navegador no puede hacer):
#   1. build del front (typecheck + vite build) y `prepare` (Playwright, solo API): empresa con suscripción en prueba
#   2. aquí: adelanta 15 días las fechas de esas empresas y lanza `billing:renew` (cobro rechazado -> past_due),
#      luego 8 días más (pasada la gracia -> escritura bloqueada)
#   3. recorridos de escritorio y móvil (`desktop`, `mobile`)
# Variables: E2E_PROJECT (nombre único), FRONTEND_E2E_LOCK (candado flock para servidores compartidos),
# E2E_KEEP=1 (no baja al terminar), E2E_REUSE_IMAGES (por defecto 1 si existe apygg-app:latest).
set -euo pipefail
cd "$(dirname "$0")/.."

[ -f .env ] || { echo "Falta .env: ejecuta primero 'make init NAME=<slug>'." >&2; exit 1; }

NODE_IMAGE="${FRONTEND_NODE_IMAGE:-node:22-bookworm-slim}"
PW_VERSION="$(grep -E '"@playwright/test"' frontend-demo/package.json | sed -E 's/.*: *"([0-9.]+)".*/\1/')"
PW_IMAGE="${FRONTEND_PLAYWRIGHT_IMAGE:-mcr.microsoft.com/playwright:v${PW_VERSION}-noble}"
UIDGID="$(id -u):$(id -g)"

get() { grep -E "^$1=" .env | tail -1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//'; }
export DB_DATABASE="$(get DB_DATABASE)" DB_USERNAME="$(get DB_USERNAME)" DB_PASSWORD="$(get DB_PASSWORD)"
export REDIS_PASSWORD="$(get REDIS_PASSWORD)" APP_TITLE="$(get APP_TITLE)"
export E2E_UID="$(id -u)" E2E_GID="$(id -g)"
export E2E_PROJECT="${E2E_PROJECT:-apygg-e2e-$(basename "$PWD" | tr -c 'a-z0-9\n' '-' | cut -c1-20)}"
if [ -z "${E2E_REUSE_IMAGES:-}" ] && docker image inspect apygg-app:latest >/dev/null 2>&1; then
    export E2E_REUSE_IMAGES=1
fi

DC=(docker compose -f docker-compose.e2e.yml --project-name "$E2E_PROJECT")

node_run() {
    docker run --rm -u "$UIDGID" -e HOME=/tmp -v "$PWD":/work -w /work/frontend-demo --memory 1g "$NODE_IMAGE" "$@"
}

pw_run() {
    docker run --rm --name "${E2E_PROJECT}-playwright" --network "${E2E_PROJECT}-network" \
        -u "$UIDGID" -e HOME=/tmp -e CI=1 -e VITE_API_URL=http://app:8000 -e MAILPIT_URL=http://mailpit:8025 \
        --memory 768m --memory-swap 768m --shm-size 256m \
        -v "$PWD":/work -w /work/frontend-demo "$PW_IMAGE" "$@"
}

psql_stack() { "${DC[@]}" exec -T postgres psql -v ON_ERROR_STOP=1 -q -U "$DB_USERNAME" -d "$DB_DATABASE" -c "$1"; }

# Retrocede N días las fechas de suscripción, facturas y cobros de las empresas del escenario de impago
# (mismo recurso que e2eViajar() de tests/E2E/helpers.php): "ahora" queda N días después de lo que consta.
viajar() {
    local d="interval '$1 days'" t="(SELECT id FROM tenants WHERE slug LIKE 'fe-dun-%')"
    psql_stack "UPDATE subscriptions SET trial_ends_at = trial_ends_at - $d, billing_anchor_at = billing_anchor_at - $d,
        current_period_start = current_period_start - $d, current_period_end = current_period_end - $d,
        next_retry_at = next_retry_at - $d, created_at = created_at - $d WHERE tenant_id IN $t;
        UPDATE invoices SET period_start = period_start - $d, period_end = period_end - $d, issued_at = issued_at - $d,
        due_at = due_at - $d, paid_at = paid_at - $d, created_at = created_at - $d WHERE tenant_id IN $t;"
}

down() {
    "${DC[@]}" --profile runner down -v --remove-orphans >/dev/null 2>&1 || true
    docker rm -f "${E2E_PROJECT}-playwright" >/dev/null 2>&1 || true
}

run() {
    echo "→ Cliente: dependencias, tipos y build (Node efímero)..."
    [ -d frontend-demo/node_modules/.bin ] || node_run npm ci --no-audit --no-fund
    node_run npm run api:types
    node_run npm run build

    [ "${E2E_KEEP:-0}" = 1 ] || trap down EXIT
    echo "→ Stack temporal ($E2E_PROJECT)..."
    bash scripts/e2e-stack.sh up

    # Estado y resultados de la ejecución anterior (carpetas fijas, ignoradas por git)
    rm -rf ./frontend-demo/e2e/.state ./frontend-demo/test-results ./frontend-demo/playwright-report
    echo "→ Fase 1: escenario de impago por la API (Playwright 'prepare')..."
    pw_run npx playwright test -c e2e/playwright.config.ts --project=prepare

    echo "→ Reloj +15 días y cobro de renovación (tarjeta rechazada -> past_due)..."
    viajar 15
    "${DC[@]}" exec -T app php artisan billing:renew --no-interaction
    echo "→ Reloj +8 días (pasada la gracia -> escritura bloqueada)..."
    viajar 8

    echo "→ Fase 2: recorridos en escritorio y móvil..."
    rc=0
    pw_run npx playwright test -c e2e/playwright.config.ts --project=desktop --project=mobile "$@" || rc=$?
    if [ "$rc" -ne 0 ]; then
        echo "── logs de app y horizon (últimas líneas) ──" >&2
        "${DC[@]}" logs --tail=40 app horizon >&2 || true
        echo "Capturas y trazas del fallo: frontend-demo/test-results y frontend-demo/playwright-report" >&2
    fi
    return "$rc"
}

cmd="${1:-run}"; shift || true

if [ -n "${FRONTEND_E2E_LOCK:-}" ]; then
    exec 9>"$FRONTEND_E2E_LOCK"
    echo "esperando turno ($FRONTEND_E2E_LOCK)..."
    flock 9
fi
unset E2E_LOCK

case "$cmd" in
    run) run "$@" ;;
    down) down ;;
    *) echo "uso: $0 {run|down}" >&2; exit 2 ;;
esac
