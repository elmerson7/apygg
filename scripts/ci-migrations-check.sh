#!/usr/bin/env bash
# Comprueba en CI que las migraciones NUEVAS (respecto a $1, ref base) se aplican sobre el
# esquema de la versión anterior y son reversibles. Docs: docs/migrations.md §10.
# Uso: scripts/ci-migrations-check.sh <ref-base>   (requiere BD configurada por entorno)
set -euo pipefail

BASE="${1:?uso: $0 <ref-base>}"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

mkdir -p "$TMP/base-migrations"
git archive "$BASE" database/migrations | tar -x -C "$TMP"
cp "$TMP"/database/migrations/*.php "$TMP/base-migrations/"

NEW="$(git diff --name-only --diff-filter=A "$BASE" HEAD -- database/migrations | wc -l | tr -d ' ')"
echo "== Migraciones nuevas respecto a $BASE: $NEW"

echo "== 1) Migrar la versión anterior ($BASE) y sembrar"
php artisan migrate:fresh --path="$TMP/base-migrations" --realpath --force
# El seeder es el de la rama; si depende de tablas nuevas avisa pero no bloquea (esquema base).
php artisan db:seed --force || echo "AVISO: el seeder falló sobre el esquema base (¿usa tablas nuevas?)"

echo "== 2) Aplicar las migraciones nuevas sobre ese esquema"
php artisan migrate --force

echo "== 3) Humo"
php artisan migrate:status --no-interaction | tail -n 15
php artisan db:table users --no-interaction >/dev/null

if [ "$NEW" -gt 0 ]; then
  echo "== 4) Rollback de las $NEW nuevas y reaplicar"
  php artisan migrate:rollback --step="$NEW" --force
  php artisan migrate --force
fi
echo "OK: migraciones aplicables sobre $BASE y reversibles"
