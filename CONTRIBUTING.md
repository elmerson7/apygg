# Contributing to APYGG

¡Gracias por tu interés en contribuir a APYGG! Esta guía te ayudará a empezar.

Antes de tocar código, lee [AGENTS.md](./AGENTS.md): es la fuente de verdad y sus reglas prevalecen sobre este documento.

## Requisitos Previos

- Docker Engine y Docker Compose v2 (`docker compose`)
- Git
- Make (recomendado: todos los comandos del proyecto pasan por `make`)

## Comandos

Todo corre dentro de Docker a través de `make`. No ejecutes `php artisan`, `composer` ni `pest` en el host. `make help` lista los targets.

## Setup Local

### 1. Clonar el repositorio

```bash
git clone <repository-url>
cd apygg
```

### 2. Configurar entorno

```bash
cp compose.env.example compose.env
```

`make build` crea `.env` desde `.env.example` si no existe.

### 3. Construir y levantar servicios

```bash
make build
make up
```

### 4. Instalar dependencias

```bash
docker compose exec app composer install
docker compose exec app npm install
```

### 5. Configurar claves y migraciones

```bash
make key          # muestra una APP_KEY; cópiala a .env
make jwt          # muestra un secreto JWT; cópialo a .env
make migrate
make seed
```

### 6. Ejecutar tests

```bash
make testdb-migrate   # solo la primera vez
make test
```

## Convenciones de Código

### Estilo de Código

- Seguimos **PSR-12** como estándar de estilo de código PHP
- Usamos **Laravel Pint** para formateo automático
- Ejecutar antes de commitear: `./vendor/bin/pint`

### Estructura de Archivos

```
app/
├── Console/          # Comandos Artisan
├── Contracts/        # Interfaces
├── Events/           # Eventos
├── Exceptions/       # Excepciones personalizadas
├── Helpers/          # Funciones auxiliares
├── Http/
│   ├── Controllers/  # Controladores
│   ├── Middleware/    # Middlewares
│   ├── Requests/     # Form Requests
│   └── Resources/    # API Resources
├── Jobs/             # Jobs de cola
├── Listeners/        # Listeners de eventos
├── Models/           # Modelos Eloquent
├── Notifications/    # Notificaciones
├── Observers/        # Observers de modelos
├── Policies/         # Policies de autorización
├── Providers/        # Service Providers
├── Rules/            # Reglas de validación
├── Services/         # Servicios de lógica de negocio
└── Traits/           # Traits reutilizables
```

### Convenciones de Nomenclatura

| Elemento | Convención | Ejemplo |
|----------|------------|---------|
| Controllers | `SingularNameController` | `UserController` |
| Models | `SingularName` | `User` |
| Migrations | `create_table_name_table` | `create_users_table` |
| Services | `SingularNameService` | `UserService` |
| Requests | `ActionNameRequest` | `StoreUserRequest` |
| Resources | `SingularNameResource` | `UserResource` |

### Naming Rules

- **Variables y métodos**: camelCase → `getUserName()`, `$userId`
- **Clases**: PascalCase → `UserService`, `BaseController`
- **Constantes**: UPPER_SNAKE_CASE → `MAX_RETRIES`, `DEFAULT_TIMEOUT`
- **Tablas de BD**: snake_case plural → `users`, `api_keys`
- **Columnas de BD**: snake_case → `created_at`, `user_id`

## Proceso de Pull Request

### 1. Crear rama

```bash
git checkout -b feature/tu-feature
# o
git checkout -b fix/tu-bug
# o
git checkout -b refactor/tu-refactor
```

### 2. Hacer cambios

- Mantén commits pequeños y atómicos
- Cada commit debe compilar y funcionar
- Agrega tests para nueva funcionalidad

### 3. Antes de crear PR

```bash
# Formatear y analizar (Pint + PHPStan, nivel 8)
make check

# Ejecutar tests
make test
```

`make install-hooks` instala los git hooks del repositorio (pre-commit y commit-msg).

### 4. Crear Pull Request

```bash
git add .
git commit -m "feat: descripción del cambio"
git push origin feature/tu-feature
```

Luego crea la PR en GitHub con:
- Título descriptivo
- Descripción del cambio
- Referencia a issue (si aplica)
- Screenshots (si aplica)

### 5. Code Review

- Al menos 1 aprobación requerida
- Todos los checks de CI deben pasar
- Sin conflictos con main

## Estándar de Commits (Conventional Commits)

Usamos [Conventional Commits](https://www.conventionalcommits.org/):

```
<tipo>(<alcance>): <descripción>

[opcional: cuerpo]

[opcional: pie]
```

### Tipos

| Tipo | Descripción |
|------|-------------|
| `feat` | Nueva funcionalidad |
| `fix` | Corrección de bug |
| `docs` | Cambios en documentación |
| `style` | Cambios de estilo (sin afectar lógica) |
| `refactor` | Refactorización de código |
| `perf` | Mejora de rendimiento |
| `test` | Agregar o modificar tests |
| `build` | Cambios en build o dependencias |
| `ci` | Cambios en CI/CD |
| `chore` | Tareas de mantenimiento |
| `revert` | Revertir commit anterior |

### Ejemplos

```bash
# Nueva funcionalidad
git commit -m "feat(auth): add Google OAuth login"

# Corrección de bug
git commit -m "fix(apikeys): prevent duplicate key generation"

# Documentación
git commit -m "docs(readme): update installation steps"

# Refactorización
git commit -m "refactor(services): extract validation to Form Requests"

# Breaking change
git commit -m "feat(api)!: change response format to JSON:API

BREAKING CHANGE: response structure changed"
```

### Alcances Comunes

- `auth` - Autenticación
- `users` - Usuarios
- `apikeys` - API Keys
- `webhooks` - Webhooks
- `files` - Archivos
- `notifications` - Notificaciones
- `middleware` - Middlewares
- `services` - Servicios
- `tests` - Tests
- `ci` - CI/CD
- `docker` - Docker
- `docs` - Documentación

## Testing

### Estructura de Tests

```
tests/
├── Feature/          # Tests de integración (HTTP, comandos, seguridad)
└── Unit/             # Tests unitarios por dominio: Docs, Llm, Logging, Mail,
                      # Menu, Middleware, Models, Requests, Security, y ArchTest.php
```

Los tests usan **Pest 4** (no PHPUnit). Los nombres van en español y en tercera persona, describiendo la conducta. Cubre también el caso denegado.

### Ejecutar Tests

```bash
# Todos los tests
make test

# Un test concreto
make test-filter filter=NombreDelTest

# Solo unitarios, de integración o de arquitectura
make test-unit
make test-feature
make test-arch

# Con cobertura
make test-coverage
```

### Escribir Tests

```php
<?php

use App\Models\User;

it('no permite a un usuario sin permiso listar los roles', function () {
    $user = User::factory()->create();

    $this->actingAs($user, 'api')
        ->getJson('/roles')
        ->assertForbidden();
});
```

Los comandos `pest` concretos y la BD de tests están en [docs/testing.md](./docs/testing.md).

## Reporting Issues

### Bug Reports

Incluye:
1. Descripción del bug
2. Pasos para reproducir
3. Comportamiento esperado
4. Comportamiento actual
5. Capturas de pantalla (si aplica)
6. Versión de Laravel y PHP
7. Logs relevantes

### Feature Requests

Incluye:
1. Descripción de la feature
2. Caso de uso
3. Propuesta de implementación (opcional)
4. Alternativas consideradas

## Contacto

- Issues: GitHub Issues
- Discusiones: GitHub Discussions
