<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Nueva API Key creada</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background:#f4f4f5; margin:0; padding:0; }
        .container { max-width:560px; margin:40px auto; background:#fff; border-radius:8px; overflow:hidden; box-shadow:0 2px 8px rgba(0,0,0,.08); }
        .header { background:#059669; padding:32px 40px; }
        .header h1 { color:#fff; margin:0; font-size:22px; }
        .body { padding:32px 40px; color:#374151; line-height:1.6; }
        .key { background:#f3f4f6; padding:12px 16px; border-radius:6px; font-family:monospace; font-size:14px; color:#1f2937; word-break:break-all; }
        .warning { background:#fef3c7; border-left:4px solid #f59e0b; padding:12px 16px; margin:16px 0; color:#92400e; }
        .footer { padding:16px 40px; background:#f9fafb; color:#9ca3af; font-size:12px; }
    </style>
</head>
<body>
<div class="container">
    <div class="header">
        <h1>{{ config('app.name') }}</h1>
    </div>
    <div class="body">
        <p>Hola <strong>{{ $name }}</strong>,</p>
        <p>Se ha creado una nueva API Key para tu cuenta.</p>
        <p><strong>Tu API Key:</strong></p>
        <div class="key">{{ $key }}</div>
        <div class="warning">
            <strong>Importante:</strong> Guarda esta clave ahora. No podrás verla nuevamente.
        </div>
        <p><strong>Detalles:</strong></p>
        <ul>
            <li>Creada: {{ $createdAt }}</li>
            @if($expiresAt)
            <li>Expira: {{ $expiresAt }}</li>
            @endif
        </ul>
    </div>
    <div class="footer">
        &copy; {{ date('Y') }} {{ config('app.name') }}. Todos los derechos reservados.
    </div>
</div>
</body>
</html>