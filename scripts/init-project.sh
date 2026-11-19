#!/usr/bin/env bash
#
# init-project.sh — Convierte una copia de APYGG en un proyecto nuevo, sin colisiones.
#
#   make init NAME=miapp [PORT_BASE=8100] [TITLE="Mi App"] [VENDOR=miorg] [README=1] [YES=1]
#   make wt-env NAME=tarea            # modo worktree: solo entorno aislado (ver abajo)
#
# Qué hace (proyecto):
#   1. Valida NAME y PORT_BASE (o busca un bloque libre de 10 puertos desde 8100).
#   2. Genera compose.env (PROJECT, puertos, UID/GID, contraseñas aleatorias).
#   3. Genera .env (APP_*, DB_*, REDIS, claves APP_KEY/JWT_SECRET/Reverb, admin, S3...).
#   4. Renombra lo que identifica al boilerplate: composer.json (name, description),
#      package.json (name) y, vía APP_TITLE, la ficha raíz y el título de /docs/api.
#   5. Git: si origin apunta a APYGG, lo renombra a `upstream` (push desactivado).
#   NO borra nada del boilerplate ni toca su documentación.
#
# Modo --worktree (make wt-env): para desarrollar el propio boilerplate con varios
# worktrees. Solo crea compose.env y .env con PROJECT=apygg-<NAME>, puertos propios
# (bloque desde 8200) y BD propia. No renombra nada ni toca git.
#
# Es idempotente: si ya se ejecutó con el mismo NAME conserva contraseñas, claves y
# puertos; guarda copia (*.bak-<fecha>) de lo que reescribe. Pide confirmación si ya
# estaba inicializado (salta con --yes / YES=1).
#
# No necesita PHP en el host: las claves se generan con openssl.

set -euo pipefail
source "$(dirname "${BASH_SOURCE[0]}")/lib/common.sh"
cd "$(repo_root)"

NAME=""; PORT_BASE=""; TITLE=""; VENDOR=""; README=0; YES=0; WORKTREE=0

usage() { sed -n '2,26p' "$0" | sed 's/^# \{0,1\}//'; exit "${1:-0}"; }
die() { echo "❌ $*" >&2; exit 1; }

while [[ $# -gt 0 ]]; do
    case "$1" in
        --name)       NAME="${2:-}"; shift 2 ;;
        --port-base)  PORT_BASE="${2:-}"; shift 2 ;;
        --title)      TITLE="${2:-}"; shift 2 ;;
        --vendor)     VENDOR="${2:-}"; shift 2 ;;
        --readme)     README=1; shift ;;
        --yes)        YES=1; shift ;;
        --worktree)   WORKTREE=1; shift ;;
        -h|--help)    usage 0 ;;
        *)            die "Argumento desconocido: $1 (usa --help)" ;;
    esac
done

# ── Validación de entradas ──────────────────────────────────────────────
[[ -n "$NAME" ]] || die "Falta NAME. Uso: make init NAME=miapp [PORT_BASE=8100] [TITLE=\"Mi App\"]"
[[ "$NAME" =~ ^[a-z][a-z0-9-]{1,23}$ && "$NAME" != *- ]] \
    || die "NAME='$NAME' no es válido: minúsculas, números y '-', empieza por letra, 2-24 caracteres."
if (( WORKTREE == 0 )) && [[ "$NAME" == "apygg" ]]; then
    die "NAME=apygg es el nombre del boilerplate y colisionaría con su stack. Elige otro."
fi
if [[ -n "$PORT_BASE" ]]; then
    [[ "$PORT_BASE" =~ ^[0-9]+$ ]] && (( PORT_BASE >= 1024 && PORT_BASE <= 65520 )) \
        || die "PORT_BASE='$PORT_BASE' no es válido (1024-65520); usa 10 puertos consecutivos."
fi
if [[ -n "$TITLE" && "$TITLE" =~ [\"\$\`\\] ]]; then
    die "TITLE no puede contener comillas dobles, \$, \` ni \\."
fi
VENDOR="${VENDOR:-$NAME}"
[[ "$VENDOR" =~ ^[a-z0-9]([a-z0-9_.-]*[a-z0-9])?$ ]] || die "VENDOR='$VENDOR' no es válido para composer (minúsculas, números, - _ .)."
for f in compose.env.example .env.example docker-compose.yml; do
    [[ -f "$f" ]] || die "No encuentro $f: ejecuta esto desde la raíz de un clon de APYGG."
done
command -v openssl >/dev/null || die "Falta openssl en el host (se usa para generar claves)."

if (( WORKTREE )); then
    PROJECT="apygg-${NAME}"; DBSLUG="apygg_${NAME//-/_}"; APP_NAME_VALUE="apygg"; START=8200
else
    PROJECT="$NAME"; DBSLUG="${NAME//-/_}"; APP_NAME_VALUE="$NAME"; START=8100
fi

# ── ¿Ya inicializado? ───────────────────────────────────────────────────
OLD_PROJECT=$(get_kv compose.env PROJECT)
REUSE=0
[[ -n "$OLD_PROJECT" && "$OLD_PROJECT" == "$PROJECT" ]] && REUSE=1
# Título visible: el dado; si es un re-run sin TITLE, el que ya había; si no, el slug.
if [[ -z "$TITLE" ]] && (( REUSE )) && [[ -n "$(get_kv .env APP_TITLE)" ]]; then
    APP_TITLE_VALUE=$(get_kv .env APP_TITLE); TITLE="${APP_TITLE_VALUE% API}"
else
    TITLE="${TITLE:-$NAME}"; APP_TITLE_VALUE="${TITLE} API"
fi
NEEDS_CONFIRM=0
if (( WORKTREE )); then
    [[ -n "$OLD_PROJECT" && "$OLD_PROJECT" != "apygg" && "$OLD_PROJECT" != "$PROJECT" ]] && NEEDS_CONFIRM=1
else
    [[ -n "$OLD_PROJECT" && "$OLD_PROJECT" != "apygg" ]] && NEEDS_CONFIRM=1
fi
if (( NEEDS_CONFIRM && YES == 0 )); then
    echo "⚠️  Este repo ya estaba inicializado (PROJECT=$OLD_PROJECT)."
    if (( REUSE )); then
        echo "   Se reescribirán compose.env y .env conservando contraseñas, claves y puertos."
    else
        echo "   Se reescribirán compose.env y .env con PROJECT=$PROJECT y secretos NUEVOS."
        echo "   Si ya existen volúmenes de '$OLD_PROJECT', quedan huérfanos (make down-v los borra, con el proyecto viejo)."
    fi
    echo "   Se guarda copia de los archivos actuales (*.bak-<fecha>)."
    if [[ ! -t 0 ]]; then die "Sin terminal para confirmar: repite con YES=1."; fi
    read -r -p "¿Continuar? (s/N): " ans
    [[ "$ans" == "s" || "$ans" == "S" ]] || { echo "❌ Cancelado."; exit 1; }
fi

# ── Puertos ─────────────────────────────────────────────────────────────
# Bloque de 10 puertos consecutivos: +0 app, +1 postgres, +2 reverb, +3 redis,
# +4 meilisearch, +5 pgbouncer, +6/+7 mailpit smtp/ui, +8/+9 s3 api/consola.

# Puertos ya reservados por otros proyectos hermanos (compose.env en carpetas vecinas),
# aunque no estén levantados ahora.
declare -A CLAIMED=()
SELF_ENV="$(pwd)/compose.env"
for f in "$(dirname "$(pwd)")"/*/compose.env; do
    [[ -f "$f" && "$(cd "$(dirname "$f")" && pwd)/compose.env" != "$SELF_ENV" ]] || continue
    while IFS='=' read -r k v; do
        v="${v%%#*}"; v="${v//[[:space:]]/}"
        [[ "$v" =~ ^[0-9]+$ ]] && CLAIMED[$v]="$(basename "$(dirname "$f")")"
    done < <(grep -E '^[A-Z0-9_]+_PORT=' "$f" || true)
done

block_busy() { # base → imprime el primer puerto ocupado y devuelve 0; 1 si todo libre
    local p
    for ((p = $1; p < $1 + 10; p++)); do
        if port_listening "$p"; then echo "$p"; return 0; fi
        if [[ -n "${CLAIMED[$p]:-}" ]]; then echo "$p (reservado por ${CLAIMED[$p]})"; return 0; fi
    done
    return 1
}

if [[ -z "$PORT_BASE" ]] && (( REUSE )); then
    PORT_BASE=$(get_kv compose.env APP_PORT)
    [[ "$PORT_BASE" =~ ^[0-9]+$ ]] || PORT_BASE=""
    REUSE_PORTS=1
fi
if [[ -n "$PORT_BASE" ]]; then
    if [[ "${REUSE_PORTS:-0}" != 1 ]] && busy=$(block_busy "$PORT_BASE"); then
        die "PORT_BASE=$PORT_BASE: el puerto $busy no está libre. Elige otra base o quita PORT_BASE para buscar una."
    fi
else
    PORT_BASE=$START
    while busy=$(block_busy "$PORT_BASE"); do
        PORT_BASE=$((PORT_BASE + 10))
        (( PORT_BASE <= 65000 )) || die "No encuentro un bloque libre de 10 puertos."
    done
fi
P_APP=$PORT_BASE;  P_PG=$((PORT_BASE + 1)); P_REVERB=$((PORT_BASE + 2)); P_REDIS=$((PORT_BASE + 3))
P_MEILI=$((PORT_BASE + 4)); P_PGB=$((PORT_BASE + 5)); P_MP_SMTP=$((PORT_BASE + 6)); P_MP_UI=$((PORT_BASE + 7))
P_S3=$((PORT_BASE + 8)); P_S3_CON=$((PORT_BASE + 9))

# ── Secretos (se conservan si es un re-run del mismo proyecto) ──────────
keep() { # archivo clave nuevo → valor existente si REUSE y no está vacío; si no, el nuevo
    local old=""
    (( REUSE )) && old=$(get_kv "$1" "$2")
    echo "${old:-$3}"
}
DB_PASSWORD=$(keep compose.env DB_PASSWORD "$(rand_hex 16)")
REDIS_PASSWORD=$(keep compose.env REDIS_PASSWORD "$(rand_hex 16)")
S3_ACCESS_KEY=$(keep compose.env S3_ACCESS_KEY "${NAME}-$(rand_hex 4)")
S3_SECRET_KEY=$(keep compose.env S3_SECRET_KEY "$(rand_hex 20)")
MEILISEARCH_KEY=$(keep compose.env MEILISEARCH_KEY "$(rand_hex 16)")
APP_KEY=$(keep .env APP_KEY "base64:$(openssl rand -base64 32)")
JWT_SECRET=$(keep .env JWT_SECRET "$(rand_hex 32)")
REVERB_APP_KEY=$(keep .env REVERB_APP_KEY "$(rand_hex 10)")
REVERB_APP_SECRET=$(keep .env REVERB_APP_SECRET "$(rand_hex 16)")
ADMIN_PASSWORD_OLD=""; (( REUSE )) && ADMIN_PASSWORD_OLD=$(get_kv .env ADMIN_PASSWORD)
ADMIN_PASSWORD="${ADMIN_PASSWORD_OLD:-$(openssl rand -base64 24 | tr -d '/+=\n' | cut -c1-18)-A1}"
ADMIN_EMAIL=$(keep .env ADMIN_EMAIL "admin@${NAME}.test")
BUCKET="$NAME"; BUCKET_PRIVATE="${NAME}-private"

# ── Copias de seguridad y generación de compose.env / .env ──────────────
TS=$(date +%Y%m%d-%H%M%S)
for f in compose.env .env; do
    if [[ -f "$f" ]]; then cp -p "$f" "$f.bak-$TS"; echo "ℹ️  Copia de seguridad: $f.bak-$TS"; fi
done

cp compose.env.example compose.env
CE=compose.env
set_kv $CE PROJECT "$PROJECT"
set_kv $CE APP_PORT "$P_APP";           set_kv $CE POSTGRES_PORT "$P_PG"
set_kv $CE REVERB_PORT "$P_REVERB";     set_kv $CE REDIS_PORT "$P_REDIS"
set_kv $CE MEILISEARCH_PORT "$P_MEILI"; set_kv $CE PGBOUNCER_PORT "$P_PGB"
set_kv $CE MAILPIT_SMTP_PORT "$P_MP_SMTP"; set_kv $CE MAILPIT_UI_PORT "$P_MP_UI"
set_kv $CE S3_PORT "$P_S3";             set_kv $CE S3_CONSOLE_PORT "$P_S3_CON"
set_kv $CE USER_ID "$(id -u)";          set_kv $CE GROUP_ID "$(id -g)"
set_kv $CE DB_DATABASE "$DBSLUG";       set_kv $CE DB_USERNAME "$DBSLUG"
set_kv $CE DB_PASSWORD "$DB_PASSWORD";  set_kv $CE REDIS_PASSWORD "$REDIS_PASSWORD"
set_kv $CE S3_ACCESS_KEY "$S3_ACCESS_KEY"; set_kv $CE S3_SECRET_KEY "$S3_SECRET_KEY"
set_kv $CE S3_BUCKET "$BUCKET";         set_kv $CE S3_PRIVATE_BUCKET "$BUCKET_PRIVATE"
set_kv $CE MEILISEARCH_KEY "$MEILISEARCH_KEY"
set_kv $CE DEV "true"
chmod 600 $CE

cp .env.example .env
E=.env
set_kv $E APP_NAME "$APP_NAME_VALUE"
set_kv $E APP_TITLE "\"$APP_TITLE_VALUE\""
set_kv $E APP_KEY "$APP_KEY"
set_kv $E APP_URL "http://localhost:${P_APP}"
set_kv $E DB_DATABASE "$DBSLUG";        set_kv $E DB_USERNAME "$DBSLUG"
set_kv $E DB_PASSWORD "$DB_PASSWORD";   set_kv $E REDIS_PASSWORD "$REDIS_PASSWORD"
set_kv $E JWT_SECRET "$JWT_SECRET"
set_kv $E REVERB_APP_ID "$NAME";        set_kv $E REVERB_APP_KEY "$REVERB_APP_KEY"
set_kv $E REVERB_APP_SECRET "$REVERB_APP_SECRET"; set_kv $E REVERB_PORT "$P_REVERB"
set_kv $E MEILISEARCH_KEY "$MEILISEARCH_KEY"
set_kv $E AWS_ACCESS_KEY_ID "$S3_ACCESS_KEY"; set_kv $E AWS_SECRET_ACCESS_KEY "$S3_SECRET_KEY"
set_kv $E AWS_BUCKET "$BUCKET";         set_kv $E AWS_PRIVATE_BUCKET "$BUCKET_PRIVATE"
set_kv $E AWS_URL "http://localhost:${P_S3}/${BUCKET}"
set_kv $E ADMIN_EMAIL "$ADMIN_EMAIL";   set_kv $E ADMIN_PASSWORD "$ADMIN_PASSWORD"
# Correo de desarrollo: Mailpit (perfil dev; DEV=true en compose.env). Para producción, cambia MAIL_MAILER.
set_kv $E MAIL_MAILER smtp;             set_kv $E MAIL_HOST mailpit
set_kv $E MAIL_PORT 1025;               set_kv $E MAIL_ENCRYPTION null
chmod 600 $E
echo "✅ compose.env y .env generados (PROJECT=$PROJECT, puertos $P_APP-$P_S3_CON)"

# ── Renombrado del boilerplate (solo modo proyecto) ─────────────────────
# Reemplaza la primera línea `    "clave": ...` (indentación de 4) conservando la coma final.
json_set() { # archivo clave valor
    local tmp; tmp=$(mktemp)
    F_KEY="$2" F_VAL="$3" awk '
        BEGIN { k = "    \"" ENVIRON["F_KEY"] "\": "; done = 0 }
        !done && index($0, k) == 1 {
            comma = ($0 ~ /,[[:space:]]*$/) ? "," : ""
            print k "\"" ENVIRON["F_VAL"] "\"" comma; done = 1; next }
        { print }
    ' "$1" > "$tmp"
    cat "$tmp" > "$1"; rm -f "$tmp"
}

if (( WORKTREE == 0 )); then
    json_set composer.json name "${VENDOR}/${NAME}"
    json_set composer.json description "API de ${TITLE}, creada desde el boilerplate APYGG (Laravel 13, FrankenPHP, PostgreSQL, Redis)."
    if grep -q '^    "name": ' package.json; then
        json_set package.json name "$NAME"
    else
        tmp=$(mktemp)
        awk -v n="$NAME" '{ print } /^    "private": true,/ { print "    \"name\": \"" n "\"," }' package.json > "$tmp"
        cat "$tmp" > package.json; rm -f "$tmp"
    fi
    echo "✅ Renombrado: composer.json (${VENDOR}/${NAME}), package.json (${NAME}), APP_TITLE=\"${APP_TITLE_VALUE}\""

    if (( README )); then
        if [[ -e README.project.md ]]; then
            echo "ℹ️  README.project.md ya existe: no se toca."
        else
            cat > README.project.md <<MD
# ${TITLE}

API de ${TITLE}, creada desde el boilerplate APYGG.

## Arrancar en local

\`\`\`bash
make setup     # build, up, composer install, migraciones, BD de tests, S3 local
make doctor    # comprueba que todo está sano
\`\`\`

Credenciales del administrador inicial: \`ADMIN_EMAIL\` / \`ADMIN_PASSWORD\` de \`.env\`.
Documentación del boilerplate: \`README.md\` y \`docs/new-project.md\`.

## Recibir mejoras del boilerplate

\`\`\`bash
make upgrade-check                      # qué commits nuevos hay en upstream
git merge upstream/main                 # y leer docs/upgrading.md
\`\`\`
MD
            echo "✅ README.project.md creado (el README.md del boilerplate no se toca)."
        fi
    fi

    # ── Git ──
    if git rev-parse --git-dir >/dev/null 2>&1; then
        origin_url=$(git remote get-url origin 2>/dev/null || true)
        if [[ -n "$origin_url" ]] && [[ "$origin_url" =~ (^|[/:])[^/:]+/apygg(\.git)?/?$ ]] \
            && ! git remote get-url upstream >/dev/null 2>&1; then
            git remote rename origin upstream >/dev/null
            git remote set-url --push upstream "NO_PUSH_AL_BOILERPLATE"
            branch=$(git symbolic-ref --short -q HEAD || true)
            [[ -n "$branch" ]] && git branch --unset-upstream "$branch" 2>/dev/null || true
            echo "✅ Git: origin ($origin_url) renombrado a 'upstream' (push desactivado)."
            GIT_NOTE=1
        elif git remote get-url upstream >/dev/null 2>&1; then
            echo "ℹ️  Git: ya existe el remoto 'upstream'; no se toca."
        elif [[ -z "$origin_url" ]]; then
            GIT_NOTE=1
        else
            echo "ℹ️  Git: origin ($origin_url) no parece el boilerplate; no se toca."
        fi
    fi
fi

# ── Resumen ─────────────────────────────────────────────────────────────
echo ""
echo "═══════════════════════════════════════════════════════════════"
if (( WORKTREE )); then
    echo " Entorno de worktree listo: PROJECT=$PROJECT"
else
    echo " Proyecto '$NAME' inicializado"
fi
echo "═══════════════════════════════════════════════════════════════"
echo "  API:        http://localhost:${P_APP}   (docs: /docs/api, back-office: /backoffice)"
echo "  Mailpit:    http://localhost:${P_MP_UI}   · Consola S3: http://localhost:${P_S3_CON}"
echo "  BD:         ${DBSLUG} (tests: ${DBSLUG}_test) · puerto host ${P_PG}"
if [[ -n "$ADMIN_PASSWORD_OLD" ]]; then
    echo "  Admin:      $ADMIN_EMAIL (contraseña sin cambios: está en .env)"
else
    echo "  Admin:      $ADMIN_EMAIL"
    echo "  Contraseña: $ADMIN_PASSWORD     <- se muestra solo ahora (también queda en .env)"
fi
echo ""
echo "Siguiente paso:  make setup   (después: make doctor)"
if [[ "${GIT_NOTE:-0}" == 1 ]]; then
    echo ""
    echo "Git: añade el origin de TU proyecto y publica cuando quieras:"
    echo "  git remote add origin <url-de-tu-repo>"
    echo "  git push -u origin $(git symbolic-ref --short -q HEAD || echo main)"
    echo "Mejoras del boilerplate:  make upgrade-check   (git fetch upstream && git merge upstream/main)"
fi
