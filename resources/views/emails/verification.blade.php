<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verifica tu correo electrónico</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background:#f4f4f5; margin:0; padding:0; }
        .container { max-width:560px; margin:40px auto; background:#fff; border-radius:8px; overflow:hidden; box-shadow:0 2px 8px rgba(0,0,0,.08); }
        .header { background:#4f46e5; padding:32px 40px; }
        .header h1 { color:#fff; margin:0; font-size:22px; }
        .body { padding:32px 40px; color:#374151; line-height:1.6; }
        .btn { display:inline-block; margin-top:24px; padding:12px 28px; background:#4f46e5; color:#fff; text-decoration:none; border-radius:6px; font-weight:600; }
        .code { background:#f3f4f6; padding:12px 16px; border-radius:6px; font-family:monospace; font-size:14px; color:#1f2937; margin:16px 0; }
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
        <p>Gracias por registrarte. Por favor verifica tu correo electrónico haciendo clic en el botón de abajo:</p>
        <a href="{{ $verificationUrl }}" class="btn">Verificar correo</a>
        <p style="margin-top:24px; color:#6b7280; font-size:14px;">Este enlace expires el: {{ $expiresAt }}</p>
        <p style="color:#9ca3af; font-size:12px;">Si no solicitaste este correo, puedes ignorarlo.</p>
    </div>
    <div class="footer">
        &copy; {{ date('Y') }} {{ config('app.name') }}. Todos los derechos reservados.
    </div>
</div>
</body>
</html>