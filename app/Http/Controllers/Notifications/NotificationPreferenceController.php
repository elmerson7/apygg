<?php

namespace App\Http\Controllers\Notifications;

use App\Contracts\NotificationPreferenceServiceInterface;
use App\Http\Controllers\Controller;
use App\Http\Requests\Notifications\UpdateNotificationPreferencesRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Preferencias de notificación del usuario en sesión. Son de la persona (valen en todos sus tenants).
 */
class NotificationPreferenceController extends Controller
{
    public function __construct(private readonly NotificationPreferenceServiceInterface $preferences) {}

    public function show(Request $request): JsonResponse
    {
        return $this->sendSuccess($this->preferences->forUser($this->authUser($request)), 'Preferencias de notificación');
    }

    public function update(UpdateNotificationPreferencesRequest $request): JsonResponse
    {
        $this->preferences->update(
            $this->authUser($request),
            $request->validated('preferences', []),
            $request->validated('quiet_hours'),
        );

        return $this->sendSuccess($this->preferences->forUser($this->authUser($request)), 'Preferencias actualizadas');
    }
}
