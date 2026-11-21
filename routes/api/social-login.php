<?php

use App\Http\Controllers\Auth\SocialLoginController;
use Illuminate\Support\Facades\Route;

/*
| Login social (módulo `social-login`, docs/social-login.md). Público: la app aún no tiene sesión.
| Apagado por defecto (`SOCIAL_LOGIN_ENABLED`): sin activar responde 404 `social_provider_unavailable`.
*/
Route::post('/auth/social/{provider}', [SocialLoginController::class, 'store'])
    ->where('provider', '[a-z0-9_-]{2,40}')
    ->name('auth.social.login');
