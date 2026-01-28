# Scripts de Despliegue

Este directorio contiene scripts para diferentes estrategias de despliegue con zero-downtime.

## Scripts Disponibles

### `blue-green.sh` - Blue-Green Deployment

Despliegue Blue-Green con cambio instantáneo de tráfico.

**Uso:**
```bash
./scripts/deploy/blue-green.sh [environment] [image-tag]
```

**Ejemplo:**
```bash
./scripts/deploy/blue-green.sh staging latest
./scripts/deploy/blue-green.sh production v1.2.3
```

**Características:**
- Zero-downtime deployment
- Health checks automáticos
- Rollback automático si falla
- Cambio instantáneo de tráfico

### `canary.sh` - Canary Deployment

Despliegue gradual con monitoreo de métricas.

**Uso:**
```bash
./scripts/deploy/canary.sh [environment] [image-tag] [percentage]
```

**Ejemplo:**
```bash
./scripts/deploy/canary.sh staging latest 10
./scripts/deploy/canary.sh production v1.2.3 25
```

**Características:**
- Despliegue gradual (10% → 50% → 100%)
- Monitoreo de métricas en cada etapa
- Rollback automático si métricas fallan
- Integración con feature flags

### `rollback.sh` - Rollback Automático

Revierte a la versión anterior en caso de fallo.

**Uso:**
```bash
./scripts/deploy/rollback.sh [environment]
```

**Ejemplo:**
```bash
./scripts/deploy/rollback.sh staging
./scripts/deploy/rollback.sh production
```

**Características:**
- Detección automática de versión anterior
- Rollback según estrategia usada
- Verificación post-rollback
- Notificaciones automáticas

## Variables de Entorno

Los scripts usan las siguientes variables de entorno:

- `HEALTH_CHECK_URL`: URL del health check (default: `http://localhost:8010/health/ready`)
- `DEPLOYMENT_STRATEGY`: Estrategia de despliegue (blue-green, canary, rolling)
- `BLUE_HEALTH_URL`: URL de health check del entorno Blue
- `GREEN_HEALTH_URL`: URL de health check del entorno Green
- `CANARY_HEALTH_URL`: URL de health check del Canary

## Integración con Plataformas

### Docker Compose

```bash
# Blue-Green
docker compose -f docker-compose.blue.yml up -d
docker compose -f docker-compose.green.yml up -d
# Cambiar tráfico manualmente o con nginx/haproxy
```

### Kubernetes

```bash
# Blue-Green
kubectl set image deployment/apygg-blue app=apygg:v1.2.3
kubectl rollout status deployment/apygg-blue
kubectl patch service apygg -p '{"spec":{"selector":{"version":"blue"}}}'
```

### AWS ECS

```bash
# Blue-Green
aws ecs update-service --cluster apygg --service apygg-blue --force-new-deployment
aws elbv2 modify-listener --listener-arn $LISTENER_ARN --default-actions Type=forward,TargetGroupArn=$BLUE_TG_ARN
```

### Railway

```bash
# Blue-Green
railway up --service blue --detach
railway link --service blue
```

## Monitoreo y Métricas

Los scripts incluyen verificación de:
- Health checks (`/health/ready`)
- Tasa de errores
- Latencia
- Throughput
- Uso de recursos

## Troubleshooting

### Health check falla
- Verificar que el servicio esté corriendo
- Revisar logs: `docker logs apygg_app` o `kubectl logs deployment/apygg`
- Verificar conectividad de red

### Rollback no funciona
- Verificar que exista una versión anterior
- Revisar permisos de ejecución
- Verificar configuración de la plataforma

## Próximos Pasos

- [ ] Integrar con Prometheus/Grafana para métricas avanzadas
- [ ] Agregar soporte para más plataformas
- [ ] Implementar notificaciones (Slack, Discord, Email)
- [ ] Agregar tests automatizados para los scripts
