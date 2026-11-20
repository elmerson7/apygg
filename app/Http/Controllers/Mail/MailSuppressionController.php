<?php

namespace App\Http\Controllers\Mail;

use App\Contracts\MailSuppressionServiceInterface;
use App\Http\Controllers\Controller;
use App\Http\Requests\Mail\StoreMailSuppressionRequest;
use App\Http\Resources\Mail\MailSuppressionResource;
use App\Models\Mail\MailSuppression;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * MailSuppressionController
 *
 * Lista de supresión: direcciones a las que no se envía (rebote permanente, queja o manual). Global de
 * la plataforma. Quitar una dirección permite volver a enviarle; si vuelve a rebotar, se suprime otra vez.
 */
class MailSuppressionController extends Controller
{
    public function __construct(private readonly MailSuppressionServiceInterface $suppressions) {}

    public function index(Request $request): JsonResponse
    {
        $rows = MailSuppression::query()
            ->when($request->filled('email'), fn ($q) => $q->where('email', 'ILIKE', '%'.addcslashes($request->string('email')->toString(), '%_\\').'%'))
            ->when($request->filled('reason'), fn ($q) => $q->where('reason', $request->string('reason')))
            ->when($request->filled('source'), fn ($q) => $q->where('source', $request->string('source')))
            ->orderByDesc('suppressed_at')
            ->orderByDesc('id')
            ->paginate($this->perPage($request, 25));

        return $this->sendPaginated($rows, resource: MailSuppressionResource::class);
    }

    public function store(StoreMailSuppressionRequest $request): JsonResponse
    {
        $row = $this->suppressions->suppress(
            $request->string('email')->toString(),
            MailSuppression::REASON_MANUAL,
            'admin',
            array_filter(['note' => $request->input('note'), 'by_user_id' => $request->user()?->id]),
        );

        return $this->sendSuccess(new MailSuppressionResource($row), 'Dirección suprimida', 201);
    }

    public function destroy(MailSuppression $mailSuppression): JsonResponse
    {
        $this->suppressions->release($mailSuppression->email);

        return $this->sendSuccess(null, 'Dirección quitada de la lista de supresión');
    }
}
