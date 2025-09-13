# FormRequests + Resources - Guía de Implementación

## 📋 Resumen de Implementación

Se han implementado **FormRequests** y **Resources** para uniformidad de I/O en los endpoints principales de la API.

## 🏗️ Estructura Creada

```
app/Http/
├── Requests/
│   ├── BaseFormRequest.php          # Clase base con validación RFC 7807
│   ├── Auth/
│   │   ├── LoginRequest.php         # Validación de login
│   │   ├── RefreshRequest.php       # Validación de refresh token
│   │   └── LogoutRequest.php        # Validación de logout
│   └── Users/
│       └── UpdateUserRequest.php    # Validación de actualización de usuario
├── Resources/
│   ├── BaseResource.php             # Clase base con helpers comunes
│   ├── UserResource.php             # Formateo de datos de usuario
│   ├── UserCollection.php           # Colección de usuarios
│   └── Auth/
│       ├── AuthTokenResource.php    # Respuesta de login con tokens
│       └── RefreshTokenResource.php # Respuesta de refresh token
```

## ✅ Endpoints Actualizados

### Autenticación (`AuthController`)
- `POST /auth/login` - Usa `LoginRequest` + `AuthTokenResource`
- `POST /auth/refresh` - Usa `RefreshRequest` + `RefreshTokenResource`  
- `POST /auth/logout` - Usa `LogoutRequest`

### Usuarios (`UserController`, `ProfileController`)
- `GET /users/me` - Usa `UserResource`
- `GET /users/{user}` - Usa `UserResource`
- `PATCH /users/{user}` - Usa `UpdateUserRequest` + `UserResource`
- `GET /profiles/me` - Usa `UserResource`

## 🎯 Beneficios Implementados

### 1. **Validación Consistente**
```php
// Antes
$data = $request->validate([
    'email' => ['required','email'],
    'password' => ['required','string'],
]);

// Ahora
$data = $request->validated(); // En LoginRequest
```

### 2. **Respuestas Uniformes**
```php
// Antes
return response()->json([
    'id' => $user->id,
    'name' => $user->name,
    // ...
]);

// Ahora
return UserResource::make($user);
```

### 3. **Errores RFC 7807**
```json
{
  "type": "https://damblix.dev/errors/ValidationException",
  "title": "Validation failed",
  "status": 422,
  "detail": "The given data was invalid.",
  "instance": "/auth/login",
  "errors": {
    "email": ["El correo electrónico es obligatorio."]
  }
}
```

## 🚀 Cómo Usar

### Crear nuevo FormRequest
```bash
php artisan make:request MyFormRequest
```

Extender de `BaseFormRequest`:
```php
class MyFormRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'field' => ['required', 'string'],
        ];
    }
}
```

### Crear nuevo Resource
```bash
php artisan make:resource MyResource
```

Extender de `BaseResource`:
```php
class MyResource extends BaseResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'field' => $this->field,
        ];
    }
}
```

### Usar en Controlador
```php
public function store(MyFormRequest $request)
{
    $model = Model::create($request->validated());
    
    return MyResource::make($model)
        ->response()
        ->setStatusCode(201);
}
```

## 🔧 Helpers Disponibles

### BaseFormRequest
- Validación automática con RFC 7807
- Mensajes en español
- Normalización de datos

### BaseResource
- `formatDate()` - Formateo ISO 8601
- `whenOwner()` - Datos solo para propietario
- `whenAuthenticated()` - Datos solo para autenticados
- Headers automáticos (Vary, Content-Type)

### UserResource
- Campos públicos siempre visibles
- Campos privados solo para propietario
- Links HATEOAS incluidos

## 📈 Próximos Pasos

1. **Migrar endpoints restantes** gradualmente
2. **Crear Resources específicos** para otros modelos (Match, Message, etc.)
3. **Implementar paginación** con ResourceCollection
4. **Añadir versionado** cuando sea necesario

## 🎨 Patrones Recomendados

### Para APIs públicas
- Siempre usar FormRequest + Resource
- Incluir links HATEOAS
- Validación exhaustiva

### Para APIs internas simples
- FormRequest solo si >3 reglas de validación
- Resource solo si hay transformación real
- Mantener simplicidad

### Para datos sensibles
- Usar `whenOwner()` y `whenAuthenticated()`
- Never exponer campos como `password`, `remember_token`
- Logs de acceso a datos sensibles

## 🐛 Debugging

### Ver validaciones
```php
// En FormRequest
dd($this->validated());
```

### Ver transformaciones
```php
// En Resource
dd($this->toArray($request));
```

### Logs automáticos
Los errores de validación se loguean automáticamente con contexto completo.
