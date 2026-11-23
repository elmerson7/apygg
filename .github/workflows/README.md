# GitHub Actions

Cuatro workflows. `main` está protegida: todo cambio entra por PR y necesita el check **CI Pipeline Success** en verde;
no se permite push directo, force-push ni borrar la rama (también para administradores).

## `ci.yml` — CI Pipeline

Corre en cada push y PR a `main`/`develop`, y a mano (`workflow_dispatch`).

| Job | Qué comprueba |
|---|---|
| Lint & Code Quality | `pint --test` y `phpstan analyse` (nivel y rutas en `phpstan.neon`) |
| Tests & Coverage | suite Pest completa con pcov; **falla si la cobertura baja del 80 %** (`--min=80`); sube el informe a Codecov |
| Tests con API_PREFIX=v1 | la suite con el prefijo de versión activo |
| Cambios que rompen (oasdiff) | `docs/openapi.json` frente al de `main`; excepciones aceptadas en `docs/openapi-breaking-ignore.txt` |
| Security Scan (Composer Audit) | `composer audit` con y sin dependencias de desarrollo |
| Migraciones (lint, upgrade y rollback) | `migrations:lint`, migrar desde cero, subir desde la versión anterior y deshacer |
| Sin módulos (…) | matriz: quita módulos opcionales con `module:remove` y corre la suite restante |
| E2E (stack real) | recorridos HTTP contra el stack de `docker-compose.e2e.yml`; solo en push a `main` y a mano; no bloquea |
| Docker Build | construye la imagen de producción |
| CI Pipeline Success | agrega el resultado de los anteriores (salvo E2E); es el check obligatorio de la protección de `main` |

El número de checks que se ve en un PR es mayor que el de jobs porque la matriz de módulos genera uno por escenario.

## `release.yml` — imagen inmutable

En push a `main` y en tags `v*`: construye la imagen, la escanea con Trivy y la publica en GHCR como `sha-<commit>`
(y `vX.Y.Z` en tags). Nunca `latest`. Ver `docs/deploy.md`.

## `cd.yml` — despliegue manual

`workflow_dispatch`: despliega o hace rollback por SSH + Docker Compose. Ver `docs/deploy.md`.

## Análisis de calidad que corre de verdad

PHPStan, Pint, cobertura mínima del 80 %, `composer audit`, Trivy sobre la imagen, oasdiff sobre el contrato y el
linter de migraciones. **No hay SonarCloud**: el workflow existía pero nunca tuvo token (`SONAR_TOKEN`) y salía
cancelado en cada corrida, así que se quitó. Un proyecto derivado que lo quiera debe crear el proyecto en
sonarcloud.io, añadir el secreto y su propio workflow.

## Dependabot

`.github/dependabot.yml` abre PRs mensuales de Composer y de GitHub Actions. Pasan por la misma CI.

## Si la CI falla

1. Abre el job en rojo y lee el paso que falla.
2. Reprodúcelo en local con Make (`make check`, `make test`, `make art cmd="migrations:lint"`, `make openapi-breaking`).
3. Corrige en tu rama y vuelve a empujar: el PR no se puede unir hasta que **CI Pipeline Success** esté en verde.
