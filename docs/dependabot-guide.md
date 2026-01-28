# Guía de Dependabot

## ¿Qué es Dependabot?

Dependabot es un bot de GitHub que automáticamente:
- Escanea tus dependencias (`composer.json`, `package.json`, etc.)
- Detecta actualizaciones disponibles
- Crea Pull Requests para actualizar dependencias
- Ejecuta CI para verificar que todo funciona

## Configuración Actual

- **Frecuencia**: Mensual (cada lunes del mes a las 9 AM)
- **Límite de PRs**: Máximo 5 PRs abiertos simultáneamente
- **Ignora**: Actualizaciones mayores de Laravel, Octane y PHP

## ¿Qué hacer con los PRs de Dependabot?

### Opción 1: Mergear todos automáticamente (Recomendado para parches)

Si los PRs son solo actualizaciones de parches (ej: `6.4.0` → `6.4.1`):

1. **Revisar rápidamente**:
   - Verificar que CI pasó ✅
   - Verificar que no hay breaking changes en el changelog

2. **Mergear**:
   - Click en "Merge pull request"
   - O usar "Merge all" si GitHub lo permite

### Opción 2: Mergear selectivamente

Si hay muchos PRs, mergea solo los importantes:

**Prioridad Alta** (mergear primero):
- Actualizaciones de seguridad (si Dependabot las marca)
- Laravel Framework (si es parche: `12.40.0` → `12.40.1`)

**Prioridad Media**:
- Dependencias principales (Laravel packages)
- Herramientas de desarrollo (Pest, PHPStan)

**Prioridad Baja** (puedes esperar):
- Actualizaciones menores de paquetes secundarios

### Opción 3: Cerrar PRs que no necesitas

Si un PR es de una actualización menor que no necesitas:

1. Click en el PR
2. Click en "Close pull request"
3. Opcionalmente, agrega un comentario explicando por qué

### Opción 4: Usar "Merge all" de GitHub

Si tienes muchos PRs y todos pasaron CI:

1. Ve a la lista de PRs
2. Selecciona todos los PRs de Dependabot
3. Usa la acción masiva "Merge all" (si está disponible)

## Recomendación para tu caso

**Con los PRs actuales:**

1. **Revisa rápidamente cada PR**:
   - ¿CI pasó? ✅
   - ¿Es solo un parche? (ej: `6.4.0` → `6.4.1`)

2. **Mergea los parches pequeños**:
   - `spatie/laravel-query-builder`: `6.4.0` → `6.4.1` ✅ Mergear
   - `laravel/scout`: `10.23.0` → `10.23.1` ✅ Mergear
   - `laravel/octane`: `2.13.4` → `2.13.5` ✅ Mergear
   - `pestphp/pest`: `4.3.1` → `4.3.2` ✅ Mergear
   - `dedoc/scramble`: `0.13.10` → `0.13.11` ✅ Mergear

3. **Revisa el de Laravel Framework**:
   - `12.40.0` → `12.49.0` ⚠️ Es una actualización menor (no mayor)
   - Revisa el changelog: https://github.com/laravel/framework/releases
   - Si no hay breaking changes, mergear
   - Si hay cambios importantes, revisar manualmente

## Automatización Futura

Puedes configurar auto-merge para PRs de Dependabot:

1. Ve a Settings → Code security and analysis
2. Busca "Dependabot"
3. Habilita "Auto-merge" para PRs que pasen CI

**⚠️ Precaución**: Solo habilita auto-merge si confías en tus tests y CI.

## Comandos Útiles

### Mergear todos los PRs de Dependabot localmente

```bash
# Listar PRs de Dependabot
gh pr list --author dependabot --state open

# Mergear uno específico
gh pr merge 40 --squash --delete-branch

# O mergear todos (cuidado!)
gh pr list --author dependabot --state open --json number --jq '.[].number' | xargs -I {} gh pr merge {} --squash --delete-branch
```

## Troubleshooting

### ¿Por qué hay tantos PRs?

- Dependabot escaneó todas las dependencias
- Encontró actualizaciones disponibles
- Creó un PR por cada actualización

### ¿Cómo evitar tantos PRs?

- Ya ajustamos: frecuencia mensual + límite de 5 PRs
- Los nuevos PRs se crearán más espaciados

### ¿Puedo desactivar Dependabot completamente?

Sí, elimina o renombra `.github/dependabot.yml`
