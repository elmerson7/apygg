#!/bin/bash
#
# Instalador de Git Hooks
# Copia los hooks desde scripts/git-hooks/ a .git/hooks/
#

set -e

# Colores para output
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Directorios
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
GIT_HOOKS_DIR="$PROJECT_ROOT/.git/hooks"
HOOKS_SOURCE_DIR="$PROJECT_ROOT/scripts/git-hooks"

echo -e "${GREEN}🔧 Instalando Git Hooks...${NC}"
echo ""

# Verificar que existe .git
if [ ! -d "$PROJECT_ROOT/.git" ]; then
    echo -e "${YELLOW}⚠️  No se encontró directorio .git. Este proyecto no es un repositorio Git.${NC}"
    exit 1
fi

# Crear directorio de hooks si no existe
mkdir -p "$GIT_HOOKS_DIR"

# Lista de hooks a instalar
HOOKS=("pre-commit" "commit-msg")

# Instalar cada hook
INSTALLED=0
for HOOK in "${HOOKS[@]}"; do
    SOURCE_FILE="$HOOKS_SOURCE_DIR/$HOOK"
    TARGET_FILE="$GIT_HOOKS_DIR/$HOOK"
    
    if [ -f "$SOURCE_FILE" ]; then
        # Copiar hook
        cp "$SOURCE_FILE" "$TARGET_FILE"
        
        # Dar permisos de ejecución
        chmod +x "$TARGET_FILE"
        
        echo -e "${GREEN}✓ Instalado: $HOOK${NC}"
        INSTALLED=$((INSTALLED + 1))
    else
        echo -e "${YELLOW}⚠️  No se encontró: $SOURCE_FILE${NC}"
    fi
done

echo ""
if [ $INSTALLED -gt 0 ]; then
    echo -e "${GREEN}✅ Se instalaron $INSTALLED hook(s) correctamente${NC}"
    echo ""
    echo -e "${GREEN}Los hooks se ejecutarán automáticamente en cada commit.${NC}"
    echo ""
    echo -e "${YELLOW}Para saltar hooks temporalmente:${NC}"
    echo -e "  ${GREEN}git commit --no-verify${NC}"
    echo ""
    echo -e "${YELLOW}Para desinstalar hooks:${NC}"
    echo -e "  ${GREEN}rm .git/hooks/pre-commit .git/hooks/commit-msg${NC}"
else
    echo -e "${YELLOW}⚠️  No se instaló ningún hook${NC}"
    exit 1
fi
