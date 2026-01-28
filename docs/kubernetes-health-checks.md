# Configuración de Health Checks para Kubernetes

Esta guía explica cómo configurar los health check endpoints de APYGG API en un entorno Kubernetes.

## Endpoints Disponibles

La aplicación proporciona los siguientes endpoints de health check:

- **`GET /health/live`** - Liveness probe (verifica que la app está viva)
- **`GET /health/ready`** - Readiness probe (verifica servicios críticos: DB, Redis)
- **`GET /health/detailed`** - Health check completo (requiere autenticación, verifica todos los servicios)

## Probes de Kubernetes

Kubernetes utiliza tres tipos de probes para monitorear el estado de los contenedores:

### 1. Liveness Probe

**Propósito:** Verificar que el contenedor está vivo y funcionando.

**Comportamiento:**
- Si falla → Kubernetes reinicia el pod
- Se ejecuta durante todo el ciclo de vida del contenedor

**Endpoint recomendado:** `/health/live`

**Configuración recomendada:**

```yaml
livenessProbe:
  httpGet:
    path: /health/live
    port: 8000
    scheme: HTTP
  initialDelaySeconds: 30    # Esperar 30 segundos antes del primer check
  periodSeconds: 10           # Verificar cada 10 segundos
  timeoutSeconds: 3           # Timeout de 3 segundos por request
  successThreshold: 1         # 1 éxito = considerar vivo
  failureThreshold: 3         # 3 fallos consecutivos = reiniciar pod
```

**Cuándo usar:**
- Para detectar deadlocks o estados bloqueados
- Para reiniciar contenedores que no responden
- **NO** debe verificar servicios externos (DB, Redis) porque causaría reinicios innecesarios

### 2. Readiness Probe

**Propósito:** Verificar que el pod está listo para recibir tráfico.

**Comportamiento:**
- Si falla → Kubernetes deja de enviar tráfico al pod (pero NO lo reinicia)
- Se ejecuta durante todo el ciclo de vida del contenedor
- El pod se marca como "NotReady" y se elimina del Service

**Endpoint recomendado:** `/health/ready`

**Configuración recomendada:**

```yaml
readinessProbe:
  httpGet:
    path: /health/ready
    port: 8000
    scheme: HTTP
  initialDelaySeconds: 5     # Esperar 5 segundos antes del primer check
  periodSeconds: 5            # Verificar cada 5 segundos
  timeoutSeconds: 2           # Timeout de 2 segundos por request
  successThreshold: 1         # 1 éxito = considerar listo
  failureThreshold: 3         # 3 fallos consecutivos = marcar como no listo
```

**Cuándo usar:**
- Para verificar conectividad a servicios críticos (DB, Redis)
- Para evitar enviar tráfico a pods que no pueden procesar requests
- Durante despliegues, para esperar a que el pod esté completamente listo

### 3. Startup Probe (Opcional)

**Propósito:** Verificar que la aplicación ha iniciado correctamente.

**Comportamiento:**
- Se ejecuta solo durante el inicio del contenedor
- Si falla → Kubernetes espera antes de iniciar los otros probes
- Una vez exitoso, los otros probes toman el control

**Endpoint recomendado:** `/health/live` o `/health/ready`

**Configuración recomendada:**

```yaml
startupProbe:
  httpGet:
    path: /health/live
    port: 8000
    scheme: HTTP
  initialDelaySeconds: 0     # Empezar inmediatamente
  periodSeconds: 5            # Verificar cada 5 segundos
  timeoutSeconds: 3           # Timeout de 3 segundos
  successThreshold: 1         # 1 éxito = aplicación iniciada
  failureThreshold: 30        # 30 intentos = máximo 2.5 minutos de espera
```

**Cuándo usar:**
- Para aplicaciones que tardan en iniciar (migraciones, warm-up, etc.)
- Para evitar que liveness probe reinicie el pod durante el arranque
- Especialmente útil con Laravel (migraciones, cache warming, etc.)

## Ejemplo Completo de Deployment

```yaml
apiVersion: apps/v1
kind: Deployment
metadata:
  name: apygg-api
  labels:
    app: apygg-api
spec:
  replicas: 3
  selector:
    matchLabels:
      app: apygg-api
  template:
    metadata:
      labels:
        app: apygg-api
    spec:
      containers:
      - name: app
        image: apygg/api:latest
        imagePullPolicy: Always
        ports:
        - name: http
          containerPort: 8000
          protocol: TCP
        
        env:
        - name: APP_ENV
          value: "production"
        - name: APP_DEBUG
          value: "false"
        - name: DB_HOST
          valueFrom:
            secretKeyRef:
              name: apygg-secrets
              key: db-host
        - name: DB_DATABASE
          valueFrom:
            secretKeyRef:
              name: apygg-secrets
              key: db-database
        # ... más variables de entorno
        
        # Startup Probe (para aplicaciones que tardan en iniciar)
        startupProbe:
          httpGet:
            path: /health/live
            port: 8000
          initialDelaySeconds: 0
          periodSeconds: 5
          timeoutSeconds: 3
          failureThreshold: 30  # Máximo 2.5 minutos
        
        # Liveness Probe
        livenessProbe:
          httpGet:
            path: /health/live
            port: 8000
          initialDelaySeconds: 30
          periodSeconds: 10
          timeoutSeconds: 3
          failureThreshold: 3
        
        # Readiness Probe
        readinessProbe:
          httpGet:
            path: /health/ready
            port: 8000
          initialDelaySeconds: 5
          periodSeconds: 5
          timeoutSeconds: 2
          failureThreshold: 3
        
        resources:
          requests:
            memory: "256Mi"
            cpu: "250m"
          limits:
            memory: "512Mi"
            cpu: "500m"
        
        # Health check para Docker (opcional, redundante con K8s probes)
        # healthcheck:
        #   test: ["CMD-SHELL", "curl -f http://localhost:8000/health/live || exit 1"]
        #   interval: 10s
        #   timeout: 3s
        #   retries: 3
        #   start_period: 30s
```

## Service Configuration

El Service de Kubernetes automáticamente usa los readiness probes para determinar qué pods pueden recibir tráfico:

```yaml
apiVersion: v1
kind: Service
metadata:
  name: apygg-api
spec:
  type: LoadBalancer  # o ClusterIP, NodePort según tu necesidad
  selector:
    app: apygg-api
  ports:
  - port: 80
    targetPort: 8000
    protocol: TCP
    name: http
```

## Valores Recomendados por Escenario

### Desarrollo / Staging

```yaml
livenessProbe:
  initialDelaySeconds: 15
  periodSeconds: 10
  timeoutSeconds: 5
  failureThreshold: 3

readinessProbe:
  initialDelaySeconds: 3
  periodSeconds: 5
  timeoutSeconds: 3
  failureThreshold: 2
```

### Producción

```yaml
livenessProbe:
  initialDelaySeconds: 30
  periodSeconds: 10
  timeoutSeconds: 3
  failureThreshold: 3

readinessProbe:
  initialDelaySeconds: 5
  periodSeconds: 5
  timeoutSeconds: 2
  failureThreshold: 3
```

### Alta Carga / Performance Crítico

```yaml
livenessProbe:
  initialDelaySeconds: 60
  periodSeconds: 15
  timeoutSeconds: 3
  failureThreshold: 3

readinessProbe:
  initialDelaySeconds: 10
  periodSeconds: 10
  timeoutSeconds: 2
  failureThreshold: 2
```

## Troubleshooting

### Problema: Pods reiniciándose constantemente

**Causa posible:** Liveness probe está verificando servicios externos que fallan intermitentemente.

**Solución:** 
- Usar `/health/live` para liveness (solo verifica que la app responde)
- Usar `/health/ready` para readiness (verifica servicios críticos)
- Aumentar `failureThreshold` si es necesario

### Problema: Pods marcados como "NotReady" pero funcionan

**Causa posible:** Readiness probe con timeout muy corto o servicios lentos.

**Solución:**
- Aumentar `timeoutSeconds` en readiness probe
- Verificar latencia de DB/Redis
- Revisar logs del pod: `kubectl logs <pod-name>`

### Problema: Pods no reciben tráfico después del despliegue

**Causa posible:** Readiness probe fallando durante el inicio.

**Solución:**
- Agregar `startupProbe` con `failureThreshold` alto
- Aumentar `initialDelaySeconds` en readiness probe
- Verificar que los servicios externos (DB, Redis) estén disponibles

### Comandos Útiles

```bash
# Ver estado de los pods
kubectl get pods -l app=apygg-api

# Ver eventos del pod
kubectl describe pod <pod-name>

# Ver logs del pod
kubectl logs <pod-name>

# Verificar health check manualmente desde dentro del pod
kubectl exec <pod-name> -- curl http://localhost:8000/health/ready

# Ver métricas de probes
kubectl get --raw "/api/namespaces/default/pods/<pod-name>/proxy/metrics"
```

## Consideraciones Importantes

1. **Timeouts:** Los timeouts deben ser menores que `periodSeconds` para evitar solapamiento de requests.

2. **Failure Threshold:** 
   - Liveness: 3 fallos = reinicio (conservador)
   - Readiness: 3 fallos = quitar del Service (conservador)
   - Ajustar según tolerancia a fallos

3. **Initial Delay:** 
   - Liveness: Dar tiempo suficiente para que la app inicie (30-60s)
   - Readiness: Puede ser más corto (5-10s) si la app inicia rápido

4. **Period Seconds:**
   - Más frecuente = más preciso pero más carga
   - Menos frecuente = menos carga pero detección más lenta
   - Recomendado: 5-10 segundos

5. **No exponer información sensible:** Los health checks son públicos (sin autenticación), no deben exponer detalles internos.

6. **Rate Limiting:** Los health checks están excluidos del rate limiting para evitar bloqueos.

## Referencias

- [Kubernetes Liveness, Readiness and Startup Probes](https://kubernetes.io/docs/tasks/configure-pod-container/configure-liveness-readiness-startup-probes/)
- [Health Check Endpoints Documentation](./api-only-considerations.md)
- [Docker Health Checks](../docker-compose.yml)
