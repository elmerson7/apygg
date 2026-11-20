#!/usr/bin/env bash
# Despliegue por SSH a un servidor con Docker Compose. Lo usan `make deploy|rollback|smoke`.
#   scripts/deploy/deploy.sh deploy|rollback|status|smoke
# Variables (de `.env.deploy`, del entorno o de la línea de make):
#   DEPLOY_HOST   user@host del servidor (o `local` para ejecutar en esta máquina: pruebas)
#   DEPLOY_PATH   carpeta en el servidor con .env (de Laravel) y donde se copia compose.prod.yml
#   IMAGE         ghcr.io/<org>/<repo>      IMAGE_TAG  sha-<commit> | vX.Y.Z (nunca latest)
#   PROJECT (apygg) · DEPLOY_PORT (22) · DEPLOY_SSH_KEY · COMPOSE_PROFILES_EXTRA (p. ej. "reverb")
#   SMOKE_URL     URL pública (humo tras desplegar) · DEPLOY_AUTO_ROLLBACK=true|false (true)
set -euo pipefail

here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
root="$(cd "$here/../.." && pwd)"
[ -f "$root/.env.deploy" ] && { set -a; . "$root/.env.deploy"; set +a; }

action="${1:-}"
DEPLOY_HOST="${DEPLOY_HOST:-}"
DEPLOY_PATH="${DEPLOY_PATH:-}"
PROJECT="${PROJECT:-apygg}"
IMAGE="${IMAGE:-}"
IMAGE_TAG="${IMAGE_TAG:-}"
AUTO_ROLLBACK="${DEPLOY_AUTO_ROLLBACK:-true}"

die() { echo "ERROR: $*" >&2; exit 2; }

if [ "$action" != "smoke" ]; then
    [ -n "$DEPLOY_HOST" ] || die "define DEPLOY_HOST (user@host)"
    [ -n "$DEPLOY_PATH" ] || die "define DEPLOY_PATH"
    [[ "$DEPLOY_PATH" == /* ]] || die "DEPLOY_PATH debe ser absoluta"
fi
[[ "$PROJECT" =~ ^[a-z0-9][a-z0-9_-]*$ ]] || die "PROJECT inválido"
[[ "${COMPOSE_PROFILES_EXTRA:-}" =~ ^[a-z0-9\ _-]*$ ]] || die "COMPOSE_PROFILES_EXTRA inválido"

valid_tag() { [[ "$1" =~ ^(sha-[0-9a-f]{7,40}|v[0-9]+\.[0-9]+\.[0-9]+([-+][A-Za-z0-9.]+)?)$ ]]; }

ssh_run() { # ejecuta un comando en el servidor (o aquí si DEPLOY_HOST=local)
    if [ "$DEPLOY_HOST" = "local" ]; then bash -c "$1"; return; fi
    local opts=(-p "${DEPLOY_PORT:-22}" -o BatchMode=yes -o StrictHostKeyChecking=accept-new)
    [ -n "${DEPLOY_SSH_KEY:-}" ] && opts+=(-i "$DEPLOY_SSH_KEY")
    ssh "${opts[@]}" "$DEPLOY_HOST" "$1"
}

# Envía remote.sh por stdin y le pasa la acción; las variables van como prefijo de entorno, entre comillas
remote() {
    local q_path q_proj q_prof args=""
    q_path=$(printf '%q' "$DEPLOY_PATH"); q_proj=$(printf '%q' "$PROJECT"); q_prof=$(printf '%q' "${COMPOSE_PROFILES_EXTRA:-}")
    local a; for a in "$@"; do args+=" $(printf '%q' "$a")"; done
    ssh_run "DEPLOY_PATH=$q_path PROJECT=$q_proj COMPOSE_PROFILES_EXTRA=$q_prof bash -s --$args" < "$here/remote.sh"
}

smoke() {
    [ -n "${SMOKE_URL:-}" ] || die "define SMOKE_URL (p. ej. https://api.midominio.com)"
    "$here/smoke.sh" "$SMOKE_URL" "${1:-}"
}

do_rollback() {
    echo "== Rollback en $DEPLOY_HOST:$DEPLOY_PATH =="
    remote rollback "${1:-}"
}

case "$action" in
    deploy)
        [ -n "$IMAGE" ] || die "define IMAGE (p. ej. ghcr.io/org/repo)"
        [[ "$IMAGE" =~ ^[a-z0-9./_-]+$ ]] || die "IMAGE inválida (en minúsculas, sin etiqueta)"
        [ -n "$IMAGE_TAG" ] || die "define IMAGE_TAG (sha-<commit> o vX.Y.Z)"
        valid_tag "$IMAGE_TAG" || die "IMAGE_TAG '$IMAGE_TAG' no es una etiqueta fija (sha-<hex> o vX.Y.Z); 'latest' no se despliega"

        echo "== Desplegando $IMAGE:$IMAGE_TAG en $DEPLOY_HOST:$DEPLOY_PATH =="
        ssh_run "mkdir -p $(printf '%q' "$DEPLOY_PATH")"
        ssh_run "cat > $(printf '%q' "$DEPLOY_PATH")/compose.prod.yml" < "$root/deploy/compose.prod.yml"

        rc=0; remote deploy "$IMAGE" "$IMAGE_TAG" || rc=$?
        if [ "$rc" -eq 10 ]; then
            # Falló el pull o las migraciones: no se cambió ningún servicio, no hay nada que revertir
            echo "ERROR: falló la descarga o las migraciones; los servicios siguen en la versión anterior." >&2
            exit 1
        elif [ "$rc" -ne 0 ]; then
            echo "ERROR: los servicios no arrancaron sanos con $IMAGE_TAG." >&2
            if [ "$AUTO_ROLLBACK" = "true" ]; then do_rollback || true; fi
            exit 1
        fi

        if [ -n "${SMOKE_URL:-}" ]; then
            # La release que informa /health es `sha-<7 hex>`: solo se puede exigir cuando se despliega por sha
            expected=""; [[ "$IMAGE_TAG" == sha-* ]] && expected="${IMAGE_TAG:0:11}"
            if ! smoke "$expected" 2>&1 | sed 's/^/   /'; then
                echo "ERROR: el humo falló tras desplegar $IMAGE_TAG." >&2
                if [ "$AUTO_ROLLBACK" = "true" ]; then do_rollback; fi
                exit 1
            fi
        else
            echo "(sin SMOKE_URL: se omite el humo)"
        fi
        echo "== Despliegue completo: $IMAGE_TAG =="
        ;;
    rollback) do_rollback "${ROLLBACK_TAG:-}" ;;
    status) remote status ;;
    smoke) smoke "${EXPECTED_RELEASE:-}" ;;
    *) die "uso: deploy.sh deploy|rollback|status|smoke" ;;
esac
