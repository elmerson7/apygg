#!/usr/bin/env bash
#
# doctor.sh — Diagnostica el entorno local del proyecto y explica cómo arreglarlo.
#
#   make doctor
#
# Solo lee: no cambia nada. Código de salida 1 si algún chequeo falla (❌);
# los avisos (⚠️) no cuentan como fallo.

set -u
source "$(dirname "${BASH_SOURCE[0]}")/lib/common.sh"
cd "$(repo_root)"

FAILS=0; WARNS=0
ok()   { echo "✅ $*"; }
fail() { echo "❌ $1"; [[ -n "${2:-}" ]] && echo "   → $2"; FAILS=$((FAILS + 1)); }
warn() { echo "⚠️  $1"; [[ -n "${2:-}" ]] && echo "   → $2"; WARNS=$((WARNS + 1)); }
info() { echo "ℹ️  $*"; }
section() { echo ""; echo "── $* ──"; }

echo "═══════════════════════════════════════════════════════════════"
echo " make doctor"
echo "═══════════════════════════════════════════════════════════════"

# ── 1. Herramientas ─────────────────────────────────────────────────────
section "Herramientas"
DOCKER_OK=0
if ! command -v docker >/dev/null 2>&1; then
    fail "docker no está instalado" "Instala Docker Engine: https://docs.docker.com/engine/install/"
elif ! docker info >/dev/null 2>&1; then
    fail "docker está instalado pero el demonio no responde (¿permisos o servicio parado?)" \
         "sudo systemctl start docker  y  sudo usermod -aG docker \$USER (luego reabre la sesión)"
elif ! docker compose version >/dev/null 2>&1; then
    fail "falta el plugin 'docker compose' (v2)" "Instala docker-compose-plugin"
else
    DOCKER_OK=1; ok "docker $(docker version -f '{{.Server.Version}}' 2>/dev/null) y $(docker compose version --short 2>/dev/null)"
fi
command -v openssl >/dev/null 2>&1 && ok "openssl disponible (lo usa make init)" || warn "falta openssl" "Lo necesita make init para generar claves"

# ── 2. Archivos de entorno ──────────────────────────────────────────────
section "Configuración"
HAVE_CE=0; HAVE_ENV=0
if [[ -f compose.env ]]; then HAVE_CE=1; ok "compose.env existe"; else
    fail "compose.env no existe" "make init NAME=<slug>   (o: cp compose.env.example compose.env y edítalo)"; fi
if [[ -f .env ]]; then HAVE_ENV=1; ok ".env existe"; else
    fail ".env no existe" "make init NAME=<slug>   (o: cp .env.example .env)"; fi

PROJECT=$(get_kv compose.env PROJECT)
if (( HAVE_CE )); then
    if [[ -z "$PROJECT" ]]; then
        fail "PROJECT vacío en compose.env" "Pon un nombre único (make init NAME=<slug> lo hace)"
    elif [[ "$PROJECT" == "apygg" ]]; then
        origin=$(git remote get-url origin 2>/dev/null || true)
        if [[ "$origin" =~ (^|[/:])[^/:]+/apygg(\.git)?/?$ ]] && ! git remote get-url upstream >/dev/null 2>&1; then
            info "PROJECT=apygg: correcto en el propio boilerplate"
        else
            fail "PROJECT=apygg en un proyecto derivado: colisionará con el stack del boilerplate" \
                 "make init NAME=<slug>   (cambia PROJECT, puertos y contraseñas)"
        fi
    else
        ok "PROJECT=$PROJECT"
    fi
fi

# ── 3. Coherencia compose.env ↔ .env ────────────────────────────────────
if (( HAVE_CE && HAVE_ENV )); then
    section "Coherencia compose.env / .env"
    same() { # etiqueta valor_compose valor_env
        if [[ "$2" == "$3" ]]; then ok "$1 coincide"; else
            fail "$1 difiere entre compose.env y .env" "Deben ser iguales (Postgres/Redis se crean con compose.env, la app se conecta con .env). Tras corregir: make reload"; fi
    }
    same "DB_DATABASE" "$(get_kv compose.env DB_DATABASE)" "$(get_kv .env DB_DATABASE)"
    same "DB_USERNAME" "$(get_kv compose.env DB_USERNAME)" "$(get_kv .env DB_USERNAME)"
    same "DB_PASSWORD" "$(get_kv compose.env DB_PASSWORD)" "$(get_kv .env DB_PASSWORD)"
    same "REDIS_PASSWORD" "$(get_kv compose.env REDIS_PASSWORD)" "$(get_kv .env REDIS_PASSWORD)"
    same "REVERB_PORT" "$(get_kv compose.env REVERB_PORT)" "$(get_kv .env REVERB_PORT)"
    if [[ "$(get_kv compose.env DEV)" == "true" ]]; then
        same "S3_BUCKET / AWS_BUCKET" "$(get_kv compose.env S3_BUCKET)" "$(get_kv .env AWS_BUCKET)"
        same "S3_ACCESS_KEY / AWS_ACCESS_KEY_ID" "$(get_kv compose.env S3_ACCESS_KEY)" "$(get_kv .env AWS_ACCESS_KEY_ID)"
    fi
    app_url_port=$(get_kv .env APP_URL | sed -nE 's#^https?://[^/:]+:([0-9]+).*#\1#p')
    if [[ -n "$app_url_port" && "$app_url_port" != "$(get_kv compose.env APP_PORT)" ]]; then
        warn "APP_URL usa el puerto $app_url_port pero APP_PORT=$(get_kv compose.env APP_PORT)" "Ajusta APP_URL en .env"
    fi
fi

if (( HAVE_ENV )); then
    [[ -n "$(get_kv .env APP_KEY)" ]] && ok "APP_KEY presente" || \
        fail "APP_KEY vacía: la app no arranca ni cifra" "make init NAME=<slug>   (o: make key y pégala en .env)"
    [[ -n "$(get_kv .env JWT_SECRET)" ]] && ok "JWT_SECRET presente" || \
        fail "JWT_SECRET vacío: el login JWT fallará" "make init NAME=<slug>   (o: make jwt)"
fi

# ── 4. Puertos ──────────────────────────────────────────────────────────
if (( HAVE_CE )); then
    section "Puertos"
    OURS=""
    (( DOCKER_OK )) && OURS=$(docker ps --filter "name=^${PROJECT}-" --format '{{.Ports}}' 2>/dev/null || true)
    BUSY=0
    while IFS='=' read -r key val; do
        val="${val%%#*}"; val="${val//[[:space:]]/}"
        [[ "$val" =~ ^[0-9]+$ ]] || continue
        if port_listening "$val"; then
            if [[ "$OURS" == *":${val}->"* ]]; then continue; fi
            fail "$key=$val está ocupado por otro proceso" \
                 "Cambia el puerto en compose.env (o rehaz con make init NAME=$PROJECT PORT_BASE=<otro>). Quién lo usa: ss -ltnp 'sport = :$val'"
            BUSY=1
        fi
    done < <(grep -E '^[A-Z0-9_]+_PORT=' compose.env)
    (( BUSY )) || ok "Ningún puerto de compose.env choca con otro proceso"
fi

# ── 5. Contenedores ─────────────────────────────────────────────────────
STACK_UP=0
if (( DOCKER_OK && HAVE_CE )) && [[ -n "$PROJECT" ]]; then
    section "Contenedores ($PROJECT)"
    SERVICES="app postgres redis reverb horizon scheduler"
    [[ "$(get_kv compose.env DEV)" == "true" ]] && SERVICES="$SERVICES mailpit s3"
    for svc in $SERVICES; do
        c="${PROJECT}-${svc}"
        st=$(docker inspect -f '{{.State.Status}} {{if .State.Health}}{{.State.Health.Status}}{{end}}' "$c" 2>/dev/null || true)
        if [[ -z "$st" ]]; then
            fail "$c no existe" "make setup   (primera vez)  ·  make up   (si ya estaba instalado)"
        elif [[ "$st" == "running healthy" || "$st" == "running " ]]; then
            ok "$c arriba${st:+ (${st#running })}"; [[ "$svc" == "postgres" ]] && STACK_UP=1
        elif [[ "$st" == "running starting" ]]; then
            warn "$c arrancando todavía" "Espera unos segundos y repite make doctor"
        else
            fail "$c: $st" "make logs  ·  make restart service=$svc"
        fi
    done
fi

# ── 6. Base de datos ────────────────────────────────────────────────────
if (( STACK_UP && HAVE_ENV )); then
    section "Base de datos"
    DBN=$(get_kv .env DB_DATABASE); DBU=$(get_kv .env DB_USERNAME); DBP=$(get_kv .env DB_PASSWORD)
    pg() { docker exec -e PGPASSWORD="$DBP" "${PROJECT}-postgres" psql -U "$DBU" -d "$1" -tAc "$2" 2>/dev/null; }
    FILES=$(ls database/migrations/*.php 2>/dev/null | wc -l)
    for db in "$DBN" "${DBN}_test"; do
        if [[ "$(pg postgres "SELECT 1 FROM pg_database WHERE datname='${db}'")" != "1" ]]; then
            if [[ "$db" == *_test ]]; then
                fail "La BD de tests '$db' no existe" "make testdb-migrate"
            else
                fail "La BD '$db' no existe" "Si el volumen es antiguo: make down-v && make setup (borra datos)"
            fi
            continue
        fi
        rows=$(pg "$db" "SELECT count(*) FROM migrations" || true)
        if [[ ! "$rows" =~ ^[0-9]+$ ]]; then
            fail "'$db' existe pero sin migrar" "$([[ "$db" == *_test ]] && echo 'make testdb-migrate' || echo 'make migrate')"
        elif (( rows < FILES )); then
            fail "'$db' tiene migraciones pendientes ($rows aplicadas de $FILES archivos)" \
                 "$([[ "$db" == *_test ]] && echo 'make testdb-migrate' || echo 'make migrate')"
        else
            ok "'$db' migrada ($rows migraciones)"
        fi
    done
fi

# ── 7. Dependencias PHP (composer) ──────────────────────────────────────
section "Dependencias (composer)"
if [[ ! -f vendor/autoload.php ]]; then
    fail "vendor/ no está instalado" "make setup   (o: make composer cmd=install)"
else
    # packages.php: caché de auto-descubrimiento de proveedores. Si lista un paquete
    # que ya no está en vendor/ (p. ej. tras quitar Socialite), Laravel falla al arrancar
    # con "Class ...ServiceProvider not found".
    PK=bootstrap/cache/packages.php
    if [[ -f "$PK" ]]; then
        missing=""
        while read -r pkg; do
            [[ -d "vendor/$pkg" ]] || missing="$missing $pkg"
        done < <(grep -oE "^ +'[a-z0-9_.-]+/[a-z0-9_.-]+' =>" "$PK" | sed -E "s/^ +'([^']+)' =>.*/\1/")
        if [[ -n "$missing" ]]; then
            fail "bootstrap/cache/packages.php lista paquetes que ya no existen:${missing}" \
                 "rm bootstrap/cache/packages.php bootstrap/cache/services.php && make composer cmd=dump-autoload"
        elif [[ vendor/composer/installed.json -nt "$PK" ]]; then
            warn "bootstrap/cache/packages.php es anterior al último composer install (puede estar desfasado)" \
                 "rm bootstrap/cache/packages.php bootstrap/cache/services.php && make composer cmd=dump-autoload"
        else
            ok "bootstrap/cache/packages.php coherente con vendor/"
        fi
    else
        ok "bootstrap/cache/packages.php no existe (se regenerará solo)"
    fi

    # vendor/ frente a composer.lock: simula la instalación dentro del contenedor.
    if (( STACK_UP )); then
        drift=$(docker compose --project-name "$PROJECT" --env-file compose.env exec -T app \
            composer install --dry-run --no-interaction --no-scripts 2>&1 | grep -E '^\s*- (Installing|Upgrading|Downgrading|Removing)' || true)
        if [[ -n "$drift" ]]; then
            fail "vendor/ está desfasado respecto a composer.lock ($(echo "$drift" | wc -l) paquetes distintos)" "make composer cmd=install"
        else
            ok "vendor/ coincide con composer.lock"
        fi
    elif [[ vendor/composer/installed.json -ot composer.lock ]]; then
        warn "vendor/ es anterior a composer.lock (puede estar desfasado)" "make composer cmd=install"
    else
        ok "vendor/ al día respecto a composer.lock (comprobación por fecha; con el stack arriba es exacta)"
    fi
fi

# ── Resumen ─────────────────────────────────────────────────────────────
echo ""
echo "═══════════════════════════════════════════════════════════════"
if (( FAILS > 0 )); then
    echo "❌ ${FAILS} problema(s), ${WARNS} aviso(s). Arregla lo marcado con → y repite make doctor."
    exit 1
fi
if (( WARNS > 0 )); then echo "✅ Sin errores (${WARNS} aviso(s) arriba)."; else echo "✅ Todo en orden."; fi
