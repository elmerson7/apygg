# ADR-001: Fase 1 - Setup Inicial y Configuración Base

## Información General
- **Fase**: 1 - Setup Inicial y Configuración Base
- **Estado**: En progreso
- **Fecha inicio**: 2026-01-XX
- **Última actualización**: 2026-01-XX

## Contexto

La Fase 1 establece la base del proyecto APYGG Laravel 12, incluyendo la configuración de Docker, instalación de Laravel, estructura de directorios modular, configuración de entornos e instalación de dependencias esenciales. Este ADR documenta todas las decisiones arquitectónicas nuevas tomadas durante la implementación de la Fase 1 que no estaban explícitamente definidas en el PLAN_ACCION.md.

## Decisiones Arquitectónicas por Subfase

### Subfase 1.1 - Preparación del Entorno con Docker

#### Decisión 1.1.1: Convención de Nombres Docker con Guión Bajo

**Decisión**: Usar prefijo `apygg_` (con guión bajo) para todos los recursos Docker en lugar de `apygg-` (con guión).

**Razones**:
- Consistencia en toda la infraestructura (contenedores, volúmenes, networks)
- Facilita identificación de recursos del proyecto
- Evita conflictos con otros proyectos Docker
- Mejor compatibilidad con scripts y herramientas de automatización

**Implementación**:
- Contenedores: `apygg_app`, `apygg_postgres`, `apygg_redis`
- Volúmenes: `apygg_pgdata`, `apygg_redisdata`
- Network: `apygg_network` (el plan mencionaba `apygg-network` con guión)

**Referencia**: `docker-compose.yml`

#### Decisión 1.1.2: Imagen Base FrankenPHP Específica

**Decisión**: Usar `dunglas/frankenphp:latest` como imagen base en lugar de construir desde `php:8.4-fpm-bookworm`.

**Razones**:
- El plan mencionaba `php:8.4-fpm-bookworm` pero FrankenPHP/Octane requiere CLI, no FPM
- La imagen oficial `dunglas/frankenphp` ya incluye PHP 8.4 + FrankenPHP preconfigurado
- Simplifica el Dockerfile al evitar instalación manual de FrankenPHP
- Optimizada específicamente para Laravel Octane

**Referencia**: `docker/app/Dockerfile`

#### Decisión 1.1.3: Sintaxis Moderna de Docker Compose

**Decisión**: Usar `docker compose` (con espacio) en lugar de `docker-compose` (con guión) en toda la documentación y comandos.

**Razones**:
- `docker-compose` es la versión standalone antigua
- `docker compose` es la sintaxis moderna del plugin de Docker
- Evita problemas de compatibilidad y uso de herramientas obsoletas
- Alinea con las mejores prácticas actuales de Docker

**Referencia**: Comandos actualizados en TASKS.md y PLAN_ACCION.md

#### Decisión 1.1.4: Profile Simplificado para Fase 1.1

**Decisión**: El servicio `app` usa solo profile `dev` en lugar de `["dev","staging","prod"]` para la Fase 1.1.

**Razones**:
- Seguir principio de minimalismo para la fase inicial
- Reducir complejidad innecesaria al inicio
- Los otros profiles se agregarán cuando se necesiten en fases posteriores
- Facilita el desarrollo inicial sin configuraciones adicionales

**Referencia**: `docker-compose.yml` - servicio `app`

#### Decisión 1.1.5: Eliminación Temprana de Servicio postgres_logs

**Decisión**: Eliminar el servicio `postgres_logs` desde el inicio en lugar de mantenerlo comentado o configurado.

**Razones**:
- El plan mencionaba que los logs van en la misma BD con particionamiento, pero no estaba claro eliminar el servicio
- Simplifica la arquitectura desde el inicio
- Evita confusión sobre si usar BD separada o no
- Si en el futuro se requiere separar, se puede agregar fácilmente

**Referencia**: `docker-compose.yml` - servicio `postgres_logs` eliminado

#### Decisión 1.1.6: Docker Compose Override para Desarrollo

**Decisión**: Crear `docker-compose.override.yml` para configuraciones específicas de desarrollo local.

**Razones**:
- No estaba explícitamente mencionado en el plan
- Permite personalizar configuración sin modificar `docker-compose.yml` principal
- Se carga automáticamente por docker-compose
- Facilita diferentes configuraciones por entorno sin duplicar código

**Contenido**:
- Variables de entorno de debugging (`APP_DEBUG`, `LOG_LEVEL`)
- Configuraciones adicionales para PostgreSQL y Redis en desarrollo

**Referencia**: `docker-compose.override.yml`

### Subfase 1.2 - Instalación del Proyecto Laravel

*Decisiones de esta subfase se documentarán cuando se complete.*

### Subfase 1.3 - Estructura de Directorios

*Decisiones de esta subfase se documentarán cuando se complete.*

### Subfase 1.4 - Archivos de Configuración de Entornos

*Decisiones de esta subfase se documentarán cuando se complete.*

### Subfase 1.5 - Instalación de Dependencias Esenciales

*Decisiones de esta subfase se documentarán cuando se complete.*

## Archivos Creados/Modificados

### Archivos Creados (Subfase 1.1)
- `docker/app/Dockerfile`: Dockerfile con imagen base `dunglas/frankenphp:latest`
- `docker-compose.override.yml`: Configuraciones de desarrollo local
- `docs/adr/ADR-001-fase-1-setup-inicial.md`: Este documento

### Archivos Modificados (Subfase 1.1)
- `docker-compose.yml`: Actualizado con convención de nombres `apygg_`, profile simplificado, eliminación de `postgres_logs`
- `TASKS.md`: Comandos actualizados a sintaxis moderna de Docker Compose
- `PLAN_ACCION.md`: Comandos básicos actualizados a sintaxis moderna de Docker Compose

## Referencias

- PLAN_ACCION.md - Sección 1 (Configuración Inicial del Proyecto) y 11 (Infraestructura Docker)
- TASKS.md - Fase 1 (Setup Inicial y Configuración Base)
- TECH_STACK.md - Stack tecnológico
- [Documentación oficial de FrankenPHP](https://frankenphp.dev/docs/laravel/)
- [Documentación oficial de Docker Compose](https://docs.docker.com/compose/)

## Consecuencias

### Positivas
- Convenciones de nombres claras y consistentes con guión bajo
- Imagen base optimizada específicamente para FrankenPHP/Octane
- Sintaxis moderna de Docker Compose alineada con mejores prácticas
- Configuración simplificada para desarrollo inicial
- Base sólida para agregar servicios adicionales

### Consideraciones
- Los servicios comentados deben descomentarse y configurarse cuando se necesiten en fases posteriores
- El healthcheck `/api/health` fallará hasta que se implemente en Fase 11 (puede ajustarse temporalmente si es necesario)
- La imagen `dunglas/frankenphp:latest` puede cambiar de versión; considerar fijar versión específica en producción
- Si en el futuro se requiere BD separada para logs, se puede agregar el servicio `postgres_logs` fácilmente
