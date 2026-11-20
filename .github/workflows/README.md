# GitHub Actions CI/CD

Este directorio contiene los workflows de GitHub Actions para CI/CD del proyecto APYGG.

## Workflows Disponibles

### `ci.yml` - Pipeline de Integración Continua

Se ejecuta automáticamente en:
- Push a ramas `main` o `develop`
- Pull Requests hacia `main` o `develop`
- Manualmente (`workflow_dispatch`)

#### Etapas del Pipeline:

1. **Lint & Code Quality**
   - Laravel Pint (formateo y estilo de código)
   - PHPStan nivel 6 (análisis estático)

2. **Tests & Coverage**
   - Tests con Pest (suite por defecto) y suites `Contract` y `Concurrency`
   - Cobertura de código (mínimo 0% para empezar)
   - Servicios: PostgreSQL 18 y Redis 7

3. **Security Scan**
   - Composer audit (escaneo rápido de vulnerabilidades)

4. **E2E (stack real)** — solo en push a `main` y a mano
   - `make init` + `make setup` (Docker Compose, perfil dev) y `make test-e2e`
   - Ver `docs/testing.md`, sección "Pruebas E2E"

5. **Docker Build**
   - Construcción de imagen Docker
   - Verificación de que la imagen funciona

## Configuración Requerida

### Secrets de GitHub

Configura los siguientes secrets en tu repositorio (Settings → Secrets and variables → Actions):

- `SONAR_TOKEN` (opcional): Token de SonarCloud para análisis de código
  - Obtener en: https://sonarcloud.io/account/security/
  - Si no se configura, el workflow `sonarcloud.yml` fallará

### Variables de Entorno

El workflow usa las siguientes variables por defecto:
- `APP_ENV=testing`
- `DB_DATABASE=apygg_test`
- `REDIS_HOST=localhost`

## Cómo Funciona

1. **En cada push/PR**: Se ejecuta el pipeline completo
2. **Si alguna etapa falla**: El pipeline se detiene y marca el check como fallido
3. **Si todas pasan**: El pipeline se marca como exitoso

## Ver Resultados

- Ve a la pestaña "Actions" en GitHub
- Selecciona el workflow que quieres ver
- Revisa los logs de cada etapa

## Troubleshooting

### Tests fallan localmente pero pasan en CI
- Verifica que tengas las mismas versiones de PHP, PostgreSQL y Redis
- Asegúrate de tener `.env` configurado correctamente

### PHPStan falla
- Ejecuta `./vendor/bin/phpstan analyse` localmente
- Revisa los errores y corrígelos

### Docker build falla
- Verifica que `docker/app/Dockerfile` esté correcto
- Prueba construir localmente: `docker build -f docker/app/Dockerfile .`

### `release.yml` - Imagen inmutable de producción

En cada push a `main` y en tags `v*`: construye `docker/app/Dockerfile.prod`, la escanea con Trivy y la publica en
GHCR con etiquetas fijas `sha-<commit>` y `vX.Y.Z` (nunca `latest`), con SBOM y provenance.

### `cd.yml` - Despliegue manual (SSH + Docker Compose)

Solo `workflow_dispatch`: elige entorno, `image_tag` (o `rollback`) y ejecuta `scripts/deploy/deploy.sh`
(pull, migraciones en contenedor aparte, `up --wait`, humo y rollback automático). Los mismos pasos corren en
local con `make deploy|rollback|smoke`. Guía completa y secrets: `docs/deploy.md`.

### `sonarcloud.yml` - SonarCloud Analysis

Análisis de calidad de código con SonarCloud:
- Detección de bugs y vulnerabilidades
- Code smells y deuda técnica
- Cobertura de código
- Métricas de complejidad

**Se ejecuta automáticamente después de que CI pasa exitosamente** (solo en main/develop, no en PRs de Dependabot).

**Configuración:**
1. Login en https://sonarcloud.io/ con tu cuenta GitHub
2. Importa tu proyecto en SonarCloud
3. Obtén Project Key y Organization Key
4. Genera token en https://sonarcloud.io/account/security/
5. Agrega `SONAR_TOKEN` a GitHub Secrets
6. Actualiza `YOUR_PROJECT_KEY` y `YOUR_ORGANIZATION_KEY` en el workflow

**Nota:** Si falta el token o las keys, el workflow fallará. Configúralo o desactívalo temporalmente.

## Control de Ejecuciones

### Limitar ejecuciones en PRs de Dependabot

El workflow `sonarcloud.yml` está configurado para **NO ejecutarse** en PRs de Dependabot.

Solo se ejecuta después de que CI pasa exitosamente en ramas `main` o `develop`, evitando ejecuciones innecesarias cuando Dependabot actualiza dependencias.

### Desactivar workflows temporalmente

Si quieres desactivar SonarCloud temporalmente:

1. Ve al archivo `.github/workflows/sonarcloud.yml`
2. Comenta el trigger `on:` o cambia las ramas
3. O simplemente no configures el `SONAR_TOKEN` (el workflow fallará pero no bloqueará CI)

## Próximos Pasos

- [x] Imagen inmutable en GHCR y despliegue manual por SSH + Compose (`docs/deploy.md`) ✅
- [x] Configurar SonarCloud Analysis ✅
- [ ] Agregar más tests para aumentar cobertura
- [ ] Configurar notificaciones (Slack, Discord, Email)
