#!/bin/bash

# Script de Blue-Green Deployment para APYGG
# Este script implementa despliegue Blue-Green con zero-downtime

set -e

ENVIRONMENT=${1:-staging}
IMAGE_TAG=${2:-latest}
BLUE_SERVICE="apygg-blue"
GREEN_SERVICE="apygg-green"
HEALTH_CHECK_URL=${HEALTH_CHECK_URL:-"http://localhost:8010/health/ready"}
MAX_HEALTH_CHECK_ATTEMPTS=30
HEALTH_CHECK_INTERVAL=5

echo "🚀 Iniciando Blue-Green Deployment"
echo "   Environment: $ENVIRONMENT"
echo "   Image Tag: $IMAGE_TAG"

# Determinar qué entorno está activo actualmente
determine_active_environment() {
    # Verificar qué servicio está recibiendo tráfico
    # Esto depende de tu configuración (load balancer, DNS, etc.)
    if curl -f "${HEALTH_CHECK_URL}" > /dev/null 2>&1; then
        # Verificar headers o metadata para determinar si es Blue o Green
        echo "blue"  # Por defecto, asumimos Blue está activo
    else
        echo "green"
    fi
}

# Desplegar a entorno inactivo
deploy_to_inactive() {
    local target_env=$1
    echo "📦 Desplegando a entorno $target_env..."
    
    # Aquí va tu lógica específica de despliegue
    # Ejemplos:
    
    # Para Docker Compose:
    # docker compose -f docker-compose.$target_env.yml up -d --build
    
    # Para Kubernetes:
    # kubectl set image deployment/apygg-$target_env app=$IMAGE_TAG
    # kubectl rollout status deployment/apygg-$target_env
    
    # Para Railway:
    # railway up --service $target_env --detach
    
    # Para AWS ECS:
    # aws ecs update-service --cluster apygg --service apygg-$target_env --force-new-deployment
    
    echo "✅ Despliegue a $target_env completado"
}

# Health check
health_check() {
    local service_url=$1
    local attempts=0
    
    echo "🏥 Verificando salud del servicio..."
    
    while [ $attempts -lt $MAX_HEALTH_CHECK_ATTEMPTS ]; do
        if curl -f "$service_url" > /dev/null 2>&1; then
            echo "✅ Servicio saludable"
            return 0
        fi
        
        attempts=$((attempts + 1))
        echo "   Intento $attempts/$MAX_HEALTH_CHECK_ATTEMPTS..."
        sleep $HEALTH_CHECK_INTERVAL
    done
    
    echo "❌ Health check falló después de $MAX_HEALTH_CHECK_ATTEMPTS intentos"
    return 1
}

# Cambiar tráfico al nuevo entorno
switch_traffic() {
    local target_env=$1
    echo "🔄 Cambiando tráfico a entorno $target_env..."
    
    # Aquí va tu lógica de cambio de tráfico
    # Ejemplos:
    
    # Para Load Balancer (AWS ALB/NLB):
    # aws elbv2 modify-listener --listener-arn $LISTENER_ARN --default-actions Type=forward,TargetGroupArn=$TARGET_GROUP_ARN
    
    # Para Nginx:
    # sed -i "s/apygg-blue/apygg-green/g" /etc/nginx/conf.d/apygg.conf
    # nginx -s reload
    
    # Para Kubernetes Service:
    # kubectl patch service apygg -p '{"spec":{"selector":{"version":"'$target_env'"}}}'
    
    # Para DNS (Cloudflare, Route53, etc.):
    # aws route53 change-resource-record-sets --hosted-zone-id $ZONE_ID --change-batch file://dns-change.json
    
    echo "✅ Tráfico cambiado a $target_env"
}

# Limpiar entorno antiguo (opcional, después de verificación)
cleanup_old_environment() {
    local old_env=$1
    echo "🧹 Limpiando entorno antiguo: $old_env"
    
    # Esperar un tiempo antes de limpiar (para rollback rápido si es necesario)
    echo "   Esperando 5 minutos antes de limpiar (para rollback rápido)..."
    sleep 300
    
    # Limpiar recursos del entorno antiguo
    # docker compose -f docker-compose.$old_env.yml down
    # kubectl delete deployment apygg-$old_env
    
    echo "✅ Limpieza completada"
}

# Función principal
main() {
    local active_env=$(determine_active_environment)
    local inactive_env=""
    
    if [ "$active_env" == "blue" ]; then
        inactive_env="green"
    else
        inactive_env="blue"
    fi
    
    echo "   Entorno activo: $active_env"
    echo "   Desplegando a: $inactive_env"
    
    # 1. Desplegar a entorno inactivo
    deploy_to_inactive "$inactive_env"
    
    # 2. Health check del nuevo entorno
    if ! health_check "$HEALTH_CHECK_URL"; then
        echo "❌ Health check falló - abortando despliegue"
        exit 1
    fi
    
    # 3. Cambiar tráfico
    switch_traffic "$inactive_env"
    
    # 4. Esperar estabilización
    echo "⏳ Esperando estabilización (60 segundos)..."
    sleep 60
    
    # 5. Verificar que todo funciona
    if ! health_check "$HEALTH_CHECK_URL"; then
        echo "❌ Verificación post-switch falló - iniciando rollback"
        switch_traffic "$active_env"  # Rollback automático
        exit 1
    fi
    
    # 6. Limpiar entorno antiguo (opcional)
    # cleanup_old_environment "$active_env"
    
    echo "✅ Blue-Green Deployment completado exitosamente"
    echo "   Nuevo entorno activo: $inactive_env"
}

main
