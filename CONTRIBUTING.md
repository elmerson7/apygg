# Contributing to APYGG

¡Gracias por tu interés en contribuir a APYGG! Esta guía te ayudará a empezar.

## Requisitos Previos

- Docker Engine 4.0+
- Docker Compose 2.0+
- Git
- Node.js 18+ (para hooks de pre-commit)
- Make (opcional)

## Setup Local

### 1. Clonar el repositorio

```bash
git clone <repository-url>
cd apygg
```

### 2. Configurar entorno

```bash
cp .env.example .env
cp env/dev.env.example env/dev.env
```

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
make setup
```

### 6. Ejecutar tests

```bash
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
├── DTOs/             # Data Transfer Objects
├── Enums/            # Enumeraciones
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
├── Repositories/     # Repositories (patrón Repository)
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
| Repositories | `SingularNameRepository` | `UserRepository` |
| DTOs | `ActionNameDTO` | `CreateUserDTO` |
| Enums | `TypeNameEnum` | `UserStatusEnum` |
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
# Formatear código
./vendor/bin/pint

# Ejecutar tests
make test

# Ejecutar análisis estático
./vendor/bin/phpstan analyse

# Ejecutar lint
make lint
```

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
git commit -m "refactor(services): extract validation to DTOs"

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
├── Feature/          # Tests de integración
│   ├── Auth/
│   ├── Users/
│   ├── ApiKeys/
│   └── ...
└── Unit/            # Tests unitarios
    ├── Services/
    ├── Helpers/
    └── ...
```

### Ejecutar Tests

```bash
# Todos los tests
make test

# Tests específicos
docker compose exec app php artisan test --filter=AuthTest

# Con cobertura
docker compose exec app php artisan test --coverage

# Tests en paralelo
docker compose exec app php artisan test --parallel
```

### Escribir Tests

```php
use Tests\TestCase;

class UserTest extends TestCase
{
    public function test_user_can_be_created(): void
    {
        $user = User::factory()->create();
        
        $this->assertDatabaseHas('users', [
            'id' => $user->id,
        ]);
    }
}
```

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
