# TASKS.md — Gaps del Boilerplate APYGG

Análisis de lo que falta para que este sea un **boilerplate robusto**.

---

## ✅ Lo que YA existe

- Auth, Roles, Permissions, ApiKeys, Webhooks, Files, Notifications (FCM), Settings
- Middlewares: RateLimit adaptativo, CORS, Security Headers, IP Whitelist, ETag, TraceId, Compression
- Services bien segregados por responsabilidad
- CI/CD: `ci.yml`, `cd.yml`, SonarCloud, Dependabot
- Docker + Makefile
- Telescope, Sentry, Backup, Cache, LogService

---

## 🔴 Crítico (sin esto no es robusto)

### 1. Testing — casi vacío

Solo existen `ExampleTest.php`, `UserControllerTest.php` y `UserPermissionsTest.php`.

- [ ] Tests de Auth (login, register, logout, refresh, forgot-password)
- [ ] Tests de ApiKeys (crear, listar, revocar, rotar)
- [ ] Tests de Webhooks (crear, entregar, reintentar)
- [ ] Tests de Files (subir, eliminar, descargar)
- [ ] Tests de Roles y Permissions (CRUD, asignación)
- [ ] Tests de Settings
- [ ] Tests de cada Middleware (RateLimit, CORS, IpWhitelist, CheckRole, CheckPermission, etc.)
- [ ] Tests unitarios de cada Service (AuthService, UserService, TokenService, ApiKeyService, WebhookService, FileService, CacheService, LogService, SecurityService, NotificationService, PasswordService, RoleService, PermissionService)
- [ ] Tests unitarios de Helpers (ApiResponse, DateHelper, SecurityHelper, StringHelper)
- [ ] Tests de Rules de validación
- [ ] Tests de Health endpoints
- [ ] Objetivo: cobertura mínima del 80% en código crítico

### 2. Contratos / Interfaces

No existe `app/Contracts` ni `app/Interfaces`.

- [x] Crear `AuthServiceInterface`
- [x] Crear `UserServiceInterface`
- [x] Crear `TokenServiceInterface`
- [x] Crear `ApiKeyServiceInterface`
- [x] Crear `WebhookServiceInterface`
- [x] Crear `FileServiceInterface`
- [x] Crear `CacheServiceInterface`
- [x] Crear `LogServiceInterface`
- [x] Crear `NotificationServiceInterface`
- [x] Crear `SecurityServiceInterface`
- [x] Crear `PermissionServiceInterface`
- [x] Crear `RoleServiceInterface`
- [x] Bindear interfaces en un `ServiceProvider` dedicado

### 3. Repository Pattern

No existe `app/Repositories`.

- [x] Crear `UserRepository`
- [x] Crear `RoleRepository`
- [x] Crear `PermissionRepository`
- [x] Crear `ApiKeyRepository`
- [x] Crear `WebhookRepository`
- [x] Crear `FileRepository`
- [x] Crear `RepositoryInterface` base
- [x] Actualizar Services para usar Repositories en vez de Eloquent directo

---

## 🟡 Importante (mejora calidad del boilerplate)

### 4. DTOs (Data Transfer Objects)

No existe `app/DTOs`.

- [x] Crear `LoginDTO`
- [x] Crear `RegisterDTO`
- [x] Crear `CreateUserDTO`
- [x] Crear `UpdateUserDTO`
- [x] Crear `CreateApiKeyDTO`
- [x] Crear `CreateWebhookDTO`
- [ ] Reemplazar arrays sueltos en Services por DTOs tipados

### 5. Documentación de API (OpenAPI/Swagger)

No hay `openapi.yaml` ni integración con Scramble/L5-Swagger.

- [x] Instalar `dedoc/scramble` o `darkaonline/l5-swagger`
- [x] Configurar auto-generación desde Form Requests y Resources
- [x] Publicar documentación en `/docs/api`
- [x] Documentar autenticación JWT y API Keys
- [x] Documentar todos los endpoints existentes

### 6. Enums centralizados

No existe `app/Enums`.

- [x] Crear `UserStatusEnum` (active, inactive, banned)
- [x] Crear `RoleEnum` (admin, user, guest)
- [x] Crear `ApiKeyScopeEnum`
- [x] Crear `WebhookEventEnum`
- [x] Crear `LogActionEnum` (created, updated, deleted, restored)
- [x] Crear `FileTypeEnum`
- [ ] Reemplazar strings hardcodeados por Enums en Models y Services

### 7. Socialite completo

`SocialAuthService.php` solo tiene 1.4KB — parece incompleto.

- [ ] Implementar login con Google
- [ ] Implementar login con GitHub
- [ ] Endpoint `GET /auth/social/{provider}/redirect`
- [ ] Endpoint `GET /auth/social/{provider}/callback`
- [ ] Configurar providers en `config/services.php`
- [ ] Crear `SocialAccount` model y migración (user_id, provider, provider_id, token)

### 8. Factories faltantes

Faltan factories para varios modelos.

- [ ] Crear `WebhookFactory`
- [ ] Crear `WebhookDeliveryFactory`
- [ ] Crear `FileFactory`
- [ ] Crear `DeviceTokenFactory`
- [ ] Crear `SettingsFactory`
- [ ] Crear `JwtBlacklistFactory`

### 9. Console Commands documentados

El directorio `app/Console` existe pero faltan commands robustos.

- [ ] Verificar que todos los Commands tengan `--help` descriptivo
- [ ] Crear `UserCreateCommand` (crear usuarios desde CLI)
- [ ] Crear `UserRoleAssignCommand` (asignar roles desde CLI)
- [ ] Crear `ApiKeyCreateCommand` (generar API keys desde CLI)
- [ ] Documentar todos los commands disponibles en README

---

## 🟢 Nice-to-have (boilerplate de referencia)

### 10. CHANGELOG.md

- [ ] Crear `CHANGELOG.md` con formato [Keep a Changelog](https://keepachangelog.com)
- [ ] Documentar versión actual y cambios principales
- [ ] Configurar en CI para actualización automática en releases

### 11. CONTRIBUTING.md

- [ ] Crear `CONTRIBUTING.md`
- [ ] Guía de setup local
- [ ] Convenciones de código
- [ ] Proceso de PR y code review
- [ ] Estándares de commits (Conventional Commits)

### 12. ARCHITECTURE.md

- [ ] Descripción de la arquitectura del boilerplate
- [ ] Diagramas de componentes
- [ ] Flujos principales (auth, request lifecycle)
- [ ] Decisiones arquitectónicas (ADRs)
- [ ] Cómo agregar un nuevo módulo

### 13. Multi-tenancy (base)

- [ ] Evaluar si el boilerplate debe soportar multi-tenancy
- [ ] Si aplica: agregar `tenant_id` a tablas principales
- [ ] Crear middleware `ResolveTenant`
- [ ] Scopes globales por tenant en BaseModel

### 14. Stubs personalizados

- [ ] Publicar stubs de Laravel (`php artisan stub:publish`)
- [ ] Personalizar stub de Controller para usar BaseController
- [ ] Personalizar stub de Model para usar BaseModel con UUID
- [ ] Personalizar stub de Request para usar BaseFormRequest
- [ ] Personalizar stub de Resource para usar BaseResource

---

## 📋 Orden de Prioridad Recomendado

1. **Interfaces** → primero para no romper nada al agregar Repositories
2. **Repositories** → desacoplar DB de Services
3. **Tests** → cubrir código existente antes de agregar más
4. **DTOs** → mejorar tipado en Services existentes
5. **Enums** → limpiar strings hardcodeados
6. **Factories faltantes** → habilitar más tests
7. **Documentación API** → Scramble sobre código ya existente
8. **Socialite** → feature nueva
9. **CHANGELOG + CONTRIBUTING + ARCHITECTURE** → documentación final
