#!/usr/bin/env bash
# Ejecuta un escenario k6 en contenedor (versión fija) contra LOAD_BASE_URL.
#   load/run.sh smoke|load|stress|spike|soak     (los targets `make load-*` lo llaman)
# Variables: LOAD_BASE_URL, LOAD_CREDENTIALS (CSV de seed:load), K6_IMAGE y cualquier LOAD_* de load/lib/config.js.
set -euo pipefail
cd "$(dirname "$0")/.."
scenario="${1:?uso: load/run.sh smoke|load|stress|spike|soak}"
[ -f "load/${scenario}.js" ] || { echo "No existe load/${scenario}.js" >&2; exit 2; }

K6_IMAGE="${K6_IMAGE:-grafana/k6:1.4.0}"
CREDS="${LOAD_CREDENTIALS:-storage/app/seed-load/credentials.csv}"
[ -f "$CREDS" ] || { echo "Falta $CREDS: ejecuta antes make seed-load (docs/seed-load.md)" >&2; exit 1; }
export LOAD_BASE_URL="${LOAD_BASE_URL:-http://127.0.0.1:${LOAD_PORT:-8010}}"

envs=()
while IFS='=' read -r name _; do envs+=(-e "$name"); done < <(env | grep -E '^LOAD_' || true)
mkdir -p load/results

# --network host: la API (o el stack temporal) escucha en 127.0.0.1 del host.
exec docker run --rm --network host --user "$(id -u):$(id -g)" \
  -v "$PWD/load:/load:ro" -v "$PWD/load/results:/results" \
  -v "$(realpath "$CREDS"):/data/credentials.csv:ro" \
  "${envs[@]}" "$K6_IMAGE" run --quiet "/load/${scenario}.js"
