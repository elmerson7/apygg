#!/bin/bash
# prune-images.sh — Retención local de imágenes Docker del proyecto
#
# Limpia imágenes huérfanas <prefijo>-<hash> más antiguas que UNTIL y sin
# containers asociados. Pensado para servidores donde cada deploy deja una
# imagen nueva y el disco se llena en semanas.
#
# Uso:
#   ./scripts/prune-images.sh               # prune real (ventana 72h)
#   ./scripts/prune-images.sh --dry-run     # solo simula, no borra
#   ./scripts/prune-images.sh --until 168h  # ventana custom (168h = 7 días)
#   DRY_RUN=1 ./scripts/prune-images.sh     # alternativa vía env var
#   make prune-images                       # wrapper del Makefile (pide confirmación)
#   make prune-images DRY_RUN=1             # dry-run vía Makefile
#
# Configuración (env vars o compose.env):
#   APP_IMAGE     Repositorio de la imagen (ej: ghcr.io/org/api). Obligatorio.
#   TAG_PREFIXES  Prefijos de tag a considerar, separados por espacio (default: "main dev")
#   UNTIL         Ventana de retención (default: 72h)
#   MIN_KEEP      Tags recientes que nunca se borran (default: 2, para rollback)
#
# Garantías de seguridad:
#   - Nunca borra imágenes con containers asociados (docker ps + filter ancestor)
#   - Nunca ejecuta `docker system prune --volumes` (destruiría pgdata/meilidata)
#   - Solo actúa sobre <repo>:<prefijo>-<hash>; no toca :latest ni tags flotantes
#   - Conserva siempre las MIN_KEEP más recientes, aunque superen la ventana
#   - Si no puede determinar la fecha de una imagen, NO la borra
#
# Log: /var/log/prune-images.log si es escribible, si no storage/logs/prune-images.log

set -euo pipefail

cd "$(dirname "$0")/.."

# ── Config ──────────────────────────────────────────────────────────────
# APP_IMAGE puede venir del entorno o de compose.env
if [[ -z "${APP_IMAGE:-}" ]] && [[ -f compose.env ]]; then
    APP_IMAGE=$(grep -E '^APP_IMAGE=' compose.env | head -1 | cut -d= -f2- || true)
fi

IMAGE_REPO="${APP_IMAGE:-}"
TAG_PREFIXES="${TAG_PREFIXES:-main dev}"
UNTIL="${UNTIL:-72h}"
MIN_KEEP="${MIN_KEEP:-2}"
DRY_RUN="${DRY_RUN:-0}"
LOG_FILE="/var/log/prune-images.log"
FALLBACK_LOG="storage/logs/prune-images.log"

# ── Args ────────────────────────────────────────────────────────────────
while [[ $# -gt 0 ]]; do
    case "$1" in
        --dry-run|-n) DRY_RUN=1; shift ;;
        --until)      UNTIL="$2"; shift 2 ;;
        --until=*)    UNTIL="${1#--until=}"; shift ;;
        --image)      IMAGE_REPO="$2"; shift 2 ;;
        --image=*)    IMAGE_REPO="${1#--image=}"; shift ;;
        --help|-h)
            sed -n '2,30p' "$0"
            exit 0
            ;;
        *)
            echo "Argumento desconocido: $1 (usa --help)" >&2
            exit 1
            ;;
    esac
done

if [[ -z "$IMAGE_REPO" ]]; then
    echo "ERROR: define APP_IMAGE (env o compose.env) o pasa --image <repo>" >&2
    echo "       Ejemplo: APP_IMAGE=ghcr.io/miorg/apygg ./scripts/prune-images.sh --dry-run" >&2
    exit 1
fi

# ── Ventana de retención → epoch de corte ───────────────────────────────
until_to_seconds() {
    local val="$1"
    local num="${val%[hmsd]*}"
    local unit="${val: -1}"
    case "$unit" in
        s) echo "$num" ;;
        m) echo $((num * 60)) ;;
        h) echo $((num * 3600)) ;;
        d) echo $((num * 86400)) ;;
        *) echo $((num * 3600)) ;;  # por defecto, horas
    esac
}

UNTIL_SECONDS=$(until_to_seconds "$UNTIL")
CUTOFF_EPOCH=$(( $(date +%s) - UNTIL_SECONDS ))

# ── Log helper ──────────────────────────────────────────────────────────
if ! touch "$LOG_FILE" 2>/dev/null; then
    mkdir -p "$(dirname "$FALLBACK_LOG")" 2>/dev/null || true
    LOG_FILE="$FALLBACK_LOG"
fi

log() {
    local msg="[$(date '+%Y-%m-%d %H:%M:%S')] $*"
    echo "$msg" | tee -a "$LOG_FILE" 2>/dev/null || echo "$msg"
}

# ── Pre-checks ──────────────────────────────────────────────────────────
if ! command -v docker >/dev/null 2>&1; then
    log "ERROR: docker no encontrado en PATH"
    exit 1
fi

if ! docker info >/dev/null 2>&1; then
    log "WARN: 'docker info' falló — ¿el daemon está activo? Continuando en modo listado."
fi

log "═══════════════════════════════════════════════════════════"
log " prune-images.sh — inicio (REPO=$IMAGE_REPO, UNTIL=$UNTIL, DRY_RUN=$DRY_RUN)"
log " Prefijos: $TAG_PREFIXES | MIN_KEEP=$MIN_KEEP"
log " Corte: $(date -d "@$CUTOFF_EPOCH" '+%Y-%m-%d %H:%M:%S' 2>/dev/null || echo "epoch $CUTOFF_EPOCH")"
log " Log: $LOG_FILE"

# ── Imágenes en uso (protegidas) ────────────────────────────────────────
PROTECTED=()
while IFS= read -r img; do
    [[ -z "$img" ]] && continue
    PROTECTED+=("$img")
    id=$(docker inspect --format '{{.Id}}' "$img" 2>/dev/null || true)
    [[ -n "$id" ]] && PROTECTED+=("$id")
done < <(docker ps --format '{{.Image}}' 2>/dev/null || true)

for cid in $(docker ps -q 2>/dev/null || true); do
    iid=$(docker inspect --format '{{.Image}}' "$cid" 2>/dev/null || true)
    [[ -n "$iid" ]] && PROTECTED+=("$iid")
done

is_protected() {
    local target_id="$1" target_tag="$2"
    for p in "${PROTECTED[@]:-}"; do
        [[ "$p" == "$target_id" || "$p" == "$target_tag" ]] && return 0
    done
    return 1
}

# ── Recolectar imágenes <repo>:<prefijo>-<hash> ─────────────────────────
# Se construye un regex con los prefijos configurados.
PREFIX_RE=$(echo "$TAG_PREFIXES" | tr ' ' '|')
REPO_RE=$(printf '%s' "$IMAGE_REPO" | sed 's/[.[\*^$()+?{}|]/\\&/g')

FOUND=()
while IFS= read -r line; do
    [[ -z "$line" ]] && continue
    repo_tag=$(echo "$line" | awk '{print $1}')
    img_id=$(echo "$line" | awk '{print $2}')

    if [[ "$repo_tag" =~ ^${REPO_RE}:(${PREFIX_RE})-.+ ]]; then
        created_str=$(echo "$line" | cut -d' ' -f3-)
        created_epoch=$(date -d "$created_str" +%s 2>/dev/null || echo "0")
        FOUND+=("$created_epoch $repo_tag $img_id $created_str")
    fi
done < <(docker images --format '{{.Repository}}:{{.Tag}} {{.ID}} {{.CreatedAt}}' 2>/dev/null || true)

# Ordenar por fecha descendente (más recientes primero)
SORTED=()
if [[ ${#FOUND[@]} -gt 0 ]]; then
    while IFS= read -r l; do SORTED+=("$l"); done < <(printf '%s\n' "${FOUND[@]}" | sort -nr)
fi

log "Imágenes ${IMAGE_REPO}:[${PREFIX_RE}]-* encontradas: ${#SORTED[@]}"

# ── MIN_KEEP: proteger las N más recientes ──────────────────────────────
KEEP=()
for i in "${!SORTED[@]}"; do
    if [[ $i -lt $MIN_KEEP ]]; then
        repo_tag=$(echo "${SORTED[$i]}" | awk '{print $2}')
        img_id=$(echo "${SORTED[$i]}" | awk '{print $3}')
        KEEP+=("$img_id" "$repo_tag")
        log "  · protegida por MIN_KEEP: $repo_tag ($img_id)"
    fi
done

is_kept() {
    local tid="$1" ttag="$2"
    for k in "${KEEP[@]:-}"; do
        [[ "$k" == "$tid" || "$k" == "$ttag" ]] && return 0
    done
    return 1
}

# ── Selección de candidatas ─────────────────────────────────────────────
CANDIDATES=()
CANDIDATE_TAGS=()

for entry in "${SORTED[@]:-}"; do
    [[ -z "$entry" ]] && continue
    created_epoch=$(echo "$entry" | awk '{print $1}')
    repo_tag=$(echo "$entry" | awk '{print $2}')
    img_id=$(echo "$entry" | awk '{print $3}')
    created_str=$(echo "$entry" | cut -d' ' -f4-)

    is_kept "$img_id" "$repo_tag" && continue

    if is_protected "$img_id" "$repo_tag"; then
        log "  · protegida (en uso): $repo_tag ($img_id)"
        continue
    fi

    # Doble verificación: algún container (incluso detenido) la usa como ancestro
    if docker ps -a --filter "ancestor=$img_id" --format '{{.ID}}' 2>/dev/null | grep -q . \
       || docker ps -a --filter "ancestor=$repo_tag" --format '{{.ID}}' 2>/dev/null | grep -q .; then
        log "  · protegida (ancestor de un container): $repo_tag ($img_id)"
        continue
    fi

    if [[ "$created_epoch" -eq 0 ]]; then
        log "  · WARN no se pudo parsear la fecha de $repo_tag — se conserva por seguridad"
        continue
    fi

    if [[ "$created_epoch" -gt "$CUTOFF_EPOCH" ]]; then
        log "  · conservada (más nueva que $UNTIL): $repo_tag"
        continue
    fi

    CANDIDATES+=("$img_id")
    CANDIDATE_TAGS+=("$repo_tag")
    log "  · CANDIDATA: $repo_tag ($img_id) creada $created_str"
done

UNIQUE=()
if [[ ${#CANDIDATES[@]} -gt 0 ]]; then
    while IFS= read -r l; do UNIQUE+=("$l"); done < <(printf '%s\n' "${CANDIDATES[@]}" | sort -u)
fi

log "Total candidatas: ${#UNIQUE[@]}"

if [[ ${#UNIQUE[@]} -eq 0 ]]; then
    log "Nada para borrar."
    docker system df 2>/dev/null | tee -a "$LOG_FILE" >/dev/null || true
    log "prune-images.sh — fin (sin cambios)"
    log "═══════════════════════════════════════════════════════════"
    exit 0
fi

# ── Estimación de espacio (tamaño real de cada imagen) ──────────────────
ESTIMATED=$(docker images --format '{{.ID}} {{.Size}}' 2>/dev/null \
    | awk -v ids="$(printf '%s ' "${UNIQUE[@]}")" '
        BEGIN { split(ids, a, " "); for (i in a) want[a[i]] = 1 }
        want[$1] { print $2 }' | paste -sd' ' - || true)
log "Tamaños de las candidatas: ${ESTIMATED:-n/d}"
log "Estado actual:"
docker system df 2>/dev/null | tee -a "$LOG_FILE" >/dev/null || true

# ── Dry-run ─────────────────────────────────────────────────────────────
if [[ "$DRY_RUN" == "1" ]]; then
    log ""
    log "DRY-RUN — no se borra nada. Se ejecutaría:"
    for tag in "${CANDIDATE_TAGS[@]}"; do
        log "  docker rmi $tag"
    done
    log "  docker builder prune -f --filter until=$UNTIL"
    log ""
    log "Para ejecutarlo de verdad: ./scripts/prune-images.sh --until $UNTIL  (o make prune-images)"
    log "prune-images.sh — fin (dry-run)"
    log "═══════════════════════════════════════════════════════════"
    exit 0
fi

# ── Borrado ─────────────────────────────────────────────────────────────
log ""
log "Borrando ${#UNIQUE[@]} imagen(es)..."
DELETED=0
FAILED=0

for img_id in "${UNIQUE[@]}"; do
    rtag=$(docker images --format '{{.Repository}}:{{.Tag}} {{.ID}}' 2>/dev/null | awk -v id="$img_id" '$2==id {print $1; exit}')
    rtag=${rtag:-$img_id}

    if docker rmi "$img_id" >>"$LOG_FILE" 2>&1; then
        log "  ✓ borrada: $rtag"
        DELETED=$((DELETED + 1))
    else
        log "  ! fallo al borrar $rtag ($img_id) — en uso o ya borrada"
        FAILED=$((FAILED + 1))
    fi
done

log "Borradas: $DELETED / ${#UNIQUE[@]} (fallos: $FAILED)"

# ── Builder cache (nunca volúmenes) ─────────────────────────────────────
log ""
log "Limpiando build cache anterior a $UNTIL..."
docker builder prune -f --filter "until=$UNTIL" >>"$LOG_FILE" 2>&1 && log "  ✓ builder prune" || log "  ! builder prune falló"

# NUNCA: docker system prune --volumes  → borraría pgdata, redisdata, meilidata.

log ""
log "Estado final:"
docker system df 2>/dev/null | tee -a "$LOG_FILE" >/dev/null || true

log "prune-images.sh — fin (borradas $DELETED, fallos $FAILED)"
log "═══════════════════════════════════════════════════════════"
exit 0
