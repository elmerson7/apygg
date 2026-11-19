#!/usr/bin/env bash
#
# setup-summary.sh [dev] — Imprime las URLs y las credenciales al terminar make setup.

set -u
source "$(dirname "${BASH_SOURCE[0]}")/lib/common.sh"
cd "$(repo_root)"

DEV="${1:-}"
APP=$(get_kv compose.env APP_PORT 8010)
echo ""
echo "═══════════════════════════════════════════════════════════════"
echo " ✅ $(get_kv compose.env PROJECT) listo"
echo "═══════════════════════════════════════════════════════════════"
echo "  API:         http://localhost:${APP}"
echo "  Salud:       http://localhost:${APP}/health"
echo "  Docs API:    http://localhost:${APP}/docs/api"
if [[ "$(get_kv .env BACKOFFICE_ENABLED true)" != "false" ]]; then
    echo "  Back-office: http://localhost:${APP}/$(get_kv .env BACKOFFICE_PATH backoffice)"
fi
if [[ "$DEV" == "true" || "$DEV" == "1" || "$DEV" == "yes" ]]; then
    echo "  Mailpit:     http://localhost:$(get_kv compose.env MAILPIT_UI_PORT 8017)"
    echo "  Consola S3:  http://localhost:$(get_kv compose.env S3_CONSOLE_PORT 8019)  (usuario/clave: S3_ACCESS_KEY / S3_SECRET_KEY de compose.env)"
fi
email=$(get_kv .env ADMIN_EMAIL)
if [[ -n "$email" ]]; then
    echo ""
    echo "  Admin:       ${email}"
    echo "  Contraseña:  $(get_kv .env ADMIN_PASSWORD)   (ADMIN_PASSWORD en .env)"
    echo "  Login:       POST http://localhost:${APP}/auth/login"
fi
echo ""
echo "Siguiente: make doctor · make test · make help"
