#!/usr/bin/env bash
# Stack temporal de carga (load/compose.yml): up | seed | down | ps | url
#   LOAD_PROJECT=apygg-k6 LOAD_PORT=18010 load/stack.sh up
#   load/stack.sh seed [--tenants=20 ...]      # php artisan seed:load (+ CSV de credenciales para k6)
#   load/stack.sh app [pgbouncer|direct]      # recrea la API con LOAD_WORKERS y, opcional, vía PgBouncer (perfil pgbouncer)
#   load/stack.sh sql "<consulta>"              # psql dentro del contenedor de PostgreSQL
#   load/stack.sh down                          # para y BORRA contenedores, volumen y red (down -v)
# Nombre de proyecto único = nada se mezcla con otros stacks. Ver docs/load-testing.md.
set -euo pipefail
cd "$(dirname "$0")"
export LOAD_PROJECT="${LOAD_PROJECT:-apygg-load}"
export LOAD_PORT="${LOAD_PORT:-18010}"
export LOAD_UID="${LOAD_UID:-$(id -u)}" LOAD_GID="${LOAD_GID:-$(id -g)}"
DC=(docker compose -f compose.yml -p "$LOAD_PROJECT" --profile pgbouncer)

case "${1:-}" in
  up)
    if ss -ltn 2>/dev/null | awk '{print $4}' | grep -q ":${LOAD_PORT}\$"; then
      echo "El puerto ${LOAD_PORT} ya está en uso: elige otro con LOAD_PORT=" >&2; exit 1
    fi
    "${DC[@]}" up -d --wait --wait-timeout 180 postgres redis
    "${DC[@]}" up -d app
    for _ in $(seq 1 90); do
      curl -fsS "http://127.0.0.1:${LOAD_PORT}/health/live" >/dev/null 2>&1 && { echo "API lista en http://127.0.0.1:${LOAD_PORT}"; break; }
      sleep 2
    done
    curl -fsS "http://127.0.0.1:${LOAD_PORT}/health/live" >/dev/null || { "${DC[@]}" logs --tail=40 app; exit 1; }
    ;;
  migrate)
    "${DC[@]}" exec -T app php artisan migrate --force --seed
    ;;
  seed)
    shift
    "${DC[@]}" exec -T app php artisan seed:load "$@"
    ;;
  app)
    if [ "${2:-direct}" = "pgbouncer" ]; then
      "${DC[@]}" up -d --wait --wait-timeout 60 pgbouncer
      export LOAD_DB_HOST=pgbouncer LOAD_DB_PORT=6432
    fi
    "${DC[@]}" up -d --force-recreate --no-deps app
    for _ in $(seq 1 60); do
      curl -fsS "http://127.0.0.1:${LOAD_PORT}/health/live" >/dev/null 2>&1 && exit 0
      sleep 2
    done
    "${DC[@]}" logs --tail=40 app; exit 1
    ;;
  sql)
    "${DC[@]}" exec -T postgres psql -U load -d load -At -c "$2"
    ;;
  down)
    "${DC[@]}" down -v --remove-orphans
    ;;
  ps) "${DC[@]}" ps ;;
  url) echo "http://127.0.0.1:${LOAD_PORT}" ;;
  *) echo "uso: load/stack.sh up|migrate|seed [opciones]|app [pgbouncer]|sql|down|ps|url" >&2; exit 2 ;;
esac
