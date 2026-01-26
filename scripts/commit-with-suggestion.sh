#!/bin/bash
#
# Helper script para hacer commit con formato Conventional Commits
# Si el mensaje no sigue el formato, sugiere corrección automática
#

set -e

# Colores
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m'

# Obtener mensaje del usuario
if [ -z "$1" ]; then
    echo -e "${YELLOW}Uso: $0 \"mensaje de commit\"${NC}"
    echo ""
    echo -e "${YELLOW}Ejemplos:${NC}"
    echo "  $0 \"Add git hooks\""
    echo "  $0 \"Fix bug in api\""
    echo "  $0 \"feat: add git hooks\""
    exit 1
fi

ORIGINAL_MSG="$1"

# Verificar si ya sigue el formato Conventional Commits
if echo "$ORIGINAL_MSG" | grep -qE '^(feat|fix|docs|style|refactor|test|chore|perf|ci|build|revert)(\(.+\))?: .{1,}'; then
    # Ya está en formato correcto
    git commit -m "$ORIGINAL_MSG"
    exit 0
fi

# Intentar corregir automáticamente
FIRST_WORD=$(echo "$ORIGINAL_MSG" | awk '{print $1}')
SUGGESTED_TYPE=""

case "$FIRST_WORD" in
    "Add"|"Adds"|"Added"|"Implement"|"Implements"|"Implemented"|"Create"|"Creates"|"Created")
        SUGGESTED_TYPE="feat"
        ;;
    "Fix"|"Fixes"|"Fixed"|"Correct"|"Corrects"|"Corrected"|"Repair"|"Repairs"|"Repaired")
        SUGGESTED_TYPE="fix"
        ;;
    "Update"|"Updates"|"Updated"|"Improve"|"Improves"|"Improved"|"Refactor"|"Refactors"|"Refactored")
        SUGGESTED_TYPE="refactor"
        ;;
    "Document"|"Documents"|"Documented"|"Docs")
        SUGGESTED_TYPE="docs"
        ;;
    "Test"|"Tests"|"Testing")
        SUGGESTED_TYPE="test"
        ;;
    "Configure"|"Configures"|"Configured"|"Setup"|"Setups"|"Set")
        SUGGESTED_TYPE="chore"
        ;;
esac

if [ -n "$SUGGESTED_TYPE" ]; then
    REST_OF_MSG=$(echo "$ORIGINAL_MSG" | sed -E "s/^[^[:space:]]+[[:space:]]+//" | tr '[:upper:]' '[:lower:]')
    if echo "$FIRST_WORD" | grep -qiE "^(Add|Adds|Added)$"; then
        REST_OF_MSG="add ${REST_OF_MSG}"
    fi
    SUGGESTED_MSG="${SUGGESTED_TYPE}: ${REST_OF_MSG}"
    
    echo -e "${GREEN}💡 Mensaje corregido automáticamente:${NC}"
    echo -e "  ${GREEN}${SUGGESTED_MSG}${NC}"
    echo ""
    
    # Preguntar si usar la sugerencia
    read -p "¿Usar este mensaje? (s/n): " -n 1 -r
    echo
    if [[ $REPLY =~ ^[Ss]$ ]]; then
        git commit -m "$SUGGESTED_MSG"
    else
        echo -e "${YELLOW}Commit cancelado. Usa el formato: tipo: descripción${NC}"
        exit 1
    fi
else
    echo -e "${YELLOW}No se pudo determinar el tipo de commit automáticamente.${NC}"
    echo -e "${YELLOW}Usa el formato: tipo: descripción${NC}"
    echo ""
    echo -e "${YELLOW}Tipos válidos: feat, fix, docs, style, refactor, test, chore, perf, ci, build, revert${NC}"
    exit 1
fi
