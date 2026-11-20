#!/usr/bin/env bash
# Lado SERVIDOR del despliegue. Lo envía deploy.sh por `ssh host bash -s -- <acción> ...`; no lo ejecutes a mano.
#   deploy <IMAGE> <TAG>   → (sale con 10 si falla antes de cambiar nada) pull, migraciones en un contenedor aparte, up --wait, guarda la etiqueta anterior
#   rollback [TAG]         → vuelve a PREVIOUS_TAG (o a TAG) sin migrar
#   status                 → imprime IMAGE_TAG / PREVIOUS_TAG
# Estado en DEPLOY_PATH/deploy.env (IMAGE, IMAGE_TAG, PREVIOUS_TAG) e historial en deploy.history.
set -euo pipefail

: "${DEPLOY_PATH:?falta DEPLOY_PATH}"
PROJECT="${PROJECT:-apygg}"
PROFILES="${COMPOSE_PROFILES_EXTRA:-}"   # p. ej. "reverb"
WAIT_TIMEOUT="${WAIT_TIMEOUT:-180}"

cd "$DEPLOY_PATH"
[ -f compose.prod.yml ] || { echo "No existe $DEPLOY_PATH/compose.prod.yml" >&2; exit 2; }
[ -f .env ] || { echo "Falta $DEPLOY_PATH/.env (variables de Laravel del servidor)" >&2; exit 2; }

dc() {
    local args=(-f compose.prod.yml --project-name "$PROJECT")
    [ -f deploy.env ] && args+=(--env-file deploy.env)
    local p
    for p in $PROFILES; do args+=(--profile "$p"); done
    docker compose "${args[@]}" "$@"
}

kv() { if [ -f deploy.env ]; then grep -E "^$1=" deploy.env | head -1 | cut -d= -f2- || true; fi; }

write_state() { # IMAGE TAG PREVIOUS
    printf 'IMAGE=%s\nIMAGE_TAG=%s\nPREVIOUS_TAG=%s\n' "$1" "$2" "$3" > deploy.env.tmp
    mv deploy.env.tmp deploy.env
    printf '%s %s image=%s tag=%s previous=%s\n' "$(date -u +%FT%TZ)" "${ACTION:-}" "$1" "$2" "$3" >> deploy.history
}

ACTION="${1:-}"; shift || true

case "$ACTION" in
    status)
        echo "IMAGE=$(kv IMAGE)"; echo "IMAGE_TAG=$(kv IMAGE_TAG)"; echo "PREVIOUS_TAG=$(kv PREVIOUS_TAG)"
        ;;

    deploy)
        image="${1:?falta imagen}"; tag="${2:?falta etiqueta}"
        current="$(kv IMAGE_TAG)"
        export IMAGE="$image" IMAGE_TAG="$tag"   # el entorno del proceso manda sobre --env-file

        echo "→ Descargando $image:$tag"
        dc pull app horizon scheduler migrate || exit 10   # 10 = falló ANTES de cambiar nada

        echo "→ Migraciones (contenedor de una sola vez, ANTES de cambiar el tráfico)"
        dc run --rm --no-deps migrate || exit 10

        # Si la etiqueta es la misma que la actual, no se pisa PREVIOUS_TAG (redespliegue idempotente)
        previous="$current"
        [ "$current" = "$tag" ] && previous="$(kv PREVIOUS_TAG)"
        write_state "$image" "$tag" "$previous"

        echo "→ Cambiando servicios (app, horizon, scheduler${PROFILES:+, $PROFILES})"
        # Horizon recibe SIGTERM y termina sus jobs (stop_grace_period 120s); app se recrea con healthcheck
        dc up -d --remove-orphans --wait --wait-timeout "$WAIT_TIMEOUT"
        echo "OK desplegado $image:$tag (anterior: ${previous:-ninguna})"
        ;;

    rollback)
        target="${1:-$(kv PREVIOUS_TAG)}"
        [ -n "$target" ] || { echo "No hay etiqueta anterior guardada (PREVIOUS_TAG vacío)" >&2; exit 3; }
        image="$(kv IMAGE)"; current="$(kv IMAGE_TAG)"
        [ -n "$image" ] || { echo "No hay despliegue previo en este servidor" >&2; exit 3; }
        export IMAGE="$image" IMAGE_TAG="$target"
        echo "→ Rollback $current → $target (las migraciones NO se revierten; ver docs/deploy.md)"
        dc pull app horizon scheduler
        # Tras el rollback, PREVIOUS_TAG pasa a ser la etiqueta que falló (por si se quiere volver a ella)
        write_state "$image" "$target" "$current"
        dc up -d --remove-orphans --wait --wait-timeout "$WAIT_TIMEOUT"
        echo "OK rollback a $image:$target"
        ;;

    *)
        echo "Acción desconocida: '$ACTION' (deploy|rollback|status)" >&2
        exit 64
        ;;
esac
