# Recibir mejoras del boilerplate en un proyecto derivado

Guía paso a paso para traer a tu proyecto (creado con `make init`) lo nuevo de APYGG sin perder
tu trabajo. Qué *rompe* en cada versión está en `docs/upgrading.md`; las novedades, en
`CHANGELOG.md`; cómo se crea el proyecto, en `docs/new-project.md`.

## Resumen (el camino feliz)

```bash
make backup                              # 1. copia de la BD
git switch -c actualizar-boilerplate     # 2. rama aparte
make upgrade-check                       # 3. qué trae upstream y dónde habrá conflictos
git merge upstream/main                  # 4. el merge (o: git merge vX.Y.Z)
# ... resolver conflictos si los hay (abajo) y leer docs/upgrading.md + CHANGELOG.md
make composer cmd=install                # 5. dependencias según composer.lock
make migrate                             #    migraciones nuevas
make check && make test && make test-contract && make doctor     # 6. verificar
```

Solo después de que todo esté verde, integra la rama en la tuya principal.

## 1. El remoto `upstream`

El boilerplate es un remoto más de tu repo, llamado `upstream`:

- `make init` lo crea solo: si `origin` apunta a un repo que termina en `/apygg`, lo **renombra** a
  `upstream` y le quita el push (`NO_PUSH_AL_BOILERPLATE`). Después tú añades **tu** `origin`.
- Si clonaste de otra forma (por ejemplo desde un espejo con otro nombre) o el repo no vino de un clon, créalo a mano:

```bash
git remote add upstream <url-del-boilerplate>
git remote set-url --push upstream NO_PUSH_AL_BOILERPLATE   # nadie publica allí por error
git remote -v
```

- Si tu repo se creó **sin** clonar el boilerplate (se copió la carpeta y se hizo `git init`), no hay
  historia común: el primer merge necesita `git merge --allow-unrelated-histories upstream/main` y
  tendrá muchos conflictos «add/add»; es mejor rehacer el proyecto desde un clon.

## 2. Antes de nada: `make upgrade-check`

```bash
make upgrade-check                       # usa upstream/main
make upgrade-check UPSTREAM_BRANCH=otra  # otra rama del boilerplate
```

Solo hace `git fetch upstream <rama>` (no cambia tu árbol) y muestra: cuántos commits te faltan y
cuántos tienes tú de más, la lista de commits nuevos, el resumen de cambios en archivos sensibles
(`database/migrations`, `.env.example`, `compose.env.example`, `docker-compose.yml`, `composer.json`,
`composer.lock`, `Makefile`, `make/`) y **los archivos que cambiaron en ambos lados**: esos son los
conflictos probables. Sale con «Estás al día» si no hay nada nuevo.

Haz `make backup` antes de aplicar: el merge puede traer migraciones.

## 3. El merge (y por qué no `rebase`)

```bash
git switch -c actualizar-boilerplate
git fetch upstream
git merge upstream/main
```

Se usa **merge, no rebase**, por tres razones:

1. Tu historia ya está publicada en tu `origin`: reescribirla (rebase) obliga a `push --force` y rompe
   los clones de tu equipo.
2. Un rebase reaplica **uno a uno** tus commits sobre el boilerplate y repite los mismos conflictos en
   cada commit; el merge los resuelve **una vez**.
3. El merge deja un commit con los dos padres: el siguiente `git merge upstream/main` sabe que lo ya
   traído está traído y solo conflictúa con lo nuevo.

Para actualizar a una **versión concreta** en vez de a la punta de `main`, usa su etiqueta
(`vX.Y.Z`, ver «Versionado» abajo):

```bash
git fetch upstream --tags
git merge v1.2.0
```

Si algo sale mal a medias: `git merge --abort` te devuelve al estado anterior. Si ya hiciste el
commit del merge y quieres deshacerlo: `git reset --hard ORIG_HEAD` (solo si aún no lo publicaste).

## 4. Resolver conflictos típicos

Lista de lo que suele chocar y cómo resolverlo. Ver los pendientes: `git status` (sección
«Unmerged paths»); tras resolver cada uno, `git add <archivo>`; al terminar, `git commit` (o `git merge --continue`).

| Archivo | Qué hacer |
|---|---|
| `docs/openapi.json` | **No lo resuelvas a mano.** Acepta cualquiera de los dos lados (`git checkout --theirs docs/openapi.json && git add docs/openapi.json`), termina el merge y regenera con `make openapi`; commitea el resultado. Un test de la suite falla si no coincide con el código. |
| `docs/api-types.d.ts` | Igual: regenera con `make types`. |
| `composer.lock` | **No edites a mano** los marcadores `<<<<<<<`. Resuelve primero `composer.json` (conserva tus paquetes y los nuevos de upstream), toma cualquier lado del lock y ejecuta `make composer cmd="update --lock --no-scripts"` seguido de `make composer cmd=install`. Si el lock queda con el `content-hash` viejo, `composer validate` lo avisa. |
| `.env.example` | Conserva tus líneas y añade las variables nuevas de upstream (los marcadores `# module:<nombre>` se conservan). **Tu `.env` no se toca solo**: copia a mano a `.env` las variables nuevas que necesites (`git diff HEAD...upstream/main -- .env.example` te las muestra). |
| `compose.env.example` | Igual que el anterior para `compose.env` (que está en `.gitignore`). `make validate` avisa si falta algo obligatorio. |
| `docker-compose.yml`, `Makefile`, `make/*.mk` | Si no los personalizaste, toma el de upstream. Si sí, conserva lo tuyo y añade lo nuevo. Después `make build` (la imagen puede haber cambiado). |
| `README.md`, `AGENTS.md`, `docs/` | `make init` no los toca para que casi no choquen. Si moviste tu README (`README.project.md` → `README.md`), conserva el tuyo: `git checkout --ours README.md && git add README.md`. |
| `config/*.php`, providers, `routes/api.php` | Conserva los **marcadores** `// module:<nombre>` (los usa `make module-remove`). Los `require` de rutas y bindings nuevos de upstream se añaden. |
| Migraciones | Ver apartado siguiente. |

Antes de dar un archivo por resuelto comprueba que no quedan marcadores: `git diff --check` y
`grep -rn '^<<<<<<<' . --include=* -l --exclude-dir=vendor --exclude-dir=node_modules --exclude-dir=.git`.

Consejo: activa `git config rerere.enabled true` en tu repo; Git recuerda cómo resolviste un
conflicto y lo repite en los siguientes merges.

### Migraciones

- **Nunca reescribas una migración ya aplicada**, ni la tuya ni la del boilerplate (`docs/migrations.md`).
  Las del boilerplate llegan como archivos nuevos con su fecha y se aplican con `make migrate`.
- Dos migraciones, una tuya y otra de upstream, que tocan la misma tabla no chocan en Git (archivos
  distintos): ordénalas por fecha y prueba `make migrate` en una BD de desarrollo (con `make backup`
  hecho) antes de seguir.
- Si tu proyecto ya desplegó y upstream **editó en sitio** una migración antigua (solo ocurrió antes
  de 1.0.0; se anota en `docs/upgrading.md`, apartado «0. Antes de merge»), esa acción es manual.
- Tras migrar, `make schema-dump` refresca `database/schema.sql` si tu proyecto lo versiona.

## 5. Leer qué cambia: `docs/upgrading.md` y `CHANGELOG.md`

Después del merge (o antes, con `git show upstream/main:docs/upgrading.md`):

- `docs/upgrading.md` está organizado **por versión de origen**. Empieza por la sección que corresponde
  a tu punto de partida y sigue hacia arriba. Cada entrada dice si hay variables `.env` nuevas, migraciones,
  contratos que cambian de firma (si heredas de una clase del núcleo, mira ahí), comandos a ejecutar
  (por ejemplo `billing:sync-plans` tras migrar) o seeders que re-sembrar.
- `CHANGELOG.md` (sección `Unreleased` y las versiones) lista las novedades sin impacto obligatorio.
- Si dudas de tu punto de partida: `git merge-base HEAD upstream/main` te da el último commit común, y
  `git diff $(git merge-base HEAD upstream/main) upstream/main -- database/migrations .env.example` lo que cambió.

## 6. Módulos que quitaste

Un módulo opcional se quita con `make module-remove name=<modulo>`
(`docs/modules.md`): borra sus archivos, quita los fragmentos con marcador de los archivos compartidos y
**conserva sus migraciones**, añadiendo una nueva `*_drop_<modulo>_module_tables.php` que elimina sus
tablas. Por eso:

- Las migraciones del módulo y la de baja siguen en tu repo: `make migrate` crea las tablas y las borra
  justo después en una BD nueva. No las borres.
- Si upstream **modifica un archivo del módulo que quitaste**, Git lo marca como conflicto
  *modify/delete* («deleted by us»). El archivo ya no te interesa: resuélvelo con
  `git rm <archivo>`. Para verlos: `git status --short | grep '^DU'` (en `git status` largo: «deleted by us»).
  Todos de golpe: `git status --short | awk '/^DU/ {print $2}' | xargs git rm`.
- Si upstream **añade archivos nuevos** a un módulo que quitaste, llegan sin conflicto y quedan huérfanos
  (con sus tests). Tras el merge, vuelve a ejecutar `make module-remove name=<modulo>` (es idempotente y
  borra lo del manifiesto, que upstream habrá actualizado) y revisa `git status`.
- Los marcadores `// module:<nombre>` que upstream añada en archivos compartidos (un binding nuevo, un
  `require` de rutas) también reaparecen: el mismo `module-remove` los quita.
- Un módulo quitado cuyas dependencias cambian (`depends` del manifiesto) puede impedir quitar otro: el
  comando te lo dice.
- Para ver qué haría sin tocar nada: `make module-remove name=<modulo> DRY=1`.

## 7. Verificación tras actualizar

```bash
make composer cmd=install      # vendor/ según composer.lock
make build                     # solo si cambiaron docker/ o docker-compose.yml; luego make up
make migrate                   # migraciones nuevas
make check                     # pint + phpstan (nivel 8)
make test                      # suite por defecto (Unit, Feature, Arch)
make test-contract             # contrato público de la API (usa docs/openapi.json)
make test-concurrency          # carreras reales (si usas billing, secuencias, cuotas...)
make doctor                    # entorno sano: contenedores, BD, vendor al día
```

Si falla `make test-contract` o un test avisa de que `docs/openapi.json` está desactualizado: `make openapi`
y commitea. Si falta una variable de entorno: compara `.env` con `.env.example`.
Si el stack no arranca tras cambiar `docker/`: `make build && make up`.

## 8. Versionado

El boilerplate se versiona con etiquetas **`vX.Y.Z`** (semver; las notas, en `CHANGELOG.md`):

- **Patch** (`v1.0.1`): correcciones, sin acciones obligatorias.
- **Minor** (`v1.1.0`): funciones nuevas compatibles; puede traer migraciones y variables nuevas.
- **Major** (`v2.0.0`): cambios que rompen; `docs/upgrading.md` lo detalla paso a paso.

```bash
git fetch upstream --tags
git tag -l 'v*' --sort=-v:refname | head      # versiones disponibles
git describe --tags upstream/main             # en qué versión está la punta de upstream
git merge v1.2.0                              # actualizar a una versión concreta
```

Actualiza de versión en versión si te saltas varias de una vez y lees `docs/upgrading.md` en orden: es más
fácil resolver y diagnosticar un salto pequeño que uno grande. `main` de upstream puede tener cambios aún
no etiquetados: para producción, usa etiquetas.
