<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Restablecer contraseña</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background:#f4f4f5; margin:0; padding:0; }
        .container { max-width:560px; margin:40px auto; background:#fff; border-radius:8px; overflow:hidden; box-shadow:0 2px 8px rgba(0,0,0,.08); }
        .header { background:#dc2626; padding:32px 40px; }
        .header h1 { color:#fff; margin:0; font-size:22px; }
        .body { padding:32px 40px; color:#374151; line-height:1.6; }
        .btn { display:inline-block; margin-top:24px; padding:12px 28px; background:#dc2626; color:#fff; text-decoration:none; border-radius:6px; font-weight:600; }
        .warning { margin-top:20px; padding:12px 16px; background:#fef3c7; border-left:4px solid #f59e0b; border-radius:4px; font-size:14px; color:#92400e; }
        .footer { padding:16px 40px; background:#f9fafb; color:#9ca3af; font-size:12px; }
    </style>
</head>
<body>
<div class="container">
    <div class="header">
        <h1>{{ $app_name }}</h1>
    </div>
    <div class="body">
        <p>Hola <strong>{{ $name }}</strong>,</p>
        <p>Recibimos una solicitud para restablecer la contraseña de tu cuenta. Haz clic en el botón para continuar:</p>
        <a href="{{ $reset_url }}" class="btn">Restablecer contraseña</a>
        <div class="warning">
            Este enlace expira en <strong>{{ $expires_minutes }} minutos</strong>. Si no solicitaste este cambio, ignora este correo.
        </div>
    </div>
    <div class="footer">
        &copy; {{ date('Y') }} {{ $app_name }}. Todos los derechos reservados.
    </div>
</div>
</body>
</html>
