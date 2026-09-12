#!/bin/bash
#
# create_testdb.sh — Crea la BD de testing aislada del proyecto actual.
#
# Nada está hardcodeado: lee DB_* de .env y PROJECT de compose.env, de modo que
# al duplicar el boilerplate (PROJECT=miapp) la BD de tests pasa a ser
# miapp_test automáticamente.
#
# La BD de testing es SIEMPRE "<DB_DATABASE>_test": los tests nunca tocan la
# base de desarrollo.
#
# Uso:
#   ./create_testdb.sh                          # solo crea la BD si no existe
#   ./create_testdb.sh --migrate                # además migra y siembra datos base
#   ./create_testdb.sh --migrate --parallel=4   # una BD por proceso paralelo
#   make testdb / make testdb-migrate / make testdb-parallel
#
# Nota sobre el seeding: con DatabaseTransactions los tests NO re-siembran por
# cada test. Los datos base se aplican UNA vez aquí y quedan persistentes;
# cada test abre una transacción que se revierte al terminar.

set -e

cd "$(dirname "$0")"

# ── Argumentos ──────────────────────────────────────────────────────────
MIGRATE=0
PARALLEL=0

for arg in "$@"; do
    case "$arg" in
        --migrate)     MIGRATE=1 ;;
        --parallel=*)  PARALLEL="${arg#--parallel=}" ;;
        --parallel)    PARALLEL=4 ;;
        *) echo "Argumento desconocido: $arg"; exit 1 ;;
    esac
done

# ── Leer configuración real desde .env / compose.env ────────────────────
read_env() {
    local key="$1" file="$2" default="$3"
    local value=""
    [[ -f "$file" ]] && value=$(grep -E "^${key}=" "$file" | head -1 | cut -d= -f2- | tr -d '"' || true)
    echo "${value:-$default}"
}

DB_DATABASE=$(read_env DB_DATABASE .env "")
DB_USERNAME=$(read_env DB_USERNAME .env "")
DB_PASSWORD=$(read_env DB_PASSWORD .env "")
DB_HOST=$(read_env DB_HOST .env postgres)
DB_PORT=$(read_env DB_PORT .env 5432)
PROJECT=$(read_env PROJECT compose.env apygg)

if [[ -z "$DB_DATABASE" || -z "$DB_USERNAME" ]]; then
    echo "❌ No se pudo leer DB_DATABASE/DB_USERNAME de .env. ¿Existe el archivo?"
    exit 1
fi

TEST_DB="${DB_DATABASE}_test"
PG_CONTAINER="${PROJECT}_postgres"
APP_CONTAINER="${PROJECT}_app"

echo "Proyecto:    ${PROJECT}"
echo "BD dev:      ${DB_DATABASE}  (no se toca)"
echo "BD testing:  ${TEST_DB}"
echo ""

if ! docker ps --format '{{.Names}}' | grep -q "^${PG_CONTAINER}$"; then
    echo "❌ No existe el contenedor ${PG_CONTAINER}."
    echo "   Verifica PROJECT en compose.env o levanta los servicios con 'make up'."
    exit 1
fi

# ── Bases a preparar ────────────────────────────────────────────────────
# En modo paralelo, cada proceso necesita la suya: si comparten una, se pisan
# los datos entre sí y los tests fallan de forma intermitente.
DATABASES=("${TEST_DB}")

if [[ "${PARALLEL}" -gt 0 ]]; then
    for i in $(seq 1 "${PARALLEL}"); do
        DATABASES+=("${TEST_DB}_${i}")
    done
    echo "Modo paralelo: ${PARALLEL} procesos"
fi

# ── 1. Crear las BD de testing (si no existen) ──────────────────────────
for db in "${DATABASES[@]}"; do
    if docker exec -e PGPASSWORD="${DB_PASSWORD}" "${PG_CONTAINER}" \
        psql -h "${DB_HOST}" -p "${DB_PORT}" -U "${DB_USERNAME}" -d postgres \
        -c "CREATE DATABASE ${db} OWNER ${DB_USERNAME};" >/dev/null 2>&1; then
        echo "✅ BD ${db} creada"
    else
        echo "ℹ️  BD ${db} ya existe (o no se pudo crear)"
    fi
done

# ── 2. (Opcional) Migrar y sembrar la BD de testing ─────────────────────
if [[ "${MIGRATE}" == "1" ]]; then
    if ! docker ps --format '{{.Names}}' | grep -q "^${APP_CONTAINER}$"; then
        echo "❌ No existe el contenedor ${APP_CONTAINER}; no se puede migrar."
        exit 1
    fi

    for db in "${DATABASES[@]}"; do
        echo ""
        echo "Migrando ${db} desde cero..."
        docker exec -e DB_DATABASE="${db}" "${APP_CONTAINER}" php artisan migrate:fresh --force

        echo "Sembrando datos base en ${db}..."
        docker exec -e DB_DATABASE="${db}" "${APP_CONTAINER}" php artisan db:seed --force
    done
fi

echo ""
echo "✅ Listo. 'make test' usará ${TEST_DB}; la BD de desarrollo (${DB_DATABASE}) queda intacta."
