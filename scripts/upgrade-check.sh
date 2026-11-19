#!/usr/bin/env bash
#
# upgrade-check.sh — Muestra qué trae el boilerplate (remoto `upstream`) que este
# proyecto aún no tiene. No modifica nada salvo traer las referencias (git fetch).
#
#   make upgrade-check [UPSTREAM_BRANCH=main]
#
# Para aplicarlo:  git merge upstream/main  y leer docs/upgrading.md.

set -u
source "$(dirname "${BASH_SOURCE[0]}")/lib/common.sh"
cd "$(repo_root)"

BRANCH="${UPSTREAM_BRANCH:-main}"

if ! git rev-parse --git-dir >/dev/null 2>&1; then
    echo "❌ Esto no es un repositorio git."; exit 1
fi
if ! git remote get-url upstream >/dev/null 2>&1; then
    echo "❌ No existe el remoto 'upstream' (el repo del boilerplate)."
    echo "   make init lo crea al renombrar origin. A mano:"
    echo "     git remote add upstream <url-del-boilerplate>"
    echo "     git remote set-url --push upstream NO_PUSH_AL_BOILERPLATE"
    exit 1
fi

echo "Trayendo upstream/${BRANCH}..."
git fetch upstream "$BRANCH" --quiet || { echo "❌ git fetch upstream falló (¿red o permisos?)"; exit 1; }
REF="upstream/${BRANCH}"

BEHIND=$(git rev-list --count "HEAD..${REF}")
AHEAD=$(git rev-list --count "${REF}..HEAD")
BASE=$(git merge-base HEAD "$REF" 2>/dev/null || true)

echo ""
echo "Tu rama: $(git symbolic-ref --short -q HEAD || echo 'HEAD suelto') · ${REF}: ${BEHIND} commit(s) que te faltan, ${AHEAD} tuyos que no están allí"
if [[ -z "$BASE" ]]; then
    echo "⚠️  No hay historia común con ${REF} (¿repo creado sin clonar el boilerplate?)."
    echo "   El primer merge necesitará: git merge --allow-unrelated-histories ${REF}"
fi

if (( BEHIND == 0 )); then
    echo "✅ Estás al día con el boilerplate."
    exit 0
fi

echo ""
echo "Commits nuevos del boilerplate:"
git log --oneline --no-decorate "HEAD..${REF}" | head -50
(( BEHIND > 50 )) && echo "  ... y $((BEHIND - 50)) más (git log HEAD..${REF})"

if [[ -n "$BASE" ]]; then
    echo ""
    echo "Archivos que suelen exigir acción (cambiaron en el boilerplate):"
    git diff --stat "${BASE}..${REF}" -- database/migrations .env.example compose.env.example \
        docker-compose.yml composer.json composer.lock Makefile make | tail -25
    echo ""
    echo "Conflictos probables (archivos que cambiaron en ambos lados):"
    comm -12 <(git diff --name-only "${BASE}..${REF}" | sort) <(git diff --name-only "${BASE}..HEAD" | sort) | head -20 | sed 's/^/  /'
fi

echo ""
echo "Siguiente:"
echo "  1. make backup                  (copia de la BD)"
echo "  2. git switch -c actualizar-boilerplate && git merge ${REF}"
echo "  3. Lee docs/upgrading.md y CHANGELOG.md; aplica lo que indiquen (.env nuevo, migraciones)"
echo "  4. make composer cmd=install && make migrate && make check && make test && make doctor"
