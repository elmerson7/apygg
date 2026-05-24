# Error Handling Conventions

## API Response Format

### Success (2xx)
```json
{
  "success": true,
  "message": "Usuario actualizado exitosamente",
  "data": { ... },
  "meta": {
    "version": "1.0",
    "timestamp": "2026-05-24T00:00:00+00:00",
    "request_id": "uuid"
  }
}
```

### Validation Error (422)
```json
{
  "success": false,
  "message": "The given data was invalid.",
  "type": "validation_error",
  "errors": {
    "password": [
      "El campo password debe contener una letra may\u00fascula, una letra min\u00fascula, un car\u00e1cter especial."
    ]
  },
  "meta": { ... }
}
```

### System Error (500)
```json
{
  "success": false,
  "message": "Error interno del servidor.",
  "type": "internal_server_error",
  "meta": { ... }
}
```

## Frontend Rules

| Status Code | Show to user |
|-------------|-------------|
| 422 | Mostrar **mensaje del API** (`errors.campo[0]` o `message`). Son textos en español escritos para el usuario final. |
| 401 | Mostrar mensaje genérico: *"Sesión expirada. Inicia sesión nuevamente."* |
| 403 | Mostrar mensaje genérico: *"No tienes permisos para esta acción."* |
| 500 | Mostrar mensaje genérico: *"Error interno del servidor. Intenta de nuevo."* |
| Otros (4xx/5xx) | Mostrar `message` del API si existe, o mensaje genérico. |
| Sin conexión | Mostrar: *"Error de conexión. Verifica tu internet."* |

## Pasos para manejar errores en el frontend

```javascript
catch (error) {
  const status = error?.response?.status
  const data = error?.response?.data

  if (status === 422 && data?.errors) {
    // Errores de validación: mostrar mensaje del API
    const firstError = Object.values(data.errors)[0]?.[0]
    toast.add({ severity: 'error', detail: firstError || data.message, life: 5000 })
  } else if (status === 500) {
    toast.add({ severity: 'error', detail: 'Error interno del servidor. Intenta de nuevo.', life: 5000 })
  } else if (status === 401) {
    toast.add({ severity: 'error', detail: 'Sesión expirada. Inicia sesión nuevamente.', life: 5000 })
  } else if (status === 403) {
    toast.add({ severity: 'error', detail: 'No tienes permisos para esta acción.', life: 5000 })
  } else if (error?.request && !error?.response) {
    toast.add({ severity: 'error', detail: 'Error de conexión. Verifica tu internet.', life: 5000 })
  } else {
    toast.add({ severity: 'error', detail: data?.message || 'Ocurrió un error inesperado.', life: 5000 })
  }
}
```

## Backend: Reglas de validación vs errores del sistema

- **ValidationException (422)**: Siempre mostrar los mensajes del API (`errors.*[0]`) — están escritos para el usuario.
- **SystemException (500+)**: **Nunca** mostrar el mensaje crudo de la excepción al usuario en producción. Solo en debug.
- El backend ya filma los mensajes sensibles en producción via `config('app.debug')`.

## Password Change Flow

1. Frontend envía `PUT /users/{id}` con `{ password }`
2. Backend valida con `StrongPassword` rule
3. Si falla → 422 con mensaje en `errors.password[0]`
4. Frontend muestra el mensaje del API en un toast
5. Si el usuario no ve el error de validación, el frontend **no debe** crear su propio mensaje — debe mostrar el del API

## StrongPassword Levels

```php
StrongPassword::basic()   // min 6 chars, no requirements
StrongPassword::medium()  // min 8 chars, uppercase + lowercase + number
StrongPassword::strong()  // min 8 chars, uppercase + lowercase + number + special
```
