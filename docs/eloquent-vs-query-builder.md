# Eloquent vs Query Builder - Guía de Uso

## Cuándo usar cada uno

### ✅ Usar Eloquent (Modelos)

**Cuando existe un modelo para la tabla:**

```php
// ✅ CORRECTO
User::where('email', $email)->first();
Role::with('permissions')->get();
$user->roles()->attach($roleId);
Permission::create([...]);
```

**Ventajas:**
- Relaciones automáticas (`$user->roles`)
- Eventos del modelo (`creating`, `saving`, etc.)
- Casts automáticos (dates, JSON, etc.)
- Scopes reutilizables (`User::active()->get()`)
- Soft deletes integrado

### ✅ Usar Query Builder (DB::table)

**Cuando NO existe modelo o es consulta compleja:**

```php
// ✅ CORRECTO - Tabla sin modelo
DB::table('password_reset_tokens')
    ->where('email', $email)
    ->delete();

// ✅ CORRECTO - Validación genérica
DB::table($table)->where($column, $value)->exists();

// ✅ CORRECTO - Consulta compleja/reporte (sin modelo)
DB::table('users')
    ->join('user_role', 'users.id', '=', 'user_role.user_id')
    ->select('users.*', 'roles.name')
    ->get();
```

### ✅ Consultas complejas con modelos existentes

**Cuando existe modelo pero la consulta es compleja, usa `Model::query()`:**

```php
// ✅ MEJOR - Query Builder desde modelo (mantiene contexto)
User::query()
    ->join('user_role', 'users.id', '=', 'user_role.user_id')
    ->join('roles', 'user_role.role_id', '=', 'roles.id')
    ->select('users.*', 'roles.name as role_name')
    ->where('users.active', true)
    ->groupBy('users.id')
    ->get();

// ✅ ACEPTABLE - Query Builder directo (solo si es muy complejo)
DB::table('users')
    ->join('user_role', 'users.id', '=', 'user_role.user_id')
    ->join('roles', 'user_role.role_id', '=', 'roles.id')
    ->select('users.*', 'roles.name as role_name')
    ->where('users.active', true)
    ->groupBy('users.id')
    ->get();

// ❌ EVITAR - Eloquent con múltiples JOINs complejos
User::with(['roles'])->get(); // Si necesitas JOINs específicos, mejor Query Builder
```

## ❌ Anti-patrones (NO hacer)

### 1. Query Builder simple cuando existe modelo

```php
// ❌ MAL - Consulta simple con Query Builder
DB::table('users')->where('email', $email)->first();

// ✅ BIEN - Consulta simple con Eloquent
User::where('email', $email)->first();

// ✅ BIEN - Consulta compleja con Query Builder desde modelo
User::query()
    ->join('user_role', 'users.id', '=', 'user_role.user_id')
    ->select('users.*', 'roles.name')
    ->get();
```

### 2. Eloquent para tablas sin modelo

```php
// ❌ MAL - Crear modelo innecesario
class PasswordResetToken extends Model {}

// ✅ BIEN - Usar Query Builder
DB::table('password_reset_tokens')->insert([...]);
```

### 3. SQL crudo innecesario

```php
// ❌ MAL
DB::select("SELECT * FROM users WHERE email = ?", [$email]);

// ✅ BIEN
User::where('email', $email)->get();
```

### 4. Mezclar ambos sin razón

```php
// ❌ MAL
$users = DB::table('users')->get();
foreach ($users as $user) {
    $roles = DB::table('user_role')
        ->where('user_id', $user->id)
        ->get();
}

// ✅ BIEN
$users = User::with('roles')->get();
```

## Regla de oro

**Si existe modelo:**
- Consultas simples → **Eloquent** (`User::where()`)
- Consultas complejas → **Query Builder desde modelo** (`User::query()->join()`)
- Operaciones masivas → **Query Builder directo** (`DB::table()`)

**Si no existe modelo → Query Builder** (`DB::table()`)

## Ejemplos del proyecto

### ✅ Correcto - Eloquent (app/Modules/Auth/Services/AuthService.php)

```php
$user = User::where('email', $email)->first();
$user->roles()->attach($roleId);
```

### ✅ Correcto - Query Builder (app/Modules/Auth/Services/PasswordService.php)

```php
DB::table('password_reset_tokens')
    ->where('email', $user->email)
    ->delete();
```

### ✅ Correcto - Query Builder (app/Core/Rules/ExistsInDatabase.php)

```php
DB::table($this->table)
    ->where($this->column, $value)
    ->exists();
```

## Cuándo usar cada uno - Resumen

| Escenario | Método | Ejemplo |
|-----------|--------|---------|
| CRUD simple con modelo | Eloquent | `User::where()->first()` |
| Relaciones | Eloquent | `User::with('roles')->get()` |
| Consulta compleja con modelo | `Model::query()` | `User::query()->join()->get()` |
| Reporte/agregación compleja | `DB::table()` | `DB::table('users')->groupBy()->get()` |
| Tabla sin modelo | `DB::table()` | `DB::table('password_reset_tokens')` |
| Operación masiva | `DB::table()` | `DB::table('users')->update([...])` |

## Rendimiento

- **Eloquent**: Más lento pero más mantenible (usa para CRUD normal)
- **Query Builder**: Más rápido para consultas complejas/reportes
- **Model::query()**: Mejor de ambos mundos (mantiene contexto del modelo)

**Recomendación:** 
1. Eloquent por defecto
2. `Model::query()` para consultas complejas con modelo
3. `DB::table()` solo si no hay modelo o es muy complejo
