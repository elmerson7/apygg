#!/usr/bin/env bash
#
# wait-healthy.sh <contenedor>... — Espera (máx. WAIT_TIMEOUT s, 180 por defecto) a que cada
# contenedor esté "healthy" (o "running" si no tiene healthcheck). Lo usa make setup.

set -u
TIMEOUT="${WAIT_TIMEOUT:-180}"
for c in "$@"; do
    printf "   %s " "$c"
    t=0
    while true; do
        st=$(docker inspect -f '{{if .State.Health}}{{.State.Health.Status}}{{else}}{{.State.Status}}{{end}}' "$c" 2>/dev/null || echo "missing")
        if [[ "$st" == "healthy" || "$st" == "running" ]]; then echo "✅"; break; fi
        if (( t >= TIMEOUT )); then echo "❌ ($st tras ${TIMEOUT}s). Revisa: docker logs $c"; exit 1; fi
        sleep 2; t=$((t + 2)); printf "."
    done
done
