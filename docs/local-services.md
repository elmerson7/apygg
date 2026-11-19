# Servicios de desarrollo: Mailpit y S3 local

El perfil `dev` de Compose añade dos servicios que solo existen en desarrollo:

| Servicio | Imagen | Para qué | Puerto en el host (127.0.0.1) | Variable (`compose.env`) |
|---|---|---|---|---|
| `mailpit` | `axllent/mailpit:v1.34.0` | Recibe los correos por SMTP y los muestra en una bandeja web | SMTP 8016, UI 8017 | `MAILPIT_SMTP_PORT`, `MAILPIT_UI_PORT` |
| `s3` | `rustfs/rustfs:1.0.6` | Emulador de S3 (API + consola web) | API 8018, consola 8019 | `S3_PORT`, `S3_CONSOLE_PORT` |
| `s3-init` | `curlimages/curl:8.11.1` | Tarea de un solo uso: crea los buckets | — | `S3_BUCKET`, `S3_PRIVATE_BUCKET` |

Los puertos solo escuchan en `127.0.0.1`. Producción no usa nada de esto: ahí van el
proveedor de correo y el S3 reales.

## Por qué RustFS y no MinIO

MinIO dejó de distribuir imágenes de su edición comunitaria: `minio/minio` ya no
existe en Docker Hub (la API devuelve 404 y `docker pull minio/minio:latest` falla con
"repository does not exist") y la copia de `quay.io` exige credenciales. Una imagen
que no se puede descargar no sirve de base para un boilerplate.

[RustFS](https://github.com/rustfs/rustfs) (Apache 2.0) es un servidor de objetos con API
S3, publicado en Docker Hub y actualizado (1.0.6, 15-nov-2026). Cabe en un solo
contenedor, toma las credenciales por variables de entorno y trae consola web. Garage
exige asignar un "layout" de nodos y generar claves por CLI; SeaweedFS necesita varios
procesos para tener S3. Para desarrollo, RustFS es lo más simple.

Los buckets se crean con `curl --aws-sigv4` (`docker/s3/init.sh`), sin instalar un
cliente S3. Si cambias de emulador, solo hay que tocar el servicio `s3` y ese script.

## Arrancar

```bash
make up-dev        # stack + Mailpit + S3 local (crea los buckets)
make mail-ui       # URL de la bandeja de correo
make s3-ui         # URL de la consola S3 (usuario/clave: S3_ACCESS_KEY / S3_SECRET_KEY)
```

`make up-dev` equivale a `make up DEV=true`.

## Correo con Mailpit

Todo correo sale por el camino único de `docs/mail-system.md`: sin cuentas en la BD
usa el mailer de respaldo `MAIL_MAILER`. Apúntalo a Mailpit en `.env` y reinicia:

```env
MAIL_MAILER=smtp
MAIL_HOST=mailpit      # nombre del servicio, dentro de la red de Compose
MAIL_PORT=1025         # puerto interno, no MAILPIT_SMTP_PORT
MAIL_ENCRYPTION=null
```

Si usas una cuenta de correo en la BD (proveedor `smtp`), pon esos mismos valores
en su proveedor. `make mail-clear` vacía la bandeja.

## Archivos en S3 local

`.env.example` ya trae `AWS_*` apuntando al emulador, pero los archivos siguen en disco
local para que arrancar no exija ningún servicio. Para usar S3:

```env
FILE_DISK_PUBLIC=s3
FILE_DISK_PRIVATE=s3_private
```

| Variable | Valor de dev | Notas |
|---|---|---|
| `AWS_ENDPOINT` | `http://s3:9000` | Visto desde el contenedor `app` |
| `AWS_USE_PATH_STYLE_ENDPOINT` | `true` | Obligatorio con un emulador |
| `AWS_BUCKET` / `AWS_PRIVATE_BUCKET` | `apygg` / `apygg-private` | Deben coincidir con `S3_BUCKET` / `S3_PRIVATE_BUCKET` |
| `AWS_ACCESS_KEY_ID` / `AWS_SECRET_ACCESS_KEY` | `apygg-dev` / `apygg-dev-secret` | Deben coincidir con `S3_ACCESS_KEY` / `S3_SECRET_KEY` |
| `AWS_URL` | `http://localhost:8018/apygg` | URL pública del bucket público, para el navegador |

El bucket público tiene política de lectura anónima solo de objetos; el privado no
tiene política, así que sus objetos solo se leen con firma.

Limitación conocida: las URL temporales firmadas se generan contra `AWS_ENDPOINT`
(`s3:9000`), que el navegador del host no resuelve. En desarrollo los privados se
descargan con `GET /files/{id}/download`; `make s3-test` comprueba la URL firmada desde
dentro del contenedor.

## Comprobar

```bash
make s3-init   # crea los buckets si faltan (idempotente)
make s3-test   # php artisan storage:check --disk=s3 --disk=s3_private
```

`php artisan storage:check` (sin `--disk`) prueba los discos de `config/files.php`, sean
locales o S3 real: sube un archivo, lo lee, pide su URL (firmada en el privado) y lo
borra. Sustituye a los antiguos `test:s3` y `minio:test`.

## Tests de integración

`tests/Integration/` (testsuite `Integration`) prueba contra los contenedores reales:

- un correo enviado por el camino único llega a la API de Mailpit;
- un archivo privado se sube al bucket privado, se lee por URL temporal (y sin firma da
  401/403) y se borra; uno público es accesible sin firma.

```bash
make up-dev
make test-integration
```

El target define `INTEGRATION_MAIL_URL` (API de Mailpit; `http://mailpit:8025`) e
`INTEGRATION_S3_ENDPOINT` (`$AWS_ENDPOINT`). Opcionales: `INTEGRATION_MAIL_SMTP_HOST`,
`INTEGRATION_MAIL_SMTP_PORT`, `INTEGRATION_S3_KEY`, `INTEGRATION_S3_SECRET`,
`INTEGRATION_S3_BUCKET`, `INTEGRATION_S3_PRIVATE_BUCKET`. Sin esas variables los tests
se **omiten** (aparecen como skipped en `make test`), así que no rompen la suite por
defecto. Desde fuera del contenedor `app`, usa los puertos del host
(`http://127.0.0.1:8017`, `http://127.0.0.1:8018`) y `INTEGRATION_MAIL_SMTP_HOST=127.0.0.1`
con `INTEGRATION_MAIL_SMTP_PORT=8016`.

## Notas

1. **Producción no usa este perfil.** `ENV=prod` no lo activa.
2. **El mismo código** sirve para S3 real: cambian `AWS_ENDPOINT` (vacío), las claves,
   `AWS_USE_PATH_STYLE_ENDPOINT=false` y `AWS_URL`.
3. **Varios proyectos en la misma máquina:** cambia `PROJECT` y los puertos en
   `compose.env`; los datos de S3 viven en el volumen `s3data` del proyecto.
4. **Todas las imágenes están fijadas** a una versión; súbelas a propósito, no con `latest`.
