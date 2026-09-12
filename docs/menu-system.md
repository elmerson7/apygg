# Menú dinámico

El menú del panel se configura en la base de datos, no en el código del cliente.

El motivo es concreto: cuando el menú vive en el front, cada cambio de permisos
obliga a tocar y desplegar dos proyectos, y tarde o temprano el menú enseña
opciones que el backend rechaza con un 403.

> **El menú es presentación, no autorización.** Ocultar un ítem no protege nada.
> Cada ruta sigue exigiendo su permiso: quien conozca la URL puede llamarla.

## Cómo se decide la visibilidad

Dos niveles, en este orden:

1. **Por permiso** (`menu_items.permission`) — recomendado. El ítem se ve si el
   usuario tiene ese permiso. Así el menú queda derivado de lo que el usuario
   realmente puede hacer y no hay dos listas que mantener sincronizadas.
2. **Por rol** (tabla `menu_item_role`) — para ítems que no se corresponden con
   un permiso concreto (un panel de inicio, un enlace externo).

Un ítem **sin permiso y sin roles asignados no lo ve nadie**. Es el default
seguro: si alguien crea un ítem y se olvida de configurarlo, no se filtra.

## Endpoints

```http
GET /user/menu                      # el menú del usuario autenticado (árbol + claves)
```

Gestión, con permiso `menu.manage`:

```http
GET    /admin/menu/items            # catálogo + roles disponibles
POST   /admin/menu/items            # crear ítem
PUT    /admin/menu/items/{id}       # editar
DELETE /admin/menu/items/{id}       # eliminar (falla si tiene hijos)
PUT    /admin/menu/items/visibility # asignar roles a varios ítems a la vez
```

`GET /user/menu` devuelve las dos formas:

```json
{
  "items": [
    {
      "key": "configuracion",
      "label": "Configuración",
      "route": null,
      "icon": "settings",
      "children": [
        { "key": "configuracion.correo", "label": "Correo", "route": "/admin/mail", "icon": "mail", "children": [] }
      ]
    }
  ],
  "keys": ["configuracion", "configuracion.correo"]
}
```

`items` es el árbol listo para renderizar; `keys` es la lista plana, cómoda para
comprobaciones puntuales en el cliente.

## Jerarquía

`parent_key` apunta a la `key` del padre. Un hijo cuyo padre **no** es visible se
descarta del árbol: mostrarlo suelto produciría un menú incoherente (aparece
"Correo" sin el "Configuración" que lo contiene).

Ojo: ese hijo sigue apareciendo en `keys`, porque el usuario sí tiene permiso
sobre él. Si tu cliente usa `keys` para decidir qué renderizar, filtra por el
árbol.

## Puesta en marcha

```bash
make art cmd="migrate"
make art cmd="db:seed --class=MenuSeeder"
```

El seeder registra los ítems de los módulos que trae el boilerplate (usuarios,
roles, permisos, correo, IA, menú, auditoría), cada uno atado a su permiso.

## Añadir un ítem

```json
POST /admin/menu/items
{
  "key": "informes",
  "label": "Informes",
  "route": "/reports",
  "icon": "bar-chart",
  "permission": "reports.read",
  "sort_order": 40
}
```

O en `MenuSeeder`, para que forme parte del despliegue.

## Notas

- `key` es el identificador estable: el cliente puede mapearlo a un componente o
  a un icono propio. Las rutas y etiquetas pueden cambiar; la clave no debería.
- `sort_order` gobierna el orden; deja huecos (10, 20, 30) para poder intercalar
  sin renumerar.
- `permission` se valida contra la tabla `permissions`: no se puede atar un ítem
  a un permiso que no existe.
