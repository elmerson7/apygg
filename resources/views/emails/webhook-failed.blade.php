<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Webhook falló</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background:#f4f4f5; margin:0; padding:0; }
        .container { max-width:560px; margin:40px auto; background:#fff; border-radius:8px; overflow:hidden; box-shadow:0 2px 8px rgba(0,0,0,.08); }
        .header { background:#dc2626; padding:32px 40px; }
        .header h1 { color:#fff; margin:0; font-size:22px; }
        .body { padding:32px 40px; color:#374151; line-height:1.6; }
        .details { background:#f3f4f6; padding:12px 16px; border-radius:6px; margin:16px 0; }
        .details p { margin:8px 0; }
        .error { background:#fef2f2; border-left:4px solid #dc2626; padding:12px 16px; color:#991b1b; }
        .footer { padding:16px 40px; background:#f9fafb; color:#9ca3af; font-size:12px; }
    </style>
</head>
<body>
<div class="container">
    <div class="header">
        <h1>⚠️ Alerta de Webhook</h1>
    </div>
    <div class="body">
        <p>Se ha producido un fallo en la entrega de un webhook.</p>
        
        <div class="details">
            <p><strong>Webhook:</strong> {{ $webhookName }}</p>
            <p><strong>Evento:</strong> {{ $event }}</p>
            <p><strong>Endpoint:</strong> {{ $endpoint }}</p>
            <p><strong>Intentos:</strong> {{ $attempts }}</p>
            <p><strong>Fecha:</strong> {{ $failedAt }}</p>
        </div>
        
        <div class="error">
            <strong>Error:</strong> {{ $error }}
        </div>
        
        <p>Por favor revisa la configuración del webhook en el panel de administración.</p>
    </div>
    <div class="footer">
        &copy; {{ date('Y') }} {{ config('app.name') }}. Todos los derechos reservados.
    </div>
</div>
</body>
</html>