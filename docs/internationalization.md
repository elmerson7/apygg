# Internacionalización (i18n) y Manejo de Timezones - Guía de Uso

## Descripción

Sistema de internacionalización y manejo de timezones preparado para expansión. Por defecto, la aplicación está configurada en español (`es`) y UTC como timezone, pero la estructura está lista para agregar más idiomas y manejar preferencias de timezone por usuario cuando sea necesario.

## Configuración Actual

### Idioma por Defecto

- **Idioma principal**: Español (`es`)
- **Idioma de respaldo**: Español (`es`)
- **Locale de Faker**: Español España (`es_ES`)

### Archivos de Traducción

Las traducciones se almacenan en archivos JSON en `resources/lang/`:

- `resources/lang/es.json` - Traducciones en español

## Estructura de Traducciones

### Formato JSON

Laravel soporta archivos JSON para traducciones simples. El formato es:

```json
{
    "clave": "Traducción",
    "clave_anidada": {
        "subclave": "Traducción anidada"
    }
}
```

### Uso en el Código

#### Traducciones Simples

```php
// Usando helper __()
__('messages.success.created')

// Usando helper trans()
trans('messages.success.created')

// Con parámetros
__('validation.min.string', ['attribute' => 'nombre', 'min' => 5])
```

#### Traducciones de Validación

Laravel automáticamente usa las traducciones de validación cuando usas reglas de validación:

```php
// En FormRequest
public function rules(): array
{
    return [
        'name' => ['required', 'string', 'max:255'],
        'email' => ['required', 'email', 'unique:users'],
    ];
}

// Laravel automáticamente usa las traducciones de:
// - validation.required
// - validation.string
// - validation.max.string
// - validation.email
// - validation.unique
```

#### Atributos Personalizados

Los nombres de campos se traducen automáticamente usando `attributes`:

```php
// En FormRequest
public function attributes(): array
{
    return [
        'email' => 'correo electrónico',
        'password' => 'contraseña',
    ];
}
```

O usando el archivo JSON:

```json
{
    "attributes": {
        "email": "correo electrónico",
        "password": "contraseña"
    }
}
```

## Agregar un Nuevo Idioma

### Paso 1: Crear Archivo de Traducción

Crea un nuevo archivo JSON en `resources/lang/`:

```bash
# Ejemplo: Agregar inglés
cp resources/lang/es.json resources/lang/en.json
```

### Paso 2: Traducir el Contenido

Edita el archivo `resources/lang/en.json` y traduce todas las cadenas:

```json
{
    "validation": {
        "required": "The :attribute field is required.",
        "email": "The :attribute must be a valid email address.",
        ...
    },
    "messages": {
        "success": {
            "created": "Resource created successfully.",
            ...
        }
    }
}
```

### Paso 3: Configurar el Idioma

#### Opción A: Variable de Entorno

En `.env`:

```env
APP_LOCALE=en
APP_FALLBACK_LOCALE=es
```

#### Opción B: Cambiar en Código

```php
// Cambiar idioma dinámicamente
app()->setLocale('en');

// O en un middleware
public function handle($request, Closure $next)
{
    $locale = $request->header('Accept-Language', 'es');
    app()->setLocale($locale);
    return $next($request);
}
```

### Paso 4: Actualizar Configuración

En `config/app.php`, asegúrate de que el nuevo idioma esté disponible:

```php
'available_locales' => ['es', 'en', 'fr'], // Agregar nuevos idiomas aquí
```

## Estructura de Traducciones Actual

### Categorías Disponibles

1. **validation** - Mensajes de validación de formularios
2. **attributes** - Nombres de campos/atributos
3. **auth** - Mensajes de autenticación
4. **messages** - Mensajes generales de la aplicación
   - **success** - Mensajes de éxito
   - **error** - Mensajes de error
   - **user** - Mensajes relacionados con usuarios
   - **file** - Mensajes relacionados con archivos
   - **api_key** - Mensajes relacionados con API Keys

## Mejores Prácticas

### 1. Usar Claves Descriptivas

```json
// ✅ Bueno
{
    "messages": {
        "user": {
            "created": "Usuario creado exitosamente."
        }
    }
}

// ❌ Evitar
{
    "msg1": "Usuario creado exitosamente."
}
```

### 2. Agrupar por Contexto

Agrupa traducciones relacionadas:

```json
{
    "messages": {
        "user": {
            "created": "...",
            "updated": "...",
            "deleted": "..."
        }
    }
}
```

### 3. Usar Parámetros Dinámicos

```json
{
    "validation": {
        "min": {
            "string": "El campo :attribute debe tener al menos :min caracteres."
        }
    }
}
```

### 4. Mantener Consistencia

- Usa el mismo formato en todos los idiomas
- Mantén las mismas claves en todos los archivos de idioma
- Documenta nuevas traducciones

## Migración desde Mensajes Hardcodeados

Si tienes mensajes hardcodeados en FormRequests, puedes migrarlos:

### Antes (Hardcodeado)

```php
protected function getCustomMessages(): array
{
    return [
        'name.required' => 'El nombre es requerido',
        'email.required' => 'El email es requerido',
    ];
}
```

### Después (Usando Traducciones)

```php
protected function getCustomMessages(): array
{
    return [
        'name.required' => __('validation.required', ['attribute' => __('attributes.name')]),
        'email.required' => __('validation.required', ['attribute' => __('attributes.email')]),
    ];
}
```

O mejor aún, usar las traducciones automáticas de Laravel:

```php
// Laravel automáticamente usa validation.required y attributes.name
// Solo necesitas definir las reglas
public function rules(): array
{
    return [
        'name' => ['required', 'string'],
        'email' => ['required', 'email'],
    ];
}
```

## Detección Automática de Idioma

Para implementar detección automática basada en headers HTTP:

### Crear Middleware

```php
// app/Http/Middleware/SetLocale.php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class SetLocale
{
    public function handle(Request $request, Closure $next)
    {
        $locale = $request->header('Accept-Language', config('app.locale'));
        
        // Validar que el idioma esté disponible
        $availableLocales = ['es', 'en'];
        if (in_array($locale, $availableLocales)) {
            app()->setLocale($locale);
        }
        
        return $next($request);
    }
}
```

### Registrar Middleware

En `bootstrap/app.php`:

```php
->withMiddleware(function (Middleware $middleware) {
    $middleware->append(\App\Http\Middleware\SetLocale::class);
})
```

## Manejo de Timezones

### Configuración Actual

- **Timezone por defecto**: UTC (configurado en `config/app.php`)
- **Timezone por usuario**: Campo `timezone` en tabla `users` (nullable, default: 'UTC')
- **DateHelper**: Helper completo con métodos de formateo y conversión de timezones

### Uso del DateHelper

El `DateHelper` proporciona métodos para trabajar con fechas y timezones:

```php
use App\Helpers\DateHelper;

// Formatear fecha con timezone específico
DateHelper::format($date, 'Y-m-d H:i:s', 'America/Mexico_City');

// Convertir entre timezones
$dateInTimezone = DateHelper::convertTimezone($date, 'Europe/Madrid');

// Formatear en español con timezone
DateHelper::toSpanish($date, true, 'America/Mexico_City');

// Obtener fecha actual en timezone específico
$now = DateHelper::now('America/Mexico_City');
```

### Preferencia de Timezone por Usuario

Cada usuario puede tener su propio timezone almacenado en el campo `timezone` de la tabla `users`.

#### Obtener Timezone del Usuario

```php
// En el modelo User
$user = User::find($id);
$timezone = $user->getTimezone(); // Retorna timezone del usuario o UTC por defecto

// Usar en formateo de fechas
DateHelper::format($date, 'Y-m-d H:i:s', $user->getTimezone());
```

#### Establecer Timezone del Usuario

```php
$user = User::find($id);
$user->timezone = 'America/Mexico_City';
$user->save();
```

### Timezones Disponibles

Laravel usa la lista de timezones de PHP. Algunos ejemplos comunes:

- `UTC` - Tiempo Universal Coordinado
- `America/Mexico_City` - Ciudad de México
- `America/New_York` - Nueva York (EST/EDT)
- `America/Los_Angeles` - Los Ángeles (PST/PDT)
- `Europe/Madrid` - Madrid
- `Europe/London` - Londres
- `Asia/Tokyo` - Tokio

Ver lista completa: [Lista de Timezones de PHP](https://www.php.net/manual/es/timezones.php)

### Implementación Futura: Middleware para Timezone Automático

Para aplicar automáticamente el timezone del usuario autenticado:

```php
// app/Http/Middleware/SetUserTimezone.php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class SetUserTimezone
{
    public function handle(Request $request, Closure $next)
    {
        if ($request->user()) {
            $timezone = $request->user()->getTimezone();
            config(['app.timezone' => $timezone]);
        }
        
        return $next($request);
    }
}
```

Registrar en `bootstrap/app.php`:

```php
->withMiddleware(function (Middleware $middleware) {
    $middleware->append(\App\Http\Middleware\SetUserTimezone::class);
})
```

### Mejores Prácticas

1. **Almacenar en UTC**: Siempre almacena fechas en UTC en la base de datos
2. **Convertir al mostrar**: Convierte al timezone del usuario solo al mostrar
3. **Usar DateHelper**: Usa `DateHelper` para todas las operaciones de fecha/timezone
4. **Validar timezones**: Valida que el timezone proporcionado sea válido antes de guardarlo

```php
// Validar timezone
$timezones = timezone_identifiers_list();
if (!in_array($timezone, $timezones)) {
    throw new \InvalidArgumentException("Timezone inválido: {$timezone}");
}
```

## Referencias

- [Laravel Localization Documentation](https://laravel.com/docs/localization)
- [Laravel JSON Translations](https://laravel.com/docs/localization#using-translation-strings-as-keys)
- [PHP Timezones](https://www.php.net/manual/es/timezones.php)
- [Carbon Documentation](https://carbon.nesbot.com/docs/)
