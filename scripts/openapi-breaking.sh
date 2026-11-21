#!/usr/bin/env bash
# Comprueba que docs/openapi.json NO introduce cambios que rompen a las apps ya publicadas (oasdiff, en Docker).
#
#   scripts/openapi-breaking.sh [<ref-base>]      (por defecto: main; `make openapi-breaking [BASE=<ref>]`)
#
# Compara el docs/openapi.json de la rama base con el de la copia de trabajo y FALLA (código 1) si hay cambios
# que rompen (quitar o renombrar rutas, campos o valores, hacer obligatorio lo opcional...). Política y cómo
# deprecar con seguridad: docs/api-routes-conventions.md ("Versiones y apps móviles").
#
# Escapes explícitos para un cambio INTENCIONADO:
#   - docs/openapi-breaking-ignore.txt: una línea por cambio aceptado, con su motivo en un comentario (queda en git).
#   - ALLOW_BREAKING=1: lo permite todo (en CI lo activa la etiqueta `breaking-change-approved` del PR).
# Variables: OASDIFF_IMAGE (versión fija; nunca latest), OPENAPI_FILE (por defecto docs/openapi.json).
set -euo pipefail
cd "$(dirname "$0")/.."

BASE="${1:-main}"
FILE="${OPENAPI_FILE:-docs/openapi.json}"
IGNORE="docs/openapi-breaking-ignore.txt"
IMAGE="${OASDIFF_IMAGE:-tufin/oasdiff:v1.11.7}"

TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

if ! git rev-parse --verify --quiet "$BASE^{commit}" >/dev/null; then
    echo "No existe la referencia base «$BASE» (¿falta 'git fetch'?)." >&2
    exit 2
fi

if ! git show "$BASE:$FILE" > "$TMP/base.json" 2>/dev/null; then
    echo "«$BASE» no tiene $FILE: no hay contra qué comparar (primer contrato). OK."
    exit 0
fi

cp "$FILE" "$TMP/revision.json"
args=(breaking /w/base.json /w/revision.json --fail-on ERR)
if [ -f "$IGNORE" ]; then
    cp "$IGNORE" "$TMP/ignore.txt"
    args+=(--err-ignore /w/ignore.txt)
fi

echo "→ oasdiff ($IMAGE): $BASE -> copia de trabajo"
rc=0
docker run --rm -u "$(id -u):$(id -g)" -v "$TMP":/w "$IMAGE" "${args[@]}" || rc=$?

if [ "$rc" -ne 0 ]; then
    if [ "${ALLOW_BREAKING:-0}" = 1 ]; then
        echo "⚠ Hay cambios que rompen, pero ALLOW_BREAKING=1 los permite (aprobación explícita)." >&2
        exit 0
    fi
    cat >&2 <<MSG

✖ El contrato introduce cambios que rompen a las apps ya publicadas.
  - Lo normal: no los hagas; añade (campos, rutas) en vez de quitar o renombrar (docs/api-routes-conventions.md).
  - Si es intencionado: añade la línea al archivo $IGNORE con su motivo, o usa ALLOW_BREAKING=1 / etiqueta
    «breaking-change-approved» en el PR.
MSG
    exit "$rc"
fi

echo "✔ Sin cambios que rompen."
