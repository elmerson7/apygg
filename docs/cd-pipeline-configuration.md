# Configuración del Pipeline CD

Esta guía explica cómo configurar el pipeline de despliegue continuo (CD) para diferentes plataformas.

## Secrets Requeridos

Configura los siguientes secrets en GitHub (Settings → Secrets and variables → Actions):

### Secrets Obligatorios

- `DEPLOYMENT_URL`: URL base del despliegue (ej: `https://api.apygg.com`)
- `BLUE_HEALTH_URL`: URL del health check del entorno Blue (ej: `https://blue.apygg.com/health/ready`)
- `GREEN_HEALTH_URL`: URL del health check del entorno Green (ej: `https://green.apygg.com/health/ready`)
- `CANARY_HEALTH_URL`: URL del health check del Canary (ej: `https://canary.apygg.com/health/ready`)

### Secrets Opcionales (según plataforma)

#### Para Docker Registry
- `DOCKER_REGISTRY_USERNAME`: Usuario del registry
- `DOCKER_REGISTRY_PASSWORD`: Contraseña del registry

#### Para AWS
- `AWS_ACCESS_KEY_ID`: Access key de AWS
- `AWS_SECRET_ACCESS_KEY`: Secret key de AWS
- `AWS_REGION`: Región de AWS (ej: `us-east-1`)

#### Para Railway
- `RAILWAY_TOKEN`: Token de Railway

#### Para Kubernetes
- `KUBECONFIG`: Configuración de Kubernetes (base64 encoded)

#### Para Notificaciones
- `SLACK_WEBHOOK_URL`: Webhook de Slack para notificaciones
- `DISCORD_WEBHOOK_URL`: Webhook de Discord para notificaciones

## Configuración por Plataforma

### Docker Compose

Si usas Docker Compose, los scripts están listos para usar. Solo necesitas:

1. Crear `docker-compose.blue.yml` y `docker-compose.green.yml`
2. Configurar nginx/haproxy para routing
3. Actualizar los scripts con tus rutas específicas

### Kubernetes

1. Crear deployments para Blue y Green:
```yaml
# deployment-blue.yaml
apiVersion: apps/v1
kind: Deployment
metadata:
  name: apygg-blue
spec:
  replicas: 3
  selector:
    matchLabels:
      app: apygg
      version: blue
  template:
    metadata:
      labels:
        app: apygg
        version: blue
    spec:
      containers:
      - name: app
        image: ghcr.io/usuario/apygg:blue-latest
```

2. Crear Service con selector dinámico:
```yaml
apiVersion: v1
kind: Service
metadata:
  name: apygg
spec:
  selector:
    version: blue  # Cambiar a 'green' para switch
  ports:
  - port: 80
    targetPort: 8000
```

3. Actualizar los scripts con comandos `kubectl` específicos

### AWS ECS

1. Crear servicios Blue y Green en ECS
2. Configurar Application Load Balancer con target groups
3. Actualizar scripts con comandos `aws cli`

Ejemplo de comando en script:
```bash
aws ecs update-service \
  --cluster apygg \
  --service apygg-blue \
  --force-new-deployment \
  --desired-count 3
```

### Railway

1. Crear servicios Blue y Green en Railway
2. Configurar variables de entorno
3. Actualizar scripts con comandos `railway`

Ejemplo:
```bash
railway up --service blue --detach
railway link --service blue
```

## Environments en GitHub

Configura los environments en GitHub (Settings → Environments):

### Staging
- Protection rules: Opcional
- Secrets específicos de staging
- URL: `https://staging.apygg.com`

### Production
- Protection rules: **Requerir aprobación manual**
- Secrets específicos de producción
- URL: `https://api.apygg.com`

## Estrategias de Despliegue

### Blue-Green (Recomendado para Producción)

**Ventajas:**
- Zero-downtime garantizado
- Rollback instantáneo
- Fácil de verificar antes de cambiar tráfico

**Cuándo usar:**
- Producción
- Cuando necesitas cambio instantáneo
- Cuando tienes recursos para mantener dos entornos

### Canary (Recomendado para Testing)

**Ventajas:**
- Despliegue gradual
- Detección temprana de problemas
- Menor impacto si falla

**Cuándo usar:**
- Staging
- Cuando quieres probar con tráfico real
- Cuando tienes métricas avanzadas

### Rolling (Recomendado para Alta Disponibilidad)

**Ventajas:**
- Menor uso de recursos
- Actualización continua
- Sin necesidad de dos entornos completos

**Cuándo usar:**
- Cuando tienes múltiples instancias
- Cuando quieres actualización continua
- Cuando los recursos son limitados

## Monitoreo

### Health Checks

El pipeline verifica automáticamente:
- `/health/ready` - Readiness probe
- `/health/live` - Liveness probe
- `/health/detailed` - Health check completo

### Métricas Recomendadas

Para Canary deployments, monitorea:
- Tasa de errores (debe ser < 1%)
- Latencia p95 (debe ser similar a producción)
- Throughput (debe ser estable)
- Uso de CPU/Memoria (debe estar dentro de límites)

## Troubleshooting

### El despliegue falla en health check

1. Verificar que el servicio esté corriendo
2. Revisar logs: `docker logs apygg_app` o `kubectl logs deployment/apygg`
3. Verificar conectividad de red
4. Revisar configuración de health checks

### Rollback no funciona

1. Verificar que exista una versión anterior
2. Revisar permisos de ejecución
3. Verificar configuración de la plataforma
4. Revisar logs del job de rollback

### Canary no aumenta tráfico

1. Verificar configuración de load balancer
2. Revisar feature flags
3. Verificar métricas (si están fuera de parámetros, no aumenta)
4. Revisar logs del job de canary

## Próximos Pasos

- [ ] Configurar notificaciones (Slack/Discord)
- [ ] Integrar con Prometheus/Grafana
- [ ] Agregar más métricas personalizadas
- [ ] Implementar A/B testing con feature flags
