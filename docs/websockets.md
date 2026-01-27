# WebSockets con Reverb

**⚠️ OPCIONAL**: Solo funciona si `BROADCAST_CONNECTION=reverb` en `.env`. Si no está configurado, la API funciona normalmente con REST.

---

## Habilitar

```env
BROADCAST_CONNECTION=reverb
REVERB_APP_ID=apygg
REVERB_APP_KEY=tu-key-segura
REVERB_APP_SECRET=tu-secret-seguro
REVERB_HOST=localhost
REVERB_PORT=8012
REVERB_SCHEME=http
```

```bash
php artisan reverb:start --host=0.0.0.0 --port=8080
```

**Deshabilitar**: `BROADCAST_CONNECTION=null`

---

## Backend - Crear Eventos

```php
use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;

class MiEventoBroadcast implements ShouldBroadcast
{
    public function broadcastOn(): array
    {
        return [new Channel('notifications')]; // o 'private-user.{userId}'
    }
    
    public function broadcastAs(): string
    {
        return 'mi.evento';
    }
}

// Disparar
broadcast(new MiEventoBroadcast($data));
```

**Canales**:
- `notifications` - Público (sin auth)
- `private-user.{userId}` - Privado (requiere JWT)
- `presence-online` - Presencia (requiere JWT)

**Eventos existentes**: `UserCreatedBroadcast`, `NotificationBroadcast`

---

## Frontend - Conectar

**Instalar**: `npm install laravel-echo pusher-js`

**Configurar**:
```javascript
import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

window.Pusher = Pusher;

const echo = new Echo({
    broadcaster: 'reverb',
    key: import.meta.env.VITE_REVERB_APP_KEY,
    wsHost: import.meta.env.VITE_REVERB_HOST,
    wsPort: import.meta.env.VITE_REVERB_PORT ?? 80,
    wssPort: 443,
    forceTLS: import.meta.env.VITE_REVERB_SCHEME === 'https',
    authEndpoint: `${API_URL}/broadcasting/auth`,
    auth: { headers: { Authorization: `Bearer ${token}` } },
});
```

**Escuchar**:
```javascript
// Canal público de pruebas
echo.channel('test-messages').listen('.test.message', (e) => {
    console.log('Mensaje:', e.message, 'Hora:', e.time);
});

// Público
echo.channel('notifications').listen('.user.created', (e) => {...});

// Privado
echo.private(`private-user.${userId}`).listen('.notification.new', (e) => {...});

// Presencia
echo.join('presence-online')
    .here((users) => {...})
    .joining((user) => {...})
    .leaving((user) => {...});
```

**Prueba rápida desde Tinker**:
```php
broadcast(new App\Events\Broadcasting\TestMessageBroadcast('Hola!'));
```

**Variables de entorno**:
```env
VITE_REVERB_APP_KEY=tu-key
VITE_REVERB_HOST=localhost
VITE_REVERB_PORT=8012
VITE_REVERB_SCHEME=http
```

---

## Autenticación

Endpoint: `POST /broadcasting/auth` (Laravel Echo lo usa automáticamente)

---

## Troubleshooting

- **No conecta**: `curl http://localhost:8012` (verificar Reverb corriendo)
- **401**: Token JWT inválido/expirado
- **Evento no llega**: Verificar `broadcastAs()` y canal
- **Logs**: `docker compose logs reverb`

---

**Recursos**: [Laravel Reverb](https://laravel.com/docs/reverb) | [Broadcasting](https://laravel.com/docs/broadcasting)
