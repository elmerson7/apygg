# Stack Tecnológico - APYGG Laravel 12

## Backend

### Framework y Servidor HTTP
- **Laravel 12** - Framework web PHP moderno para aplicaciones robustas
- **PHP 8.5** - Lenguaje de programación de última generación
- **FrankenPHP (Octane)** - Servidor HTTP moderno production-ready basado en Caddy para desarrollo y PaaS

### Autenticación y Seguridad
- **php-open-source-saver/jwt-auth v2.8.x** - Autenticación mediante JSON Web Tokens (JWT)
- **Laravel Sanctum** - Autenticación de API tokens (alternativa incorporada)
- **bcrypt** - Hashing seguro de contraseñas

### Base de Datos
- **PostgreSQL 18** - Base de datos relacional con soporte a JSON, UUID, particionamiento
- **PgBouncer** (Opcional) - Connection pooler para PostgreSQL, recomendado para producción con alta carga
- **Laravel Migrations** - Versionado de schema de BD
- **Laravel Eloquent ORM** - Abstracción de base de datos orientada a objetos

### Caché y Sesiones
- **Redis 7** - Cache en memoria, sesiones, colas
- **Laravel Cache** - Abstracción de cache con drivers múltiples

### Colas y Procesamiento Asíncrono
- **Laravel Queue** - Sistema de colas incorporado
- **Laravel Horizon** v3.x - Dashboard para monitoreo de colas
- **Laravel Reverb** - WebSockets nativo para comunicación en tiempo real
- **Redis Queue Driver** - Driver de colas basado en Redis

### Búsqueda y Indexación
- **Meilisearch 1.x** (Opcional) - Motor de búsqueda full-text
- **Laravel Scout** - Abstracción de búsqueda

### Documentación de API
- **dedoc/scramble** - Documentación automática de API sin configuración manual

### Observabilidad y Logging
- **Laravel Telescope** v5.x - Herramienta de debugging para desarrollo
- **Sentry** (Opcional) - Monitoreo de errores en producción
- **Laravel Logging** - Sistema centralizado de logs
- **Prometheus** (Opcional) - Recolección de métricas
- **Grafana** (Opcional) - Visualización de métricas y alertas
- **OpenTelemetry** (Opcional) - Distributed tracing

### Utilidades
- **spatie/laravel-query-builder** - Query filters estandarizado para filtrado dinámico
- **laravel/scout** - Búsqueda full-text
- **laravel/tinker** - REPL interactivo para Laravel

---

## Containerización e Infraestructura

### Docker
- **Docker** - Containerización de la aplicación
- **Docker Compose** v2.x - Orquestación local de contenedores
- **docker:latest** - CLI de Docker

### Imágenes Base
- **dunglas/frankenphp:php8.4-bookworm** - Runtime PHP con FrankenPHP
- **postgres:18-alpine** - Base de datos
- **pgbouncer/pgbouncer:latest** (Opcional) - Connection pooler para PostgreSQL
- **redis:7-alpine** - Cache
- **getmeili/meilisearch:latest** (Opcional) - Búsqueda

---

## Testing

### Herramientas de Test
- **Pest 4.x** - Framework de testing moderno para PHP (construido sobre PHPUnit 12)
- **pestphp/pest-plugin-laravel** - Plugin de Pest para Laravel 12 con helpers específicos

### Características
- Sintaxis expresiva y legible (inspirada en Jest/RSpec)
- API de expectativas fluida (`expect()->toBe()`, `->toBeTrue()`, etc.)
- Testing paralelo integrado
- Watch mode para desarrollo (`--watch`)
- Snapshot testing
- Browser testing con Playwright (opcional)
- Architecture testing
- Compatible con tests de PHPUnit existentes

### Cobertura de Código
- Pest incluye herramientas de cobertura integradas
- Target: 80% en código crítico (auth, usuarios, permisos)
- Enfoque pragmático: calidad sobre cantidad

---

## Desarrollo

### Linting y Formateo
- **Laravel Pint** - Formateador de código basado en PHP CS Fixer
- **PHP CS Fixer** - Fixer automático de estándares PSR-12
- **PHPStan** v1.x - Análisis estático de código (nivel 9)
- **SonarQube** (Opcional) - Análisis de calidad de código

### Package Manager
- **Composer 2.x** - Gestor de dependencias de PHP
- **npm** (Opcional) - Gestor de dependencias de Node.js

### IDE y Herramientas
- **VS Code** - Editor de código
- **PHP Intelephense** - Extensión de PHP para VS Code
- **Laravel Extension Pack** - Extensiones para Laravel
- **Docker Extension** - Extensión para gestionar Docker
- **PostgreSQL Extension** - Extensión para gestionar bases de datos

---

## CI/CD y DevOps

### Integración Continua
- **GitHub Actions** / **GitLab CI** / **Jenkins** - Pipeline de CI/CD
- **Dependabot** - Escaneo de vulnerabilidades de dependencias
- **Snyk** (Opcional) - Análisis de seguridad

### Automatización
- **Git Hooks** (pre-commit, pre-push) - Validaciones automáticas antes de commits
- **Semantic Release** (Opcional) - Versionado automático

---

## Seguridad

### Headers y Protección
- **HTTPS/TLS 1.3** - Encriptación de comunicaciones
- **Helmet.js** equivalente - Headers de seguridad HTTP
- **CORS Middleware** - Control de acceso entre orígenes
- **Rate Limiting** - Limitación de requests
- **IP Whitelisting** - Restricción por IP

### Validación y Sanitización
- **Laravel Form Request Validation** - Validación centralizada
- **Laravel Sanitization** - Sanitización de inputs
- **OWASP** - Cumplimiento de estándares de seguridad

---

## Herramientas de Gestión de Base de Datos

### Desarrollo Local
- **TablePlus** (Recomendado) - Cliente gráfico para gestión de BD
- **DBeaver** - Alternativa gratuita
- **pgAdmin** (Desktop) - Gestor de PostgreSQL
- **VS Code PostgreSQL Extension** - Extensión integrada

---

## Monitoreo y Observabilidad (Producción)

### Logs
- **Laravel Logging** - Sistema centralizado de logs

### Errors
- **Sentry** (Opcional) - Tracking de errores y performance

### Métricas (Futuro)
- **Prometheus** (Opcional) - Recolección de métricas
- **Grafana** (Opcional) - Visualización de métricas

### Trazas Distribuidas (Futuro)
- **OpenTelemetry** (Opcional) - Tracing distribuido para microservicios

---

## Hosting y Despliegue

### Opciones de Hosting
- **AWS EC2** / **DigitalOcean** / **Linode** - Servidores en la nube
- **Heroku** / **Railway** - Platform as a Service (PaaS)
- **Kubernetes** (On-premise o cloud) - Orquestación de contenedores

### CDN (Opcional)
- **Cloudflare** - CDN y cache edge

### Backup
- **AWS S3** / **Backblaze** - Almacenamiento en la nube

---

## Versiones Resumidas

| Tecnología | Versión | Tipo |
|---|---|---|
| PHP | 8.5 | Core |
| Laravel | 12.x | Framework |
| PostgreSQL | 18 | BD |
| PgBouncer | Latest | Connection Pooler (Opcional) |
| Redis | 7 | Cache |
| Docker | Latest | Container |
| Compose | 2.x | Orquestación |
| Node.js | 20 LTS (Opcional) | Runtime |
| Composer | 2.x | Package Manager |
| PHPUnit | 11.x | Testing |
| Meilisearch | 1.x | Search (Opcional) |

---

## Requisitos Mínimos del Sistema

### Desarrollo Local
- CPU: 4 cores
- RAM: 8 GB mínimo (16 GB recomendado)
- Disco: 50 GB SSD libre
- Docker Engine 4.0+
- Docker Compose 2.0+

### Producción
- CPU: 2-4 cores
- RAM: 4-8 GB (escalable)
- Disco: 100+ GB SSD
- PostgreSQL 16+
- Redis 6+

---

## Alternativas Consideradas pero No Usadas

- **Symfony** (en lugar de Laravel) - Laravel es más rápido de desarrollar
- **MySQL** (en lugar de PostgreSQL) - PostgreSQL es más robusto para production
- **MongoDB** (NoSQL) - Se requiere relacional para este proyecto
- **Memcached** (en lugar de Redis) - Redis es más versátil
- **Elasticsearch** (en lugar de Meilisearch) - Meilisearch es más simple y moderno

