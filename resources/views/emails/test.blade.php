<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Correo de prueba</title>
</head>
<body style="font-family: -apple-system, Segoe UI, Roboto, Helvetica, Arial, sans-serif; line-height: 1.6; color: #1f2937; margin: 0; padding: 24px; background: #f9fafb;">
    <div style="max-width: 560px; margin: 0 auto; background: #ffffff; border-radius: 8px; padding: 32px; border: 1px solid #e5e7eb;">
        <h1 style="margin: 0 0 16px; font-size: 20px;">Correo de prueba</h1>

        <p style="margin: 0 0 16px;">
            Si estás leyendo esto, la configuración de correo de
            <strong>{{ $app_name }}</strong> funciona correctamente.
        </p>

        <table style="width: 100%; border-collapse: collapse; font-size: 14px;">
            <tr>
                <td style="padding: 8px 0; color: #6b7280;">Cuenta</td>
                <td style="padding: 8px 0; text-align: right;">{{ $account_name }}</td>
            </tr>
            <tr>
                <td style="padding: 8px 0; color: #6b7280;">Driver</td>
                <td style="padding: 8px 0; text-align: right;">{{ $driver }}</td>
            </tr>
            <tr>
                <td style="padding: 8px 0; color: #6b7280;">Enviado</td>
                <td style="padding: 8px 0; text-align: right;">{{ $sent_at }}</td>
            </tr>
        </table>

        <p style="margin: 24px 0 0; font-size: 12px; color: #9ca3af;">
            Este mensaje se generó desde el panel de administración de correo.
            No es necesario responder.
        </p>
    </div>
</body>
</html>
