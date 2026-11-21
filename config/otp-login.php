<?php

/*
|--------------------------------------------------------------------------
| Login sin contraseña por código de un solo uso (módulo opcional `otp-login`, docs/otp-login.md)
|--------------------------------------------------------------------------
|
| Apagado por defecto. El código va por correo (siempre) y, si se activa, por SMS (`SmsSenderInterface`).
| Los límites cuentan igual exista o no el destino: la respuesta nunca revela si hay cuenta.
|
*/

return [
    'enabled' => (bool) env('OTP_LOGIN_ENABLED', false),

    // Canal SMS (destino en E.164 de `user_profiles.phone`). Requiere un SMS_MODE real o fake configurado.
    'sms_enabled' => (bool) env('OTP_LOGIN_SMS_ENABLED', false),

    'length' => max(6, (int) env('OTP_LOGIN_LENGTH', 6)),
    'ttl_minutes' => max(1, (int) env('OTP_LOGIN_TTL_MINUTES', 10)),

    // Intentos fallidos con el MISMO código antes de anularlo.
    'max_attempts' => max(1, (int) env('OTP_LOGIN_MAX_ATTEMPTS', 5)),

    // Códigos pedidos por destino y por IP en la ventana (cuenta existan o no).
    'send_per_destination' => (int) env('OTP_LOGIN_SEND_PER_DESTINATION', 3),
    'send_per_ip' => (int) env('OTP_LOGIN_SEND_PER_IP', 10),
    'send_window_minutes' => (int) env('OTP_LOGIN_SEND_WINDOW_MINUTES', 15),

    // Verificaciones fallidas por destino y por IP en la ventana (bloqueo aunque se pidan códigos nuevos).
    'verify_failures_per_destination' => (int) env('OTP_LOGIN_VERIFY_FAILURES_PER_DESTINATION', 10),
    'verify_failures_per_ip' => (int) env('OTP_LOGIN_VERIFY_FAILURES_PER_IP', 20),
    'verify_window_minutes' => (int) env('OTP_LOGIN_VERIFY_WINDOW_MINUTES', 15),

    // Días que se conservan las filas ya usadas o caducadas (`otp-login:clean`).
    'retention_days' => (int) env('OTP_LOGIN_RETENTION_DAYS', 1),
];
