# Configuración de MinIO (S3 Compatible)

## Resumen

MinIO es un servidor de almacenamiento de objetos compatible con la API de S3. Se usa en **desarrollo** y **staging** para simular AWS S3. En **producción** se usa AWS S3 real.

## Arquitectura

- **Dev/Staging**: MinIO (Docker) → Simula S3
- **Producción**: AWS S3 → Servicio real de Amazon

## Configuración

### 1. Docker Compose

MinIO está configurado en `docker-compose.yml` con profiles `["dev", "staging"]`:

```yaml
minio:
  image: minio/minio:latest
  container_name: apygg_minio
  profiles: ["dev", "staging"]
  ports:
    - "8015:9000"  # API S3
    - "8016:9001"  # Consola web
```

### 2. Variables de Entorno

#### Variables Docker (`env/dev.env` y `env/staging.env`)

```env
# MinIO (S3 compatible - Solo dev/staging)
MINIO_ROOT_USER=minioadmin
MINIO_ROOT_PASSWORD=minioadmin
```

#### Variables Laravel (`.env` en raíz)

**Para Dev/Staging (MinIO):**
```env
# MinIO Configuration (S3 compatible)
AWS_ACCESS_KEY_ID=minioadmin
AWS_SECRET_ACCESS_KEY=minioadmin
AWS_DEFAULT_REGION=us-east-1
AWS_BUCKET=apygg
AWS_ENDPOINT=http://minio:9000
AWS_USE_PATH_STYLE_ENDPOINT=true
AWS_URL=http://localhost:8015
```

**Para Producción (AWS S3 real):**
```env
# AWS S3 Configuration (Producción)
AWS_ACCESS_KEY_ID=tu_access_key_real
AWS_SECRET_ACCESS_KEY=tu_secret_key_real
AWS_DEFAULT_REGION=us-east-1
AWS_BUCKET=tu-bucket-produccion
AWS_ENDPOINT=
AWS_USE_PATH_STYLE_ENDPOINT=false
AWS_URL=https://tu-bucket.s3.amazonaws.com
```

### 3. Iniciar MinIO

```bash
# Desarrollo
make up ENV=dev

# Staging
make up ENV=staging
```

MinIO estará disponible en:
- **API S3**: `http://localhost:8015`
- **Consola Web**: `http://localhost:8016`
  - Usuario: `minioadmin`
  - Contraseña: `minioadmin`

### 4. Crear Bucket

1. Abre la consola web: `http://localhost:8016`
2. Inicia sesión con `minioadmin` / `minioadmin`
3. Crea un bucket con el nombre configurado en `AWS_BUCKET` (ej: `apygg`)

### 5. Uso en Código

El código es **idéntico** para MinIO y AWS S3:

```php
use Illuminate\Support\Facades\Storage;

// Subir archivo
Storage::disk('s3')->put('archivo.txt', $contenido);

// Obtener URL
$url = Storage::disk('s3')->url('archivo.txt');

// Usar FileService
FileService::upload($file, 'uploads', null, 's3');
```

## ¿Por qué funciona?

MinIO implementa la misma **API REST** que AWS S3. Laravel usa `league/flysystem-aws-s3-v3` que habla el protocolo S3. Al cambiar el `AWS_ENDPOINT`, las peticiones van a MinIO en lugar de AWS, pero usando el mismo protocolo.

## Diferencias Clave

| Aspecto | Dev/Staging (MinIO) | Producción (AWS S3) |
|---------|---------------------|---------------------|
| Endpoint | `http://minio:9000` | (vacío = AWS por defecto) |
| Credenciales | `minioadmin` / `minioadmin` | Credenciales AWS reales |
| Path Style | `true` | `false` |
| URL pública | `http://localhost:8015` | `https://bucket.s3.amazonaws.com` |
| Consola | `http://localhost:8016` | AWS Console |

## Notas Importantes

1. **Producción NO usa MinIO**: Solo dev y staging tienen el servicio MinIO en Docker
2. **Mismo código**: No necesitas cambiar código entre ambientes
3. **Bucket debe existir**: Crea el bucket en MinIO antes de usar
4. **Variables separadas**: Docker vars en `env/*.env`, Laravel vars en `.env`
