# Sistema de Backups - Guía de Uso

## Descripción

Sistema completo de backups automáticos y manuales para base de datos PostgreSQL con soporte para compresión, almacenamiento remoto (S3) y retención automática.

## Comandos Disponibles

### 1. Crear Backup

```bash
# Backup de base de datos (por defecto)
php artisan backup:create

# Backup de base de datos sin comprimir
php artisan backup:create --no-compress

# Backup sin subir a remoto
php artisan backup:create --no-upload

# Backup de archivos (cuando esté implementado)
php artisan backup:create --files
```

**Ubicación local:** `storage/app/backups/backup_db_{database}_{timestamp}.sql.gz`

### 2. Listar Backups

```bash
# Listar todos los backups locales
php artisan backup:list

# Incluir backups remotos
php artisan backup:list --remote

# Filtrar por tipo
php artisan backup:list --type=database

# Formato JSON
php artisan backup:list --format=json
```

### 3. Restaurar Backup

```bash
# Restaurar backup (con confirmación)
php artisan backup:restore backup_db_apygg_2026-01-26_03-00-00.sql.gz

# Restaurar sin confirmación (peligroso)
php artisan backup:restore backup_db_apygg_2026-01-26_03-00-00.sql.gz --force
```

**⚠️ ADVERTENCIA:** La restauración reemplazará todos los datos actuales de la base de datos.

### 4. Limpiar Backups Antiguos

```bash
# Limpiar backups según política de retención
php artisan backup:clean

# Simular limpieza sin eliminar
php artisan backup:clean --dry-run
```

## Configuración

### Variables de Entorno

Agregar en `.env`:

```env
# Sistema de Backups
BACKUP_REMOTE_ENABLED=true  # Habilitado por defecto (sube a S3/MinIO)
BACKUP_REMOTE_DISK=s3
BACKUP_REMOTE_PATH=backups
BACKUP_COMPRESSION_ENABLED=true
BACKUP_COMPRESSION_FORMAT=gzip
BACKUP_RETENTION_DAILY=7
BACKUP_RETENTION_WEEKLY=30
BACKUP_RETENTION_MONTHLY=90
BACKUP_DATABASE_ENABLED=true
BACKUP_FILES_ENABLED=false
BACKUP_NOTIFICATIONS_ENABLED=true
BACKUP_NOTIFY_ON_SUCCESS=false
BACKUP_NOTIFY_ON_FAILURE=true
```

### Configuración de S3/MinIO

**Para desarrollo con MinIO:**

```env
AWS_ACCESS_KEY_ID=minioadmin
AWS_SECRET_ACCESS_KEY=minioadmin
AWS_DEFAULT_REGION=us-east-1
AWS_BUCKET=elmerson  # O el nombre de tu bucket
AWS_ENDPOINT=http://minio:9000
AWS_USE_PATH_STYLE_ENDPOINT=true
AWS_URL=http://localhost:8015
BACKUP_REMOTE_ENABLED=true
BACKUP_REMOTE_DISK=s3
```

**Para producción con AWS S3:**

```env
AWS_ACCESS_KEY_ID=tu_access_key_real
AWS_SECRET_ACCESS_KEY=tu_secret_key_real
AWS_DEFAULT_REGION=us-east-1
AWS_BUCKET=tu-bucket-produccion
BACKUP_REMOTE_ENABLED=true
BACKUP_REMOTE_DISK=s3
```

**Importante:** El bucket debe existir antes de crear backups. Si no existe, el sistema mantendrá el backup en storage local y mostrará una advertencia.

## Política de Retención

El sistema mantiene automáticamente:

- **Diarios**: Últimos 7 días (todos los backups)
- **Semanales**: Últimos 30 días (1 backup por semana)
- **Mensuales**: Últimos 90 días (1 backup por mes)

La limpieza se ejecuta automáticamente cada día a las 4 AM.

## Backups Automáticos

Los backups se crean automáticamente:

- **Frecuencia**: Diariamente
- **Hora**: 3:00 AM
- **Compresión**: Habilitada por defecto
- **Subida remota**: Según configuración

## Estructura de Archivos

```
storage/app/backups/
├── backup_db_apygg_2026-01-26_03-00-00.sql.gz
├── backup_db_apygg_2026-01-27_03-00-00.sql.gz
└── ...
```

## Ejemplos de Uso

### Crear backup manual antes de migración

```bash
php artisan backup:create
```

### Listar backups disponibles

```bash
php artisan backup:list --remote
```

### Restaurar backup específico

```bash
php artisan backup:restore backup_db_apygg_2026-01-26_03-00-00.sql.gz
```

### Verificar política de retención

```bash
php artisan backup:clean --dry-run
```

## Notas Importantes

1. **Backups locales**: Se guardan en `storage/app/backups/` (siempre se mantiene una copia local)
2. **Backups remotos**: Se suben automáticamente a S3/MinIO si está habilitado
3. **Fallback automático**: Si falla la subida a S3, el backup se mantiene en storage local
4. **Bucket requerido**: El bucket S3 debe existir antes de crear backups (crear manualmente en MinIO/AWS)
5. **Compresión**: Reduce significativamente el tamaño de los backups
6. **Retención**: Los backups antiguos se eliminan automáticamente según política
7. **Restauración**: Siempre crear un backup antes de restaurar

## Troubleshooting

### Error: "pg_dump: command not found"

El cliente PostgreSQL ya está instalado en el Dockerfile. Si aún aparece este error, reconstruir el contenedor:

```bash
docker compose build --no-cache app
docker compose up -d app
```

### Error: "server version mismatch"

Si el servidor PostgreSQL es versión 18 pero el cliente es versión 15, el Dockerfile ya incluye `postgresql-client-18` del repositorio oficial de PostgreSQL. Reconstruir el contenedor:

```bash
docker compose build --no-cache app
docker compose up -d app
```

### Error: "Backup no encontrado"

Verificar que el backup existe:

```bash
php artisan backup:list
ls -lh storage/app/backups/
```

### Error: "NoSuchBucket" o "bucket does not exist"

El bucket debe existir antes de crear backups. Crear el bucket:

**En MinIO (desarrollo):**
```bash
# Acceder a la consola web: http://localhost:8016
# O usar mc (MinIO Client):
docker compose exec minio mc mb local/elmerson
```

**En AWS S3 (producción):**
- Crear el bucket desde AWS Console
- O usar AWS CLI: `aws s3 mb s3://tu-bucket`

### Error al subir a S3

Verificar conexión a S3/MinIO:

```bash
php artisan test:s3
```

Este comando prueba la conexión y muestra detalles del error si falla.
