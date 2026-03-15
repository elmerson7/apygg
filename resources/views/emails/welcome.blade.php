<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bienvenido a {{ $app_name }}</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background:#f4f4f5; margin:0; padding:0; }
        .container { max-width:560px; margin:40px auto; background:#fff; border-radius:8px; overflow:hidden; box-shadow:0 2px 8px rgba(0,0,0,.08); }
        .header { background:#4f46e5; padding:32px 40px; }
        .header h1 { color:#fff; margin:0; font-size:22px; }
        .body { padding:32px 40px; color:#374151; line-height:1.6; }
        .btn { display:inline-block; margin-top:24px; padding:12px 28px; background:#4f46e5; color:#fff; text-decoration:none; border-radius:6px; font-weight:600; }
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
        <p>¡Tu cuenta ha sido creada exitosamente! Ya puedes acceder a la plataforma.</p>
        <a href="{{ $app_url }}" class="btn">Ir a la plataforma</a>
    </div>
    <div class="footer">
        &copy; {{ date('Y') }} {{ $app_name }}. Todos los derechos reservados.
    </div>
</div>
</body>
</html>
