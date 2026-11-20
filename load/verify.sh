#!/usr/bin/env bash
# Humo de punta a punta con stack temporal: levanta (≤ 2 GB), migra, siembra poco, corre k6 y lo baja todo (down -v).
#   flock /home/ubuntu/dev/apygg-wt/.verify.lock load/verify.sh      # en el servidor compartido, con candado
# Variables: LOAD_PROJECT (apygg-load), LOAD_PORT (18010), LOAD_TENANTS (20), LOAD_SCENARIO (smoke).
set -uo pipefail
cd "$(dirname "$0")/.."
export LOAD_PROJECT="${LOAD_PROJECT:-apygg-load}" LOAD_PORT="${LOAD_PORT:-18010}"
scenario="${LOAD_SCENARIO:-smoke}"
rc=1
cleanup() { load/stack.sh down >/dev/null 2>&1; }
trap cleanup EXIT
load/stack.sh up && load/stack.sh migrate \
  && load/stack.sh seed --tenants="${LOAD_TENANTS:-20}" --users-per-tenant=10 --files-per-tenant=5 \
  && LOAD_BASE_URL="http://127.0.0.1:${LOAD_PORT}" load/run.sh "$scenario"
rc=$?
exit $rc
