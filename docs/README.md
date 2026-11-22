# Índice de la documentación

Fuente de verdad: [`AGENTS.md`](../AGENTS.md) (si un doc lo contradice, manda `AGENTS.md`). Este índice lista **todos** los
documentos vigentes; un test (`DocsIndiceTest`) falla si falta alguno. Además: [`ARCHITECTURE.md`](../ARCHITECTURE.md),
[`TECH_STACK.md`](../TECH_STACK.md), [`CONTRIBUTING.md`](../CONTRIBUTING.md), [`CHANGELOG.md`](../CHANGELOG.md).

## Empezar y mantener un proyecto derivado
- [new-project.md](new-project.md): de cero a API corriendo, nombres Docker y puertos.
- [upgrading-from-boilerplate.md](upgrading-from-boilerplate.md): recibir mejoras (`upstream`, merge).
- [upgrading.md](upgrading.md): qué rompe cada versión y qué hacer.
- [modules.md](modules.md): módulos opcionales, cómo quitar o añadir uno.
- [git-hooks.md](git-hooks.md) · [dependabot-guide.md](dependabot-guide.md) · [PERMISOS_DOCKER.md](PERMISOS_DOCKER.md)

## Normas de código
- [CODING_STANDARDS.md](CODING_STANDARDS.md) · [CONTROLLER_CONVENTIONS.md](CONTROLLER_CONVENTIONS.md) · [eloquent-vs-query-builder.md](eloquent-vs-query-builder.md)
- [api-routes-conventions.md](api-routes-conventions.md) · [api-response-structure.md](api-response-structure.md) · [error-handling.md](error-handling.md) · [DATE_FORMAT_STANDARD.md](DATE_FORMAT_STANDARD.md)
- [api-contract.md](api-contract.md): OpenAPI y tipos para los fronts (`openapi.json`, `openapi-breaking-ignore.txt`).
- [id-strategy.md](id-strategy.md) · [money.md](money.md) · [sequences.md](sequences.md) · [i18n.md](i18n.md)
- [migrations.md](migrations.md) · [seeders-guide.md](seeders-guide.md) · [octane.md](octane.md) · [queues-and-jobs.md](queues-and-jobs.md) · [events-listeners-system.md](events-listeners-system.md)
- [adr/](adr/): registros de decisiones (históricos).

## Seguridad, acceso y multiempresa
- [permissions.md](permissions.md) · [sessions-and-2fa.md](sessions-and-2fa.md) · [api-keys.md](api-keys.md) · [cors-configuration.md](cors-configuration.md)
- [impersonation.md](impersonation.md) <!-- module:impersonation -->
- [multi-tenancy.md](multi-tenancy.md) · [tenant-onboarding.md](tenant-onboarding.md) · [settings-and-flags.md](settings-and-flags.md) · [features-usage-examples.md](features-usage-examples.md)
- [audit.md](audit.md) · [security-audit.md](security-audit.md) · [logging.md](logging.md)
- [privacy.md](privacy.md) <!-- module:privacy -->
- [social-login.md](social-login.md): login con Google/GitHub (módulo `social-login`) <!-- module:social-login -->
- [otp-login.md](otp-login.md): login con código por correo/SMS (módulo `otp-login`) <!-- module:otp-login -->
- [offline-sync.md](offline-sync.md): sincronización offline para apps (módulo `offline-sync`: UUID del cliente, If-Match/412, `POST /sync/batch`) <!-- module:offline-sync -->
- [jwt-keys.md](jwt-keys.md): JWT asimétrico (RS256/ES256) con `kid` y rotación

## Funcionalidad
- [notifications.md](notifications.md) · [mail-system.md](mail-system.md) · [files.md](files.md)
- [billing.md](billing.md) <!-- module:billing -->
- [export-import.md](export-import.md) <!-- module:export-import -->
- [webhooks.md](webhooks.md) · [integrations.md](integrations.md) · [idempotency.md](idempotency.md)
- [llm.md](llm.md) <!-- module:llm -->
- [docs-assistant.md](docs-assistant.md) <!-- module:docs-assistant -->
- [menu-system.md](menu-system.md)
- [maintenance-mode.md](maintenance-mode.md) <!-- module:maintenance -->
- [backoffice.md](backoffice.md) <!-- module:backoffice -->
- [websockets.md](websockets.md) <!-- module:reverb -->
- [frontend-demo.md](frontend-demo.md) <!-- module:frontend-demo -->

## Caché, búsqueda y datos
- [cache-service-usage.md](cache-service-usage.md) · [cache-invalidation.md](cache-invalidation.md) · [cache-warming.md](cache-warming.md) · [cache-inspection.md](cache-inspection.md)
- [redis-configuration.md](redis-configuration.md) · [backups-system.md](backups-system.md)
- [meilisearch-configuration.md](meilisearch-configuration.md) <!-- module:search -->

## Operación y despliegue
- [deploy.md](deploy.md): producción (`.env.production.example`, validación de arranque) · [docker-maintenance.md](docker-maintenance.md) · [frankenphp-configuration.md](frankenphp-configuration.md) · [kubernetes-health-checks.md](kubernetes-health-checks.md)
- [scheduler-tasks.md](scheduler-tasks.md) · [horizon-reverb-telescope.md](horizon-reverb-telescope.md) · [local-services.md](local-services.md)
- [logging-file-structure.md](logging-file-structure.md) · [logging-sentry-behavior.md](logging-sentry-behavior.md) · [sentry-configuration.md](sentry-configuration.md)
- [metrics.md](metrics.md): módulo `metrics`, `GET /metrics` Prometheus protegido <!-- module:metrics -->

## Pruebas y rendimiento
- [testing.md](testing.md) · [testing-database.md](testing-database.md)
- [seed-load.md](seed-load.md) <!-- module:seed-load -->
- [load-testing.md](load-testing.md) <!-- module:load-testing -->
- [performance-baseline.md](performance-baseline.md) <!-- module:load-testing -->
