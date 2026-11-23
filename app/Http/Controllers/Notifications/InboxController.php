<?php

namespace App\Http\Controllers\Notifications;

use App\Contracts\InboxServiceInterface;
use App\Http\Controllers\Controller;
use App\Http\Requests\Notifications\IndexInboxRequest;
use App\Http\Resources\Notifications\InboxNotificationResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Bandeja in-app del usuario en sesión, en el tenant activo (ruta con middleware `tenant`).
 * Sin permiso propio: cada usuario solo ve y toca lo suyo (el id ajeno es 404).
 */
class InboxController extends Controller
{
    protected ?string $resource = InboxNotificationResource::class;

    public function __construct(private readonly InboxServiceInterface $inbox) {}

    public function index(IndexInboxRequest $request): JsonResponse
    {
        $perPage = $request->integer('per_page') ?: null;

        return $this->sendPaginated($this->inbox->paginate(
            $this->authUser($request),
            ['unread' => $request->boolean('unread'), 'type' => $request->validated('type')],
            $perPage,
        ));
    }

    public function unreadCount(Request $request): JsonResponse
    {
        return $this->sendSuccess(['unread' => $this->inbox->unreadCount($this->authUser($request))], 'Notificaciones sin leer');
    }

    public function read(Request $request, int $id): JsonResponse
    {
        return $this->sendSuccess($this->inbox->markRead($this->authUser($request), $id), 'Notificación marcada como leída');
    }

    public function readAll(Request $request): JsonResponse
    {
        return $this->sendSuccess(['marked' => $this->inbox->markAllRead($this->authUser($request))], 'Notificaciones marcadas como leídas');
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->inbox->delete($this->authUser($request), $id);

        return $this->sendSuccess(null, 'Notificación eliminada');
    }
}
