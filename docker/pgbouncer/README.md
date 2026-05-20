# PgBouncer Configuration

## Descripción

PgBouncer es un connection pooler para PostgreSQL que reduce el número de conexiones directas a la base de datos, mejorando el rendimiento en entornos de alta carga.

## Configuración

- **Modo**: `transaction` (recomendado para Laravel)
- **Pool Size**: 25 conexiones al backend PostgreSQL
- **Max Client Connections**: 100 conexiones desde Laravel
- **Puerto**: 6432 (interno), mapeado por `PGBOUNCER_PORT` en `compose.env`

## Uso

### Desarrollo
En desarrollo se usa conexión directa a PostgreSQL (sin PgBouncer):
- `DB_HOST=postgres`
- `DB_PORT=5432`

### Producción
En producción se usa PgBouncer:
- `DB_HOST=pgbouncer`
- `DB_PORT=6432`

## Comandos Útiles

### Ver estado del pool
```bash
docker compose exec pgbouncer pgbouncer -c "SHOW POOLS"
```

### Ver estadísticas
```bash
docker compose exec pgbouncer pgbouncer -c "SHOW STATS"
```

### Ver clientes conectados
```bash
docker compose exec pgbouncer pgbouncer -c "SHOW CLIENTS"
```

### Ver servidores (conexiones al backend)
```bash
docker compose exec pgbouncer pgbouncer -c "SHOW SERVERS"
```

## Migraciones

**IMPORTANTE**: Para ejecutar migraciones en producción, conectarse directamente a PostgreSQL (no a PgBouncer):

```bash
# Opción 1: Cambiar DB_HOST temporalmente
DB_HOST=postgres php artisan migrate

# Opción 2: Ejecutar desde el contenedor PostgreSQL
docker compose exec postgres psql -U apygg -d apygg
```

## Troubleshooting

### PgBouncer no se conecta a PostgreSQL
- Verificar que PostgreSQL esté corriendo y saludable
- Verificar credenciales en variables de entorno
- Revisar logs: `docker compose logs pgbouncer`

### Errores de conexión desde Laravel
- Verificar que `DB_HOST=pgbouncer` en producción
- Verificar que PgBouncer esté saludable: `docker compose ps pgbouncer`
- Revisar logs de Laravel y PgBouncer

### Pool agotado
- Aumentar `default_pool_size` en `docker/pgbouncer/pgbouncer.ini`
- Revisar si hay conexiones colgadas: `SHOW CLIENTS`
- Verificar configuración de timeouts