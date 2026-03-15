# Mailpit (solo local)

Captura el correo saliente en desarrollo. No envía correos reales.

- **SMTP**: `mailpit:1025` (desde otros contenedores) — Laravel usa `MAIL_HOST=mailpit`, `MAIL_PORT=1025`
- **Panel web**: http://localhost:8019 (puerto expuesto en el host)

Perfil: `dev`.
