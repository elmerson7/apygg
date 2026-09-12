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
#   ./create_testdb.sh            # solo crea la BD si no existe
#   ./create_testdb.sh --migrate  # además migra desde cero y siembra datos base
#   make testdb / make testdb-migrate
#
# Nota sobre el seeding: con DatabaseTransactions los tests NO re-siembran por
# cada test. Los datos base se aplican UNA vez aquí y quedan persistentes;
# cada test abre una transacción que se revierte al terminar.

set -e

cd "$(dirname "$0")"

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

# ── 1. Crear la BD de testing (si no existe) ────────────────────────────
if docker exec -e PGPASSWORD="${DB_PASSWORD}" "${PG_CONTAINER}" \
    psql -h "${DB_HOST}" -p "${DB_PORT}" -U "${DB_USERNAME}" -d postgres \
    -c "CREATE DATABASE ${TEST_DB} OWNER ${DB_USERNAME};" >/dev/null 2>&1; then
    echo "✅ BD ${TEST_DB} creada"
else
    echo "ℹ️  BD ${TEST_DB} ya existe (o no se pudo crear)"
fi

# ── 2. (Opcional) Migrar y sembrar la BD de testing ─────────────────────
if [[ "${1:-}" == "--migrate" ]]; then
    if ! docker ps --format '{{.Names}}' | grep -q "^${APP_CONTAINER}$"; then
        echo "❌ No existe el contenedor ${APP_CONTAINER}; no se puede migrar."
        exit 1
    fi

    echo ""
    echo "Migrando ${TEST_DB} desde cero..."
    docker exec -e DB_DATABASE="${TEST_DB}" "${APP_CONTAINER}" php artisan migrate:fresh --force

    echo "Sembrando datos base..."
    docker exec -e DB_DATABASE="${TEST_DB}" "${APP_CONTAINER}" php artisan db:seed --force
fi

echo ""
echo "✅ Listo. 'make test' usará ${TEST_DB}; la BD de desarrollo (${DB_DATABASE}) queda intacta."
