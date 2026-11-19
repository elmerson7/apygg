#!/usr/bin/env bash
#
# migrate-docker-names.sh [--dry-run] [PROYECTO] — Copia los volúmenes con el nombre antiguo
# (<proyecto>_<vol>, guion bajo) a la nomenclatura actual (<proyecto>-<vol>, guion).
#
#   - Con el stack PARADO (make down). Si algún contenedor usa el volumen viejo, aborta.
#   - Por cada volumen viejo que exista y cuyo nuevo NO exista: crea el nuevo y copia los
#     datos con un contenedor efímero (alpine, cp -a). Nunca borra el viejo.
#   - Idempotente: si el nuevo ya existe, lo salta. --dry-run solo muestra qué haría.
#   - PROYECTO por defecto: PROJECT de compose.env (o "apygg").
#
# Uso: make rename-volumes [DRY=1]   ·   bash scripts/migrate-docker-names.sh --dry-run

set -euo pipefail
cd "$(dirname "$0")/.."

DRY=0
PROJECT=""
for a in "$@"; do
    case "$a" in
        --dry-run|-n) DRY=1 ;;
        -h|--help) sed -n 2,12p "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
        -*) echo "❌ Opción desconocida: $a" >&2; exit 2 ;;
        *) PROJECT="$a" ;;
    esac
done
if [[ -z "$PROJECT" && -f compose.env ]]; then
    PROJECT=$(grep -E '^PROJECT=' compose.env | head -1 | cut -d= -f2- | sed 's/[[:space:]#].*//')
fi
PROJECT="${PROJECT:-apygg}"
[[ "$PROJECT" =~ ^[a-z0-9][a-z0-9_-]*$ ]] || { echo "❌ PROJECT='$PROJECT' no es válido." >&2; exit 2; }
command -v docker >/dev/null && docker info >/dev/null 2>&1 || { echo "❌ Docker no está disponible." >&2; exit 1; }

VOLS="pgdata redisdata meilidata s3data"
((DRY)) && echo "🔎 Modo --dry-run: no se cambia nada."
echo "Proyecto: $PROJECT"

COPIED=0; OLD_LEFT=()
for v in $VOLS; do
    old="${PROJECT}_${v}"; new="${PROJECT}-${v}"
    if ! docker volume inspect "$old" >/dev/null 2>&1; then
        echo "  · $old no existe: nada que migrar"; continue
    fi
    if docker volume inspect "$new" >/dev/null 2>&1; then
        echo "  · $new ya existe: se salta (puedes borrar $old cuando compruebes)"; OLD_LEFT+=("$old"); continue
    fi
    if [[ -n "$(docker ps -aq --filter "volume=$old")" ]]; then
        echo "❌ Hay contenedores usando $old. Para el stack primero: make down" >&2; exit 1
    fi
    if ((DRY)); then
        echo "  → copiaría $old → $new"; COPIED=$((COPIED+1)); OLD_LEFT+=("$old"); continue
    fi
    echo "  → copiando $old → $new ..."
    docker volume create "$new" >/dev/null
    if ! docker run --rm -v "$old":/from:ro -v "$new":/to alpine sh -c 'cp -a /from/. /to/'; then
        docker volume rm "$new" >/dev/null 2>&1 || true
        echo "❌ Falló la copia de $old; se eliminó el $new incompleto. El viejo sigue intacto." >&2; exit 1
    fi
    COPIED=$((COPIED+1)); OLD_LEFT+=("$old")
done

echo "Listo: $COPIED volumen(es) $( ((DRY)) && echo 'por copiar' || echo copiado\(s\))."
if ((${#OLD_LEFT[@]})) && ((! DRY)); then
    echo "Tras levantar (make up) y comprobar que todo funciona, borra los viejos con:"
    echo "  docker volume rm ${OLD_LEFT[*]}"
fi
