<p align="center"><a href="https://laravel.com" target="_blank"><img src="https://raw.githubusercontent.com/laravel/art/master/logo-lockup/5%20SVG/2%20CMYK/1%20Full%20Color/laravel-logolockup-cmyk-red.svg" width="400" alt="Laravel Logo"></a></p>

<p align="center">
<a href="https://github.com/laravel/framework/actions"><img src="https://github.com/laravel/framework/workflows/tests/badge.svg" alt="Build Status"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/dt/laravel/framework" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/v/laravel/framework" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/l/laravel/framework" alt="License"></a>
</p>

# APYGG - API Boilerplate Laravel 12

API Boilerplate production-ready construido con Laravel 12, diseñado para aplicaciones modernas y escalables.

## 🚀 Stack Tecnológico

### Backend Core
- **Laravel 12.40.0** - Framework PHP moderno
- **PHP 8.5** - Lenguaje de programación
- **FrankenPHP (Octane)** - Servidor HTTP de alto rendimiento basado en Caddy

### Base de Datos y Cache
- **PostgreSQL 18** - Base de datos relacional con soporte JSON, UUID y particionamiento
- **PgBouncer** (Opcional) - Connection pooler para PostgreSQL en producción
- **Redis 7** - Cache en memoria, sesiones y colas

### Autenticación y Seguridad
- **php-open-source-saver/jwt-auth v2.8.3** - Autenticación JWT
- **Laravel Sanctum** - Autenticación de API tokens
- **bcrypt** - Hashing seguro de contraseñas

### Colas y Procesamiento Asíncrono
- **Laravel Queue** - Sistema de colas
- **Laravel Horizon v5.33** - Dashboard para monitoreo de colas
- **Laravel Reverb v1.7.0** - WebSockets nativo para tiempo real (OPCIONAL)

### Búsqueda y Observabilidad
- **Meilisearch 1.x** (Opcional) - Motor de búsqueda full-text
- **Laravel Scout v10.17** - Abstracción de búsqueda
- **Laravel Telescope v5.11** - Herramienta de debugging (desarrollo)
- **Sentry v4.15** (Opcional) - Monitoreo de errores

### Utilidades
- **spatie/laravel-permission v6.21** - Sistema de permisos y roles
- **dedoc/scramble** - Documentación automática de API
- **Laravel Pint** - Formateador de código PSR-12

### Containerización
- **Docker** - Containerización de la aplicación
- **Docker Compose v2.x** - Orquestación de contenedores
- **dunglas/frankenphp:1.11-php8.5-bookworm** - Imagen base PHP con FrankenPHP

## 📋 Requisitos

- Docker Engine 4.0+
- Docker Compose 2.0+
- Make (opcional, pero recomendado)
- 8 GB RAM mínimo (16 GB recomendado)
- 50 GB espacio en disco

## 🛠️ Instalación

### 1. Clonar el repositorio

```bash
git clone <repository-url>
cd apygg
```

### 2. Construir y levantar servicios

```bash
# Opción 1: Usando Make (recomendado)
make build
make up

# Opción 2: Docker Compose directo
docker compose --profile dev build
docker compose --profile dev up -d
```

**Nota**: Los archivos de entorno (`.env` y `env/dev.env`) se crean automáticamente desde los archivos `.example` durante el build si no existen.

### 3. Generar claves de aplicación

```bash
# Generar APP_KEY
make key
# O directamente:
docker compose exec app php artisan key:generate

# Generar JWT_SECRET
make jwt
# O directamente:
docker compose exec app php artisan jwt:secret -f
```

### 4. Ejecutar migraciones

```bash
make migrate
# O directamente:
docker compose exec app php artisan migrate
```

## 🚀 Comandos de Despliegue

### Usando Make (Recomendado)

El proyecto incluye un `Makefile` que simplifica los comandos más comunes:

```bash
# Construir contenedores
make build

# Levantar servicios
make up

# Detener servicios
make down

# Reiniciar servicios
make restart

# Ver logs
make logs

# Ver estado de servicios
make ps

# Acceder al shell del contenedor
make sh

# Generar APP_KEY
make key

# Generar JWT_SECRET
make jwt

# Ejecutar migraciones
make migrate

# Ejecutar seeders
make seed

# Ejecutar comandos artisan
make art cmd="comando"

# Ejecutar comandos composer
make composer cmd="comando"
```

### Usando Docker Compose directamente

```bash
# Construir contenedores
docker compose --profile dev build

# Levantar servicios
docker compose --profile dev up -d

# Detener servicios
docker compose --profile dev down

# Ver logs
docker compose --profile dev logs -f

# Ver estado
docker compose --profile dev ps

# Acceder al shell del contenedor
docker compose exec app bash

# Generar APP_KEY
docker compose exec app php artisan key:generate

# Generar JWT_SECRET
docker compose exec app php artisan jwt:secret -f

# Ejecutar migraciones
docker compose exec app php artisan migrate
```

### Desde dentro del contenedor (make sh)

```bash
# Acceder al contenedor
make sh

# Una vez dentro del contenedor, puedes ejecutar:
php artisan key:generate
php artisan jwt:secret -f
php artisan migrate
composer install
composer update
```

### Desde la terminal del host

Todos los comandos artisan y composer deben ejecutarse dentro del contenedor:

```bash
# Comandos artisan
docker compose exec app php artisan <comando>

# Comandos composer
docker compose exec app composer <comando>
```

## 📝 Comandos Útiles

### Gestión de Servicios

```bash
# Construir y levantar
make build && make up

# Reiniciar un servicio específico
make restart service=app

# Ver logs de un servicio específico
docker compose logs -f app
```

### Base de Datos

```bash
# Ejecutar migraciones
make migrate

# Ejecutar seeders
make seed

# Rollback migraciones
docker compose exec app php artisan migrate:rollback

# Refrescar base de datos
docker compose exec app php artisan migrate:fresh --seed
```

### Cache y Optimización

```bash
# Limpiar cache
docker compose exec app php artisan cache:clear
docker compose exec app php artisan config:clear
docker compose exec app php artisan route:clear
docker compose exec app php artisan view:clear

# Optimizar para producción
docker compose exec app php artisan config:cache
docker compose exec app php artisan route:cache
docker compose exec app php artisan view:cache
```

### Desarrollo

```bash
# Ejecutar tests
docker compose exec app php artisan test

# Formatear código
docker compose exec app ./vendor/bin/pint

# Tinker (REPL interactivo)
docker compose exec app php artisan tinker
```

## 🌐 Puertos y Servicios

| Servicio | Puerto | Descripción |
|----------|--------|-------------|
| App (Laravel) | 8010 | Aplicación principal |
| PostgreSQL | 8011 | Base de datos |
| Reverb (WebSockets) | 8012 | Servidor WebSocket |
| Meilisearch | 8013 | Motor de búsqueda |
| Redis | 8014 | Cache y colas |
| PgBouncer (Prod) | 8017 | Connection pooler |

## 📁 Estructura del Proyecto

```
apygg/
├── app/
│   ├── Console/           # Comandos de consola
│   ├── Events/            # Eventos de la aplicación
│   ├── Exceptions/        # Manejo de excepciones
│   ├── Helpers/           # Helpers y utilidades
│   ├── Http/
│   │   ├── Controllers/   # Controladores
│   │   ├── Middleware/    # Middleware
│   │   ├── Requests/      # Form Requests
│   │   └── Resources/     # API Resources
│   ├── Jobs/              # Jobs para colas
│   ├── Listeners/         # Listeners de eventos
│   ├── Logging/           # Configuración de logs
│   ├── Mail/              # Plantillas de email
│   ├── Models/            # Modelos Eloquent
│   ├── Notifications/     # Notificaciones
│   ├── Observers/         # Observers de modelos
│   ├── Policies/          # Políticas de autorización
│   ├── Providers/         # Service Providers
│   ├── Rules/             # Rules de validación
│   ├── Services/          # Servicios de negocio
│   └── Traits/            # Traits reutilizables
├── config/                # Archivos de configuración
├── database/              # Migraciones y seeders
├── docker/                # Configuración Docker
├── env/                   # Variables de entorno por ambiente
├── routes/
│   ├── api.php            # Punto de entrada de rutas
│   └── api/               # Módulos de rutas (uno por dominio)
└── tests/                 # Tests
```

## 🔧 Configuración de Entornos

El proyecto soporta múltiples entornos mediante perfiles de Docker Compose:

- **dev** - Desarrollo (por defecto)
- **staging** - Staging
- **prod** - Producción

```bash
# Usar entorno específico
ENV=staging make up
ENV=prod make build
```

## 🌐 Convenciones de Rutas

Este proyecto **NO utiliza el prefijo `/api/`** en las rutas.

### Estructura por Sistema

Las rutas se organizan según el sistema que las consume:

| Sistema | Prefijo | Descripción | Ejemplo |
|---------|---------|-------------|---------|
| **Público** | *(ninguno)* | Rutas públicas sin prefijo | `/auth/login`, `/search` |
| **Usuario autenticado** | `/user` | Endpoints del usuario en sesión | `/user/profile`, `/user/preferences` |
| **Admin** | `/admin` | Gestión y administración | `/admin/users`, `/admin/roles` |

### Ejemplos

```
POST /auth/login          → Login público
GET  /user/profile        → Perfil del usuario autenticado
PUT  /user/preferences    → Actualizar preferencias
GET  /admin/roles         → Listar roles (admin)
DELETE /admin/roles/{id}  → Eliminar rol (admin)
```

### Módulos disponibles en `routes/api/`

| Archivo | Prefijo | Descripción |
|---------|---------|-------------|
| `auth.php` | *(ninguno)* | Login, registro, refresh token |
| `user.php` | `/user` | Perfil y preferencias del usuario |
| `users.php` | `/users` | CRUD de usuarios |
| `roles.php` | `/admin` | Roles, permisos y activity logs |
| `api-keys.php` | `/admin` | Gestión de API Keys |
| `files.php` | *(ninguno)* | Subida y descarga de archivos |
| `search.php` | *(ninguno)* | Búsqueda global |
| `webhooks.php` | *(ninguno)* | Webhooks entrantes |
| `chat.php` | *(ninguno)* | Chat en tiempo real |
| `settings.php` | *(ninguno)* | Configuraciones globales |

## 📚 Documentación Adicional

- [TECH_STACK.md](./TECH_STACK.md) - Stack tecnológico detallado
- [TASKS.md](./TASKS.md) - Lista de tareas y fases del proyecto
- [PLAN_ACCION.md](./PLAN_ACCION.md) - Plan de acción y arquitectura
- [docs/](./docs/) - Documentación adicional

## 🤝 Contribuir

Las contribuciones son bienvenidas. Por favor:

1. Fork el proyecto
2. Crea una rama para tu feature (`git checkout -b feature/AmazingFeature`)
3. Commit tus cambios (`git commit -m 'Add some AmazingFeature'`)
4. Push a la rama (`git push origin feature/AmazingFeature`)
5. Abre un Pull Request

## 📄 Licencia

Este proyecto está bajo la Licencia MIT. Ver el archivo [LICENSE](LICENSE) para más detalles.

## 👥 Autores

- **APYGG Team**

---

**Nota**: Este es un boilerplate en desarrollo activo. Algunas características pueden estar en construcción.
