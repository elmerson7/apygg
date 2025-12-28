# Gestión de Permisos con Docker

## Problema

Cuando Docker crea archivos dentro de volúmenes montados, estos archivos pueden tener permisos diferentes a los del usuario del host, causando problemas al:
- Editar archivos desde el IDE (VS Code/Cursor)
- Usar Git desde el IDE
- Ejecutar comandos desde el host

## Solución Implementada

El proyecto está configurado para que el contenedor Docker use el mismo **UID/GID** del usuario del host, evitando conflictos de permisos.

### Cómo Funciona

1. **Dockerfile** crea un usuario `appuser` con el mismo UID/GID del host
2. **docker-compose.yml** pasa el UID/GID del host como build args
3. El contenedor ejecuta comandos como `appuser` (no `root` ni `www-data`)
4. Los archivos creados tienen los mismos permisos que el usuario del host

### Configuración Automática

El Makefile detecta automáticamente tu UID/GID:

```bash
# El Makefile detecta automáticamente:
USER_ID=$(shell id -u)
GROUP_ID=$(shell id -g)
```

### Uso Normal

Simplemente usa los comandos normales del Makefile:

```bash
# Construir con permisos correctos
make build

# Levantar servicios
make up

# Los archivos creados tendrán permisos correctos automáticamente
```

### Si Necesitas Corregir Permisos Manualmente

Si por alguna razón los permisos se desincronizan:

```bash
# Opción 1: Usar el comando del Makefile
make fix-permissions

# Opción 2: Usar el script directamente
./docker/app/fix-permissions.sh

# Opción 3: Comando manual
sudo chown -R $(id -u):$(id -g) .
```

### Al Clonar el Repositorio

Cuando alguien clone el repositorio:

1. **Primera vez**: Construir la imagen con sus propios UID/GID
   ```bash
   make build
   ```

2. **Si hay problemas de permisos**: Corregir con el comando del Makefile
   ```bash
   make fix-permissions
   ```

### Variables de Entorno Opcionales

Si necesitas especificar UID/GID manualmente (raro):

```bash
# En env/dev.env (descomentar y ajustar)
USER_ID=1000
GROUP_ID=1000

# O al construir
USER_ID=1000 GROUP_ID=1000 make build
```

## Verificación

Para verificar que todo está correcto:

```bash
# Ver UID/GID del usuario actual
id -u
id -g

# Ver permisos de un archivo creado por Docker
ls -la composer.lock

# Debería mostrar tu usuario, no root ni www-data
```

## Archivos Modificados

- `docker/app/Dockerfile`: Crea usuario con UID/GID del host
- `docker-compose.yml`: Pasa UID/GID como build args
- `docker/app/entrypoint.sh`: Ajusta permisos de storage/cache al iniciar
- `Makefile`: Detecta y pasa UID/GID automáticamente
- `docker/app/fix-permissions.sh`: Script helper para corregir permisos

## Notas Importantes

- ✅ Los archivos creados por Docker son editables desde el IDE
- ✅ Git funciona correctamente desde el IDE
- ✅ No necesitas usar `sudo` para editar archivos
- ✅ Al clonar el repo, solo necesitas `make build` y `make fix-permissions` si es necesario

