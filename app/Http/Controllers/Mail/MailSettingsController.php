<?php

namespace App\Http\Controllers\Mail;

use App\Http\Controllers\Controller;
use App\Http\Requests\Mail\UpdateMailSettingsRequest;
use App\Models\Mail\MailSetting;
use Illuminate\Http\JsonResponse;

/**
 * MailSettingsController
 *
 * Ajustes globales del sistema de correo: pausa total y límites de envío.
 */
class MailSettingsController extends Controller
{
    public function index(): JsonResponse
    {
        return $this->sendSuccess([
            MailSetting::KEY_GLOBAL_PAUSE => MailSetting::isGloballyPaused(),
            MailSetting::KEY_DAILY_LIMIT => (int) MailSetting::getValue(MailSetting::KEY_DAILY_LIMIT, 0),
            MailSetting::KEY_RATE_LIMIT => (int) MailSetting::getValue(MailSetting::KEY_RATE_LIMIT, 0),
        ], 'Ajustes de correo obtenidos exitosamente');
    }

    public function update(UpdateMailSettingsRequest $request): JsonResponse
    {
        foreach ($request->validated() as $key => $value) {
            MailSetting::setValue($key, $value);
        }

        return $this->index();
    }
}
