#!/usr/bin/env bash
# Humo tras desplegar: sondas de salud, versión desplegada y (opcional) un login real.
#   scripts/deploy/smoke.sh <URL> [RELEASE_ESPERADA]
# Variables: SMOKE_RETRIES (10), SMOKE_DELAY (3 s), SMOKE_EMAIL + SMOKE_PASSWORD (login opcional),
#            SMOKE_LOGIN_PATH (/auth/login). Sale con 0 si todo pasa; 1 si algo falla.
set -uo pipefail

url="${1:-${SMOKE_URL:-}}"
expected="${2:-${EXPECTED_RELEASE:-}}"
[ -n "$url" ] || { echo "Uso: smoke.sh <URL> [RELEASE_ESPERADA]" >&2; exit 64; }
url="${url%/}"
retries="${SMOKE_RETRIES:-10}"; delay="${SMOKE_DELAY:-3}"

fail() { echo "FALLO: $*" >&2; exit 1; }

# GET con reintentos: devuelve el cuerpo en $body; falla si nunca da 2xx
get_ok() {
    local path="$1" i code
    for ((i = 1; i <= retries; i++)); do
        code=$(curl -sS -m 10 -o /tmp/smoke.$$ -w '%{http_code}' "$url$path" 2>/dev/null) || code=000
        if [[ "$code" =~ ^2 ]]; then body=$(cat /tmp/smoke.$$); rm -f /tmp/smoke.$$; return 0; fi
        sleep "$delay"
    done
    rm -f /tmp/smoke.$$
    fail "$path no respondió 2xx tras $retries intentos (último: $code)"
}

get_ok /health/live;  echo "ok  /health/live"
get_ok /health/ready; echo "ok  /health/ready"

release=$(printf '%s' "$body" | grep -o '"release" *: *"[^"]*"' | head -1 | cut -d'"' -f4)
[ -n "$release" ] || fail "/health/ready no informa 'release'"
echo "ok  release desplegada: $release"
if [ -n "$expected" ] && [ "$release" != "$expected" ]; then
    fail "release desplegada '$release' != esperada '$expected'"
fi

if [ -n "${SMOKE_EMAIL:-}" ] && [ -n "${SMOKE_PASSWORD:-}" ]; then
    payload=$(printf '{"email":"%s","password":"%s"}' "$SMOKE_EMAIL" "$SMOKE_PASSWORD")
    code=$(curl -sS -m 15 -o /dev/null -w '%{http_code}' -X POST -H 'Content-Type: application/json' \
        -H 'Accept: application/json' -d "$payload" "$url${SMOKE_LOGIN_PATH:-/auth/login}") || code=000
    [[ "$code" =~ ^2 ]] || fail "login de prueba devolvió $code"
    echo "ok  login de prueba"
fi

echo "Humo OK ($url)"
