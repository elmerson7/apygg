# JWT asimétrico (RS256/ES256), `kid` y rotación de claves

Por defecto la API firma con **HS256** (`JWT_ALGO=HS256`, `JWT_SECRET`) y nada de lo de este documento aplica.
Si necesitas que terceros verifiquen los tokens sin poder emitirlos, usa un algoritmo asimétrico.

## Activar RS256 / ES256

```bash
# RS256
openssl genrsa -out jwt-private.pem 4096 && openssl rsa -in jwt-private.pem -pubout -out jwt-public.pem
# ES256
openssl ecparam -name prime256v1 -genkey -noout -out jwt-private.pem && openssl ec -in jwt-private.pem -pubout -out jwt-public.pem
```

```dotenv
JWT_ALGO=RS256                       # o ES256 (HS384/512, RS384/512, ES384/512 también valen)
JWT_PRIVATE_KEY=file:///run/secrets/jwt-private.pem   # ruta file:// o el PEM completo
JWT_PUBLIC_KEY=file:///run/secrets/jwt-public.pem
JWT_PASSPHRASE=                      # si la clave privada la lleva
```

La clave privada vive solo en la API (secreto del despliegue, nunca en git). La pública se puede repartir.
Cambiar de algoritmo **invalida todos los tokens emitidos** (los usuarios vuelven a iniciar sesión).

## Cabecera `kid` y rotación

La librería `php-open-source-saver/jwt-auth` no emite `kid` ni acepta dos claves de verificación. Este repo lo cubre con un
proveedor opcional, `App\Support\Jwt\KeyRotatingLcobucci`, que **solo se activa si defines `JWT_KID`** (`config/jwt.php`):
sin `JWT_KID` se usa el proveedor de la librería, sin cambios.

```dotenv
JWT_KID=2026-11                       # identifica la clave con la que se firma (cabecera `kid`)
JWT_PREVIOUS_PUBLIC_KEY=file:///run/secrets/jwt-public-old.pem
JWT_PREVIOUS_PUBLIC_KEY_UNTIL=2026-11-30T00:00:00Z   # hasta cuándo se acepta la anterior
```

Procedimiento de rotación:

1. Genera el par nuevo. Despliega con `JWT_PRIVATE_KEY`/`JWT_PUBLIC_KEY` nuevos, `JWT_KID` nuevo y la pública vieja en
   `JWT_PREVIOUS_PUBLIC_KEY` con `..._UNTIL` ≥ `JWT_REFRESH_TTL` (si no, cerrarás sesiones antes de tiempo).
2. Los tokens nuevos salen con el `kid` nuevo; los firmados con la clave vieja siguen valiendo hasta esa fecha.
3. Pasada la fecha, quita las variables `JWT_PREVIOUS_*`.

Límites: el proveedor acepta **una** clave anterior a la vez (no hay JWKS publicado: los verificadores externos reciben la
pública por su canal). El `kid` es informativo para ellos; la API prueba la vigente y la anterior sin depender de él.
Pruebas: `tests/Feature/Auth/JwtAsimetricoTest.php` (pares generados en el test).
