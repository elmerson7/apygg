<?php

namespace App\Http\Controllers\Privacy;

use App\Contracts\AccountDeletionServiceInterface;
use App\Http\Controllers\Controller;
use App\Http\Requests\Privacy\RequestAccountDeletionRequest;
use App\Models\AccountDeletionRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Borrado de la propia cuenta (`/user/account-deletion`).
 */
class AccountDeletionController extends Controller
{
    public function __construct(private readonly AccountDeletionServiceInterface $deletions) {}

    public function show(Request $request): JsonResponse
    {
        $pending = $this->deletions->pending($request->user());

        return $this->sendSuccess($pending ? $this->present($pending) : null, 'Solicitud de borrado');
    }

    public function store(RequestAccountDeletionRequest $request): JsonResponse
    {
        $row = $this->deletions->request($request->user(), (string) $request->validated('password'), $request->validated('second_factor'), $request->ip());

        return $this->sendSuccess($this->present($row), 'Borrado de cuenta programado', 202);
    }

    public function destroy(Request $request): JsonResponse
    {
        return $this->sendSuccess($this->present($this->deletions->cancel($request->user())), 'Borrado de cuenta cancelado');
    }

    /**
     * @return array<string, mixed>
     */
    private function present(AccountDeletionRequest $row): array
    {
        return [
            'id' => $row->id,
            'status' => $row->status,
            'requested_at' => $row->requested_at->toIso8601String(),
            'scheduled_for' => $row->scheduled_for->toIso8601String(),
            'cancelled_at' => $row->cancelled_at?->toIso8601String(),
        ];
    }
}
