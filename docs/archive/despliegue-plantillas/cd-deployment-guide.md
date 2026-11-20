# Guía de Despliegue CD - Explicación Simple

## 🔄 Flujo Completo del CD Pipeline

```
1. Push a main/staging
   ↓
2. CI Pipeline se ejecuta (lint, tests, build)
   ↓
3. Si CI pasa ✅ → CD Pipeline se ejecuta automáticamente
   ↓
4. CD Pipeline:
   a) Determina estrategia (blue-green/canary/rolling)
   b) Construye imagen Docker
   c) Despliega según plataforma (cPanel/Railway/etc)
   ↓
5. Health checks verifican que todo funciona
   ↓
6. ✅ Despliegue completado
```

## 📦 ¿Qué hace el CD Pipeline?

1. **Construye la imagen Docker** con tu código
2. **La sube a un registro** (GitHub Container Registry, Docker Hub, etc.)
3. **Se conecta a tu servidor** (cPanel, Railway, AWS, etc.)
4. **Despliega el código nuevo**
5. **Verifica que funcione** (health checks)
6. **Si falla → hace rollback automático**

## 🖥️ Despliegue en cPanel (Staging)

### ¿Qué es cPanel?
- Panel de control web para servidores compartidos
- Típicamente tiene: FTP, SSH, Git, PHP, MySQL
- **NO tiene Docker** (normalmente)

### Flujo para cPanel:

```
1. CI pasa ✅
   ↓
2. CD Pipeline se ejecuta
   ↓
3. Se conecta por SSH a tu servidor cPanel
   ↓
4. Ejecuta comandos:
   - cd public_html/staging
   - git pull origin main
   - composer install --no-dev
   - php artisan migrate --force
   - php artisan cache:warm
   - php artisan config:cache
   ↓
5. Health check: curl https://staging.tudominio.com/health/ready
   ↓
6. ✅ Despliegue completado
```

### Configuración necesaria:

**En GitHub Secrets:**
- `CPANEL_HOST`: tu-servidor.com (o IP)
- `CPANEL_USER`: usuario_ssh
- `CPANEL_SSH_KEY`: clave privada SSH (o password)

**En cPanel:**
- Clonar repo en `public_html/staging`
- Configurar `.env` con variables de staging
- Dar permisos SSH al usuario

## 🚂 Despliegue en Railway (Staging/Producción)

### ¿Qué es Railway?
- Plataforma PaaS (Platform as a Service)
- Similar a Heroku, Render, Fly.io
- **SÍ tiene Docker** nativo
- Despliega automáticamente desde Git

### Flujo para Railway:

```
1. CI pasa ✅
   ↓
2. CD Pipeline se ejecuta
   ↓
3. Construye imagen Docker
   ↓
4. La sube a GitHub Container Registry (ghcr.io)
   ↓
5. Se conecta a Railway API
   ↓
6. Railway:
   - Descarga la nueva imagen
   - Reemplaza el contenedor actual
   - Reinicia el servicio
   ↓
7. Health check: curl https://tu-app.railway.app/health/ready
   ↓
8. ✅ Despliegue completado
```

### Configuración necesaria:

**En Railway:**
1. Crear proyecto nuevo
2. Conectar con GitHub (opcional, Railway puede hacer auto-deploy)
3. Obtener Railway Token

**En GitHub Secrets:**
- `RAILWAY_TOKEN`: token de Railway
- `RAILWAY_SERVICE_ID`: ID del servicio en Railway

## 🔑 Diferencias Clave

| Característica | cPanel | Railway |
|----------------|--------|---------|
| Docker | ❌ No | ✅ Sí |
| Despliegue | Git pull + comandos | Imagen Docker |
| Configuración | Manual (SSH) | API automática |
| Complejidad | Media | Baja |
| Costo | Barato | Medio |

## 📝 Ejemplo Práctico

### cPanel (Staging):
```bash
# El workflow ejecuta esto por SSH:
ssh usuario@servidor.com
cd public_html/staging
git pull origin main
composer install --no-dev
php artisan migrate --force
php artisan cache:warm
```

### Railway (Staging):
```bash
# El workflow ejecuta esto:
docker build -t ghcr.io/usuario/apygg:staging .
docker push ghcr.io/usuario/apygg:staging
railway up --service staging --image ghcr.io/usuario/apygg:staging
```

## 🎯 Configuración Paso a Paso

### Para cPanel (Staging):

1. **En tu servidor cPanel:**
   ```bash
   cd public_html
   git clone https://github.com/tu-usuario/apygg.git staging
   cd staging
   cp .env.example .env
   # Editar .env con tus variables de staging
   ```

2. **En GitHub Secrets:**
   - `CPANEL_HOST`: tu-servidor.com
   - `CPANEL_USER`: usuario_ssh
   - `CPANEL_SSH_KEY`: clave privada SSH (generar con `ssh-keygen`)
   - `CPANEL_PATH`: public_html/staging (opcional, default)
   - `STAGING_URL`: https://staging.tudominio.com

3. **El workflow automáticamente:**
   - Se conecta por SSH
   - Hace `git pull`
   - Ejecuta `composer install`
   - Ejecuta migraciones
   - Pre-calienta cache

### Para Railway (Producción):

1. **En Railway:**
   - Crear cuenta en https://railway.app
   - Crear proyecto nuevo
   - Conectar con GitHub (opcional)
   - Obtener Service ID y Token

2. **En GitHub Secrets:**
   - `RAILWAY_TOKEN`: token de Railway
   - `RAILWAY_SERVICE_ID`: ID del servicio
   - `PRODUCTION_URL`: https://tu-app.railway.app

3. **El workflow automáticamente:**
   - Construye imagen Docker
   - La sube a GitHub Container Registry
   - Despliega en Railway
   - Verifica health check

## 🎯 Recomendación

- **Staging (cPanel)**: Usar Git + SSH (simple, funciona) ✅ Configurado
- **Producción (Railway)**: Usar Docker (más robusto, escalable) ✅ Configurado

Ambos están listos en el workflow CD. Solo configura los secrets.
