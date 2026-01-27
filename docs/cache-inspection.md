# Inspección del Cache

## Descripción

Comando para listar y ver el estado del cache de Redis. Permite ver qué datos están cacheados, su TTL, tamaño y valores.

## Comando

```bash
php artisan cache:list [opciones]
```

## Opciones

### Filtrado

- `--pattern=*` - Filtrar por patrón (ej: `user:*`, `permission:*`)
- `--tag=TAG` - Filtrar por tag específico
- `--limit=N` - Límite de keys a mostrar (default: 50)

### Información

- `--ttl` - Mostrar TTL (tiempo de vida) de cada key
- `--size` - Mostrar tamaño de cada key
- `--value` - Mostrar valores de las keys
- `--stats` - Mostrar solo estadísticas generales

## Ejemplos

### Listar todas las keys (primeras 50)

```bash
php artisan cache:list
```

### Listar keys de usuarios con TTL

```bash
php artisan cache:list --pattern=user --ttl
```

### Ver valores de configuraciones

```bash
php artisan cache:list --pattern=config --value
```

### Ver estadísticas generales

```bash
php artisan cache:list --stats
```

### Listar con toda la información

```bash
php artisan cache:list --pattern=user --ttl --size --value --limit=20
```

## Interpretación de TTL

- `∞ (sin expiración)` - La key no expira nunca
- `1h`, `30m`, `45s` - Tiempo restante hasta expiración
- `0s (expirado)` - La key ya expiró pero aún existe
- `❌ (no existe)` - La key no existe

## Tipos de Keys

El comando agrupa las keys por tipo:

- `user` - Cache de usuarios
- `permission` - Cache de permisos
- `role` - Cache de roles
- `config` - Configuraciones del sistema
- `webhook` - Cache de webhooks
- `search` - Cache de búsquedas
- `other` - Otros tipos

## Performance

- El comando usa `KEYS` que puede ser lento con muchas keys
- Para producción con muchas keys, usar `--limit` para limitar resultados
- Considerar usar `--pattern` para filtrar antes de buscar

## Troubleshooting

### No se encuentran keys
1. Verificar que Redis esté disponible: `php artisan redis:test`
2. Verificar que haya datos en cache: ejecutar `php artisan cache:warm`
3. Verificar la base de datos de Redis: debe ser la DB 1 para cache

### TTL muestra "∞"
- Las keys con tags de Laravel pueden tener TTL en el tag, no en la key individual
- Esto es normal para cache con tags

### Valores muy largos
- Usar `--limit` para reducir la cantidad de keys mostradas
- Los valores se truncan a 100 caracteres cuando se muestran
