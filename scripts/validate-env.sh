#!/usr/bin/env bash
#
# validate-env.sh — Valida compose.env (y su coherencia con docker-compose.yml).
# Lo ejecuta `make validate` antes de build/up. Sale con código 1 si hay errores.
#
# Errores (❌, abortan):
#   - compose.env no existe, PROJECT vacío o con caracteres no válidos
#   - algún *_PORT no numérico, fuera de 1-65535 o repetido
#   - puertos de BD / Redis / S3 / Mailpit no ligados a 127.0.0.1 en docker-compose.yml
#   - REDIS_PASSWORD vacío cuando docker-compose.yml exige --requirepass
#   - DB_PASSWORD vacío
# Avisos (⚠️, no abortan): valores de plantilla, pgbouncer/meilisearch expuestos.

set -u
source "$(dirname "${BASH_SOURCE[0]}")/lib/common.sh"
cd "$(repo_root)"

ERRORS=0
err()  { echo "❌ $*"; ERRORS=$((ERRORS + 1)); }
okk()  { echo "✅ $*"; }
warn() { echo "⚠️  $*"; }

echo "═══════════════════════════════════════════════════════════════"
echo "Validando configuración..."
echo ""

if [[ ! -f compose.env ]]; then
    echo "❌ ERROR: compose.env no existe"
    echo "   Proyecto nuevo:  make init NAME=<slug>   (genera compose.env y .env)"
    echo "   A mano:          cp compose.env.example compose.env"
    exit 1
fi
okk "compose.env existe"

PROJECT=$(get_kv compose.env PROJECT)
if [[ -z "$PROJECT" ]]; then
    err "PROJECT está vacío en compose.env (nombre del proyecto Compose; obligatorio)"
elif [[ ! "$PROJECT" =~ ^[a-z0-9][a-z0-9_-]*$ ]]; then
    err "PROJECT='$PROJECT' no es válido: usa minúsculas, números, '-' o '_'"
fi

echo ""
echo "Configuración actual:"
printf "  %-17s %s\n" "PROJECT:" "$PROJECT"

# ── Puertos ─────────────────────────────────────────────────────────────
declare -A SEEN=()
while IFS= read -r line; do
    key="${line%%=*}"; val="${line#*=}"; val="${val%%#*}"; val="${val//[[:space:]]/}"
    printf "  %-17s %s\n" "${key}:" "$val"
    if [[ ! "$val" =~ ^[0-9]+$ ]] || (( val < 1 || val > 65535 )); then
        err "$key='$val' no es un puerto válido (1-65535)"
    elif [[ -n "${SEEN[$val]:-}" ]]; then
        err "$key=$val repite el puerto de ${SEEN[$val]}"
    else
        SEEN[$val]="$key"
    fi
done < <(grep -E '^[A-Z0-9_]+_PORT=' compose.env)

# ── Contraseñas ─────────────────────────────────────────────────────────
echo ""
DB_PASSWORD=$(get_kv compose.env DB_PASSWORD)
REDIS_PASSWORD=$(get_kv compose.env REDIS_PASSWORD)
[[ -z "$DB_PASSWORD" ]] && err "DB_PASSWORD está vacío en compose.env"
if grep -q -- '--requirepass' docker-compose.yml && [[ -z "$REDIS_PASSWORD" ]]; then
    err "REDIS_PASSWORD está vacío pero docker-compose.yml arranca Redis con --requirepass"
fi

# ── Exposición de puertos en docker-compose.yml ─────────────────────────
# Servicios que SOLO deben escuchar en 127.0.0.1 (datos y herramientas de dev)
LOCAL_ONLY=" postgres redis s3 mailpit "
EXPOSED=$(awk '
    /^services:/ { in_services = 1; next }
    /^[a-z]/ && !/^services:/ { in_services = 0 }
    in_services && /^  [a-z0-9_-]+:/ { svc = $1; sub(":", "", svc); in_ports = 0; next }
    in_services && /^    ports:/ { in_ports = 1; next }
    in_services && /^    [a-z_]+:/ { in_ports = 0 }
    in_services && in_ports && /^      - / {
        line = $0; sub(/^      - /, "", line); gsub(/"/, "", line)
        if (line !~ /^127\.0\.0\.1:/) print svc " " line
    }
' docker-compose.yml)
while read -r svc mapping; do
    [[ -z "${svc:-}" ]] && continue
    if [[ "$LOCAL_ONLY" == *" $svc "* ]]; then
        err "docker-compose.yml: '$svc' publica $mapping en todas las interfaces; debe ser 127.0.0.1:$mapping"
    elif [[ "$svc" == "pgbouncer" || "$svc" == "meilisearch" ]]; then
        warn "docker-compose.yml: '$svc' publica $mapping en todas las interfaces; en un servidor expuesto, ligarlo a 127.0.0.1 o filtrar por firewall"
    fi
done <<< "$EXPOSED"

# ── Valores de plantilla ────────────────────────────────────────────────
if [[ -f compose.env.example && "$PROJECT" == "$(get_kv compose.env.example PROJECT)" ]]; then
    echo ""
    warn "PROJECT=$PROJECT es el de la plantilla. Si es un proyecto nuevo: make init NAME=<slug>"
    echo "   (cambia PROJECT y puertos para no chocar con otros proyectos)."
fi
if [[ "$DB_PASSWORD" == "$(get_kv compose.env.example DB_PASSWORD)" && "$PROJECT" != "apygg" ]]; then
    warn "DB_PASSWORD es el valor de la plantilla; make init genera uno aleatorio."
fi

echo ""
if [[ ! -f .env ]]; then
    warn ".env no existe - se creará desde .env.example (mejor: make init NAME=<slug>)"
else
    okk ".env existe"
fi
echo ""
echo "═══════════════════════════════════════════════════════════════"

if (( ERRORS > 0 )); then
    echo "❌ ${ERRORS} error(es) en la configuración. Corrígelos antes de continuar."
    exit 1
fi
