# ADR-001: Fase 1 - Setup Inicial y Configuración Base

## Información General
- **Fase**: 1 - Setup Inicial y Configuración Base
- **Estado**: En progreso
- **Fecha inicio**: 2026-01-XX
- **Última actualización**: 2026-01-28
- **Subfase 1.1**: Completada (pendiente pruebas finales)

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

**Decisión**: Usar `dunglas/frankenphp:php8.4-bookworm` como imagen base en lugar de construir desde `php:8.4-fpm-bookworm`.

**Razones**:
- El plan mencionaba `php:8.4-fpm-bookworm` pero FrankenPHP/Octane requiere CLI, no FPM
- La imagen oficial `dunglas/frankenphp:php8.4-bookworm` ya incluye PHP 8.4 + FrankenPHP preconfigurado
- Versión específica (`php8.4-bookworm`) en lugar de `latest` para mayor estabilidad
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

#### Decisión 1.1.4: Perfiles Dev y Prod desde el Inicio

**Decisión**: Implementar perfiles `dev` y `prod` desde la Fase 1.1 en lugar de solo `dev` inicialmente.

**Razones**:
- El plan mencionaba perfiles `dev` y `prod` pero inicialmente se implementó solo `dev`
- Facilita el despliegue en producción desde el inicio
- Estructura clara y preparada para ambos entornos
- Evita refactorización posterior cuando se necesite producción

**Implementación**:
- Servicios con perfil `dev`: `app`, `postgres`, `redis` (con configuraciones de desarrollo)
- Servicios con perfil `prod`: `app-prod`, `postgres-prod`, `redis-prod` (con configuraciones de producción)

**Referencia**: `docker-compose.yml` - servicios con perfiles `dev` y `prod`

#### Decisión 1.1.5: Eliminación Temprana de Servicio postgres_logs

**Decisión**: Eliminar el servicio `postgres_logs` desde el inicio en lugar de mantenerlo comentado o configurado.

**Razones**:
- El plan mencionaba que los logs van en la misma BD con particionamiento, pero no estaba claro eliminar el servicio
- Simplifica la arquitectura desde el inicio
- Evita confusión sobre si usar BD separada o no
- Si en el futuro se requiere separar, se puede agregar fácilmente

**Referencia**: `docker-compose.yml` - servicio `postgres_logs` eliminado

#### Decisión 1.1.6: Perfiles Dev y Prod en lugar de Override

**Decisión**: Eliminar `docker-compose.override.yml` y usar perfiles `dev` y `prod` directamente en `docker-compose.yml` para gestionar configuraciones por entorno.

**Razones**:
- El plan mencionaba perfiles pero inicialmente se usó override para desarrollo
- Los perfiles son más explícitos y claros sobre qué servicios se ejecutan en cada entorno
- Evita confusión sobre qué archivo se está usando
- Mejor alineación con el PLAN_ACCION.md que menciona perfiles `dev` y `prod`
- Facilita el despliegue en diferentes entornos con comandos simples

**Implementación**:
- Perfil `dev`: Servicios con configuraciones de desarrollo
  - `app`: `APP_DEBUG=true`, `LOG_LEVEL=debug`
  - `postgres`: `POSTGRES_INITDB_ARGS` con encoding UTF8
  - `redis`: `maxmemory=256mb`, política `allkeys-lru`
- Perfil `prod`: Servicios con configuraciones de producción
  - `app-prod`: `APP_DEBUG=false`, `LOG_LEVEL=info`
  - `postgres-prod`: Sin configuraciones adicionales de desarrollo
  - `redis-prod`: Sin límites de memoria (configuración por defecto)

**Uso**:
```bash
# Desarrollo
docker compose --profile dev up -d

# Producción
docker compose --profile prod up -d
```

**Referencia**: `docker-compose.yml` - servicios con perfiles `dev` y `prod`

#### Decisión 1.1.7: Configuración DNS para Build de Docker

**Decisión**: Agregar configuración DNS explícita en `docker-compose.yml` para resolver problemas de DNS durante el build en entornos WSL.

**Razones**:
- En algunos entornos WSL, Docker tiene problemas resolviendo DNS durante el build (`Temporary failure resolving 'deb.debian.org'`)
- El build fallaba en el paso `apt-get update` impidiendo construir la imagen
- Necesario para garantizar builds exitosos independientemente de la configuración DNS del sistema

**Implementación**:
- `network: host` en la sección `build` para usar la red del host durante el build
- DNS explícitos (`8.8.8.8`, `8.8.4.4`, `1.1.1.1`) en la configuración del servicio `app`
- Esto permite que el build acceda a repositorios Debian sin problemas de DNS

**Referencia**: `docker-compose.yml` - sección `x-app-base` con `build.network: host` y `dns`

#### Decisión 1.1.8: Entrypoint que Maneja Instalación Pendiente de Laravel

**Decisión**: Modificar `entrypoint.sh` para manejar el caso cuando Laravel aún no está instalado (Fase 1.1).

**Razones**:
- En la Fase 1.1 solo se configura Docker, Laravel se instala en Fase 1.2
- El entrypoint original intentaba ejecutar `composer install` y `php artisan` sin tener `composer.json`
- Esto causaba que el contenedor se reiniciara continuamente con errores

**Implementación**:
- Verificar si `composer.json` existe antes de ejecutar comandos de Laravel
- Si no existe, mostrar mensaje informativo y mantener el contenedor corriendo con `tail -f /dev/null`
- Esto permite que el contenedor esté disponible para instalar Laravel en la Fase 1.2

**Referencia**: `docker/app/entrypoint.sh`

#### Decisión 1.1.9: Separación de Variables de Entorno Docker vs Laravel

**Decisión**: Separar las variables de entorno en dos tipos de archivos: `.env.example` en la raíz para Laravel y `env/*.env.example` solo para variables específicas de Docker Compose. Usar `env_file` en Docker Compose para cargar solo variables Docker, permitiendo que Laravel lea su `.env` desde la raíz.

**Razones**:
- Laravel busca `.env` en la raíz por defecto, no en subdirectorios
- Docker Compose usa `env/${APP_ENV}.env` para seleccionar archivos según el entorno
- Separación clara de responsabilidades: variables Laravel vs configuración Docker
- Facilita el uso sin Docker (solo `.env` en raíz)
- Los archivos `env/*.env.example` solo contienen variables específicas de servicios Docker (nombres de servicios, puertos internos)
- Las variables de entorno del sistema (cargadas por Docker) tienen prioridad sobre `.env`, pero solo se cargan variables Docker necesarias

**Implementación**:
- `.env.example` en raíz: Contiene todas las variables que Laravel necesita (APP_*, DB_*, REDIS_*, JWT_*, etc.) con valores genéricos (127.0.0.1, localhost)
- `.env` en raíz: Copia de `.env.example` con valores reales (incluyendo `APP_KEY` generado)
- `env/dev.env`: Solo variables Docker (APP_ENV, DB_HOST=postgres, REDIS_HOST=redis, nombres de servicios Docker)
- `env/dev.env.example`: Template con solo variables Docker para desarrollo
- `env/prod.env.example`: Solo variables Docker para producción
- `env/staging.env.example`: Solo variables Docker para staging

**Estructura**:
```
apygg/
├── .env                    # Para Laravel (todos los ambientes) - generado desde .env.example
├── .env.example            # Template Laravel (todas las variables Laravel)
├── env/
│   ├── dev.env            # Docker Compose dev (solo variables Docker)
│   ├── dev.env.example    # Solo variables Docker
│   ├── prod.env.example   # Solo variables Docker
│   └── staging.env.example # Solo variables Docker
```

**Flujo de Variables de Entorno**:

```
┌─────────────────────────────────────┐
│  docker-compose.yml                  │
│  env_file: env/dev.env               │
└──────────────┬──────────────────────┘
               │
               ▼
┌─────────────────────────────────────┐
│  env/dev.env                        │
│  - DB_HOST=postgres                 │
│  - REDIS_HOST=redis                 │
│  - DB_PORT=5432                     │
│  - REDIS_PORT=6379                  │
│  - (solo variables Docker)          │
└──────────────┬──────────────────────┘
               │
               │ Docker carga estas variables
               │ como variables de entorno del sistema
               ▼
┌─────────────────────────────────────┐
│  Contenedor Laravel                 │
│                                     │
│  Variables de entorno (prioridad): │
│  1. Sistema (env/dev.env)          │
│     → DB_HOST, REDIS_HOST, etc.    │
│  2. .env en /app                   │
│     → APP_KEY, APP_NAME, etc.       │
│                                     │
│  Resultado:                         │
│  - Variables Docker sobrescriben   │
│    las del .env (DB_HOST, etc.)    │
│  - Variables solo en .env se usan  │
│    normalmente (APP_KEY, etc.)      │
└─────────────────────────────────────┘
```

**Cómo funciona**:
1. Docker Compose carga `env/dev.env` como variables de entorno del sistema dentro del contenedor
2. Laravel lee `.env` desde `/app/.env` (montado como volumen)
3. Si una variable existe en ambos lugares, la variable del sistema (`env/dev.env`) tiene prioridad
4. Variables que solo existen en `.env` (como `APP_KEY`) se usan normalmente
5. Esto permite que `DB_HOST=postgres` (Docker) sobrescriba `DB_HOST=127.0.0.1` (`.env`), mientras que `APP_KEY` solo existe en `.env` y funciona correctamente

**Ventajas de esta implementación**:
- ✅ Separación clara: Docker solo maneja conexiones a servicios, Laravel maneja su configuración
- ✅ Escalable: Fácil agregar nuevas variables Docker sin tocar `docker-compose.yml`
- ✅ Estándar de la industria: Patrón común en proyectos Docker
- ✅ Mantenible: Un solo lugar para variables Docker por entorno (`env/dev.env`)

**Referencia**: `.env.example` (raíz), `env/dev.env`, `env/*.env.example`, `docker-compose.yml` (línea 9-10)

#### Decisión 1.1.10: Gestión de Permisos Docker para Compatibilidad con IDE y Git

**Decisión**: Configurar el contenedor Docker para usar el mismo UID/GID del usuario del host, creando un usuario `appuser` dentro del contenedor con estos valores.

**Razones**:
- Los archivos creados por Docker (como `composer.lock`, `bootstrap/cache/.gitignore`) tenían permisos de `root` o `www-data`, causando problemas al:
  - Editar archivos desde el IDE (VS Code/Cursor)
  - Usar Git desde el IDE (archivos aparecían como modificados por cambios de permisos)
  - Ejecutar comandos desde el host sin `sudo`
- Al clonar el repositorio, otros desarrolladores tendrían problemas similares
- Necesidad de una solución que funcione tanto desde el contenedor como desde el IDE

**Implementación**:
- **Dockerfile**: Crea usuario `appuser` con UID/GID pasados como build args (`USER_ID`, `GROUP_ID`)
- **docker-compose.yml**: Pasa UID/GID del host como build args y variables de entorno
- **Makefile**: Detecta automáticamente UID/GID del usuario actual (`id -u`, `id -g`)
- **entrypoint.sh**: Ajusta permisos de `storage` y `bootstrap/cache` al iniciar
- **fix-permissions.sh**: Script helper para corregir permisos manualmente si es necesario

**Flujo**:
1. Makefile detecta UID/GID del usuario del host automáticamente
2. Al construir (`make build`), pasa estos valores como build args al Dockerfile
3. Dockerfile crea usuario `appuser` con estos UID/GID
4. Contenedor ejecuta comandos como `appuser` (no `root`)
5. Archivos creados tienen permisos del usuario del host
6. IDE y Git funcionan correctamente sin conflictos

**Ventajas**:
- ✅ Archivos editables desde el IDE sin problemas de permisos
- ✅ Git funciona correctamente desde el IDE
- ✅ No requiere `sudo` para editar archivos creados por Docker
- ✅ Funciona al clonar el repositorio (cada desarrollador usa su propio UID/GID)
- ✅ Compatible con diferentes sistemas operativos (Linux, macOS, WSL)

**Uso**:
```bash
# Construir con permisos correctos (automático)
make build

# Si hay problemas de permisos, corregir manualmente
make fix-permissions
```

**Referencia**: 
- `docker/app/Dockerfile` (creación de usuario `appuser`)
- `docker-compose.yml` (build args `USER_ID`, `GROUP_ID`)
- `Makefile` (detección automática de UID/GID)
- `docker/app/fix-permissions.sh` (script helper)
- `docs/PERMISOS_DOCKER.md` (documentación completa)

### Subfase 1.2 - Instalación del Proyecto Laravel

*Decisiones de esta subfase se documentarán cuando se complete.*

### Subfase 1.3 - Estructura de Directorios

*Decisiones de esta subfase se documentarán cuando se complete.*

### Subfase 1.4 - Archivos de Configuración de Entornos

*Decisiones de esta subfase se documentarán cuando se complete.*

### Subfase 1.5 - Instalación de Dependencias Esenciales

#### Decisión 1.5.1: Uso de php-open-source-saver/jwt-auth en lugar de tymon/jwt-auth

**Decisión**: Usar `php-open-source-saver/jwt-auth` v2.8.3 en lugar de `tymon/jwt-auth` para autenticación JWT.

**Razones**:
- `tymon/jwt-auth` es un paquete que ya no se mantiene activamente
- `php-open-source-saver/jwt-auth` es un fork mantenido activamente del paquete original
- Compatible con Laravel 12 y PHP 8.4
- API idéntica a `tymon/jwt-auth`, por lo que la migración es transparente
- Mejor soporte y actualizaciones de seguridad

**Implementación**:
- Declarado en `composer.json`: `"php-open-source-saver/jwt-auth": "^2.8"`
- Instalado vía `composer update`: v2.8.3 instalado correctamente
- Configuración publicada: `config/jwt.php` existe y está completo
- Guard configurado: `config/auth.php` tiene guard 'jwt' funcionando
- Variables de entorno: Configuradas en `.env.example` (`JWT_SECRET`, `JWT_TTL`, `JWT_ALGO`, `JWT_REFRESH_TTL`)

**Referencia**: 
- `composer.json` (línea 19)
- `config/jwt.php`
- `config/auth.php` (línea 44)
- `routes/api/auth.php` (usa middleware 'jwt')

#### Decisión 1.5.2: Actualización de Laravel Octane a v2.13.3

**Decisión**: Actualizar `laravel/octane` de `^2.12` a `^2.13.3` para obtener las últimas mejoras y correcciones.

**Razones**:
- Versión más reciente con mejoras de rendimiento y estabilidad
- Correcciones de bugs de versiones anteriores
- Mejor compatibilidad con FrankenPHP y PHP 8.4
- Mantener el proyecto actualizado con las últimas versiones estables

**Implementación**:
- Actualizado en `composer.json`: `"laravel/octane": "^2.13.3"`
- Instalado vía `composer update`: v2.13.3 instalado correctamente
- Configuración existente compatible: `config/octane.php` funciona sin cambios
- Docker configurado: `docker/app/entrypoint.sh` usa FrankenPHP correctamente

**Referencia**:
- `composer.json` (línea 13)
- `config/octane.php`
- `docker/app/entrypoint.sh` (líneas 45-56)
- `.env.example` (`OCTANE_SERVER=frankenphp`)

## Archivos Creados/Modificados

### Archivos Creados (Subfase 1.1)
- `docker/app/Dockerfile`: Dockerfile con imagen base `dunglas/frankenphp:php8.4-bookworm`
- `docker/app/entrypoint.sh`: Script de entrada para Octane/FrankenPHP
- `docker/app/php.ini`: Configuración PHP personalizada
- `docker/app/fix-permissions.sh`: Script helper para corregir permisos de archivos Docker
- `.env.example`: Template con todas las variables de Laravel (raíz)
- `env/dev.env.example`: Template con solo variables Docker para desarrollo
- `env/prod.env.example`: Template con solo variables Docker para producción
- `env/staging.env.example`: Template con solo variables Docker para staging
- `docs/adr/ADR-001-fase-1-setup-inicial.md`: Este documento
- `docs/PERMISOS_DOCKER.md`: Documentación sobre gestión de permisos con Docker

### Archivos Creados/Modificados (Subfase 1.5)
- `composer.json`: Actualizado con `php-open-source-saver/jwt-auth` v2.8 y `laravel/octane` v2.13.3
- `composer.lock`: Actualizado con todas las dependencias instaladas (php-open-source-saver/jwt-auth v2.8.3, laravel/octane v2.13.3)
- `config/jwt.php`: Configuración publicada y completa para JWT
- `app/Models/Model.php`: BaseModel creado
- `app/Http/Middleware/`: Carpeta creada para middleware futuros

### Archivos Modificados (Subfase 1.1)
- `docker-compose.yml`: Actualizado con convención de nombres `apygg_`, perfiles `dev` y `prod`, eliminación de `postgres_logs`, build args para UID/GID
- `docker/app/Dockerfile`: Crea usuario `appuser` con UID/GID del host para evitar problemas de permisos
- `docker/app/entrypoint.sh`: Ajusta permisos de storage/cache al iniciar
- `Makefile`: Detecta automáticamente UID/GID del usuario y pasa como build args, comando `fix-permissions` agregado
- `env/dev.env`: Comentarios sobre USER_ID/GROUP_ID agregados
- `TASKS.md`: Comandos actualizados a sintaxis moderna de Docker Compose, referencias actualizadas a `php-open-source-saver/jwt-auth`
- `PLAN_ACCION.md`: Comandos básicos actualizados a sintaxis moderna de Docker Compose, referencias actualizadas a `php-open-source-saver/jwt-auth`
- `TECH_STACK.md`: Actualizado con `php-open-source-saver/jwt-auth` v2.8.x

### Archivos Eliminados (Subfase 1.1)
- `docker-compose.override.yml`: Eliminado en favor de usar perfiles `dev` y `prod` directamente en `docker-compose.yml`

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
- Los perfiles `dev` y `prod` usan servicios separados (`app` vs `app-prod`) para evitar conflictos de container_name cuando se ejecutan simultáneamente
- Staging puede usar el perfil `prod` con diferentes variables de entorno según el plan
- Si en el futuro se requiere BD separada para logs, se puede agregar el servicio `postgres_logs` fácilmente
- La imagen `dunglas/frankenphp:php8.4-bookworm` usa versión específica para mayor estabilidad

### Problemas Resueltos
- **DNS durante build de Docker**: Resuelto agregando configuración DNS explícita en `docker-compose.yml`
  - **Solución implementada**: 
    - `network: host` en la sección `build` para usar la red del host durante el build
    - DNS explícitos (`8.8.8.8`, `8.8.4.4`, `1.1.1.1`) en la configuración del servicio
  - **Resultado**: Build exitoso, todos los servicios corriendo correctamente
  - **Entrypoint mejorado**: Maneja el caso cuando Laravel aún no está instalado (Fase 1.1)