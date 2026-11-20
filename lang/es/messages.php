<?php

/*
 * Mensajes de la API que no son de validación. Cada clave existe en lang/en y en lang/es
 * (LangParidadTest lo vigila). El front traduce por `code`; el `message` es informativo.
 */
return [
    'errors' => [
        'not_found' => 'Recurso no encontrado.',
        'endpoint_not_found' => 'Endpoint no encontrado.',
        'endpoint_not_found_detail' => 'El endpoint solicitado no existe.',
        'unauthenticated' => 'No autenticado',
        'unauthorized' => 'No autorizado',
        'forbidden' => 'No tienes permiso para realizar esta acción',
        'token_invalid' => 'Token inválido o expirado',
        'validation' => 'Los datos proporcionados no son válidos.',
        'duplicate' => 'Ya existe un registro con este valor.',
        'server_error' => 'Ocurrió un error interno del servidor.',
        'generic' => 'Ocurrió un error.',
    ],
    'success' => [
        'created' => 'Recurso creado exitosamente.',
        'updated' => 'Recurso actualizado exitosamente.',
        'deleted' => 'Recurso eliminado exitosamente.',
        'restored' => 'Recurso restaurado exitosamente.',
    ],
    'locale' => [
        'show' => 'Configuración regional obtenida exitosamente',
        'updated' => 'Configuración regional actualizada exitosamente',
        'tenant_show' => 'Configuración regional de la empresa obtenida exitosamente',
        'tenant_updated' => 'Configuración regional de la empresa actualizada exitosamente',
    ],
    'mail' => [
        'welcome' => [
            'subject' => 'Bienvenido a :app',
            'greeting' => '¡Hola :name!',
            'intro' => 'Te damos la bienvenida a :app.',
            'body' => 'Tu cuenta ha sido creada exitosamente y ya puedes comenzar a usar nuestros servicios.',
            'help' => 'Si tienes alguna pregunta o necesitas ayuda, no dudes en contactarnos.',
            'salutation' => 'Saludos, el equipo de :app',
        ],
        'password_reset' => [
            'subject' => 'Restablecer Contraseña',
            'greeting' => 'Hola :name,',
            'intro' => 'Recibiste este email porque solicitaste restablecer tu contraseña.',
            'action' => 'Restablecer Contraseña',
            'expires' => 'Este enlace expirará en 60 minutos.',
            'ignore' => 'Si no solicitaste este cambio, ignora este email.',
            'salutation' => 'Saludos, :app',
        ],
        'email_verification' => [
            'subject' => 'Verifica tu correo electrónico',
            'greeting' => 'Hola :name,',
            'intro' => 'Confirma que este correo es tuyo para terminar de configurar tu cuenta.',
            'action' => 'Verificar correo',
            'expires' => 'Este enlace expirará en :minutes minutos.',
            'ignore' => 'Si no creaste esta cuenta, ignora este email.',
            'salutation' => 'Saludos, :app',
        ],
    ],
];
