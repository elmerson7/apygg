# GitHub Actions CI/CD

Este directorio contiene los workflows de GitHub Actions para CI/CD del proyecto APYGG.

## Workflows Disponibles

### `ci.yml` - Pipeline de Integración Continua

Se ejecuta automáticamente en:
- Push a ramas `main` o `develop`
- Pull Requests hacia `main` o `develop`

#### Etapas del Pipeline:

1. **Lint & Code Quality**
   - Laravel Pint (formateo y estilo de código)
   - PHPStan nivel 5 (análisis estático)

2. **Tests & Coverage**
   - Tests con Pest
   - Cobertura de código (mínimo 0% para empezar)
   - Servicios: PostgreSQL 18 y Redis 7

3. **Security Scan**
   - Snyk (requiere `SNYK_TOKEN` en secrets)
   - Composer audit (escaneo de vulnerabilidades)

4. **Docker Build**
   - Construcción de imagen Docker
   - Verificación de que la imagen funciona

## Configuración Requerida

### Secrets de GitHub

Configura los siguientes secrets en tu repositorio (Settings → Secrets and variables → Actions):

- `SNYK_TOKEN` (opcional): Token de Snyk para escaneo de seguridad avanzado
  - Obtener en: https://snyk.io/
  - Si no se configura, el workflow `snyk-security.yml` se saltará
- `SONAR_TOKEN` (opcional): Token de SonarCloud para análisis de código
  - Obtener en: https://sonarcloud.io/account/security/
  - Si no se configura, el workflow `sonarcloud.yml` se saltará

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

### `cd.yml` - Pipeline de Despliegue Continuo

Se ejecuta automáticamente después de que CI pasa exitosamente en ramas `main` o `staging`.

#### Estrategias de Despliegue:

1. **Blue-Green Deployment**
   - Despliegue a entorno inactivo
   - Health checks automáticos
   - Cambio instantáneo de tráfico
   - Zero-downtime garantizado

2. **Canary Deployment**
   - Despliegue gradual (10% → 50% → 100%)
   - Monitoreo de métricas en cada etapa
   - Rollback automático si falla
   - Integración con feature flags

3. **Rolling Deployment**
   - Actualización en batches pequeños
   - Verificación continua
   - Rollback automático si falla

#### Características:

- **Rollback Automático**: Si cualquier etapa falla, se revierte automáticamente
- **Health Checks**: Verificación continua de salud del servicio
- **Post-Deployment**: Ejecución automática de migraciones y cache warming
- **Notificaciones**: Estado del despliegue (configurable)

#### Uso Manual:

Puedes ejecutar despliegues manualmente desde la pestaña "Actions":

1. Ve a "Actions" → "CD Pipeline"
2. Click en "Run workflow"
3. Selecciona:
   - Environment (staging/production)
   - Strategy (blue-green/canary/rolling)
   - Rollback (si quieres hacer rollback)

### `snyk-security.yml` - Snyk Security Scan

Escaneo de seguridad completo con Snyk:
- **Snyk Code (SAST)**: Análisis de código estático
- **Snyk Open Source (SCA)**: Análisis de dependencias Composer
- **Snyk Container**: Análisis de imagen Docker
- **Snyk IaC**: Análisis de Dockerfiles y docker-compose

Se ejecuta automáticamente después de que CI pasa exitosamente.

**Configuración:**
1. Obtén tu token en https://snyk.io/
2. Agrega `SNYK_TOKEN` a GitHub Secrets

### `sonarcloud.yml` - SonarCloud Analysis

Análisis de calidad de código con SonarCloud:
- Detección de bugs y vulnerabilidades
- Code smells y deuda técnica
- Cobertura de código
- Métricas de complejidad

Se ejecuta automáticamente después de que CI pasa exitosamente.

**Configuración:**
1. Login en https://sonarcloud.io/ con tu cuenta GitHub
2. Importa tu proyecto en SonarCloud
3. Obtén Project Key y Organization Key
4. Genera token en https://sonarcloud.io/account/security/
5. Agrega `SONAR_TOKEN` a GitHub Secrets
6. Actualiza `YOUR_PROJECT_KEY` y `YOUR_ORGANIZATION_KEY` en el workflow

## Próximos Pasos

- [x] Configurar CD Pipeline (Fase 24.3) para despliegue automático ✅
- [x] Configurar Snyk Security Scan ✅
- [x] Configurar SonarCloud Analysis ✅
- [ ] Agregar más tests para aumentar cobertura
- [ ] Configurar notificaciones (Slack, Discord, Email)
