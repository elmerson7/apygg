<?php

namespace App\Http\Controllers\Mail;

use App\Contracts\MailAccountServiceInterface;
use App\Contracts\MailServiceInterface;
use App\Http\Controllers\Controller;
use App\Http\Requests\Mail\SendTestMailRequest;
use App\Http\Requests\Mail\StoreMailAccountRequest;
use App\Http\Requests\Mail\UpdateMailAccountRequest;
use App\Http\Resources\Mail\MailAccountResource;
use App\Models\Mail\MailAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * MailAccountController
 *
 * CRUD de cuentas de envío y prueba de entrega.
 */
class MailAccountController extends Controller
{
    public function __construct(private readonly MailAccountServiceInterface $accounts) {}

    public function index(Request $request): JsonResponse
    {
        $accounts = MailAccount::query()
            ->with('mailProvider')
            ->withCount('templateConfigs')
            ->orderBy('priority')
            ->paginate($request->integer('per_page', 15));

        return $this->sendPaginated($accounts);
    }

    public function store(StoreMailAccountRequest $request): JsonResponse
    {
        $account = $this->accounts->create($request->validated());

        return $this->sendSuccess(
            new MailAccountResource($account->load('mailProvider')),
            'Cuenta de correo creada exitosamente',
            201
        );
    }

    public function show(MailAccount $mailAccount): JsonResponse
    {
        return $this->sendSuccess(
            new MailAccountResource($mailAccount->load('mailProvider')),
            'Cuenta obtenida exitosamente'
        );
    }

    public function update(UpdateMailAccountRequest $request, MailAccount $mailAccount): JsonResponse
    {
        $this->accounts->update($mailAccount, $request->validated());

        return $this->sendSuccess(
            new MailAccountResource($mailAccount->load('mailProvider')),
            'Cuenta actualizada exitosamente'
        );
    }

    public function destroy(MailAccount $mailAccount): JsonResponse
    {
        if ($mailAccount->is_default) {
            return $this->sendError('No se puede eliminar la cuenta por defecto', 422);
        }

        $mailAccount->delete();

        return $this->sendSuccess(null, 'Cuenta eliminada exitosamente');
    }

    /**
     * Enviar un correo de prueba desde esta cuenta.
     *
     * Se envía de forma síncrona a propósito: quien pulsa "probar" necesita
     * saber en el acto si la configuración funciona, no que se encole.
     */
    public function test(SendTestMailRequest $request, MailAccount $mailAccount, MailServiceInterface $mail): JsonResponse
    {
        $sent = $mail->send(
            to: $request->validated('email'),
            subject: 'Correo de prueba — '.config('app.name'),
            view: 'emails.test',
            data: [
                'app_name' => config('app.name'),
                'account_name' => $mailAccount->name,
                'driver' => $mailAccount->getDriver(),
                'sent_at' => now()->format('Y-m-d H:i:s'),
            ],
            queue: false,
            account: $mailAccount,
        );

        return $sent
            ? $this->sendSuccess(null, 'Correo de prueba enviado exitosamente')
            : $this->sendError('No se pudo enviar el correo de prueba. Revisa los logs de correo.', 422);
    }
}
