<?php

namespace App\Http\Controllers\Billing;

use App\Contracts\InvoiceServiceInterface;
use App\Contracts\PaymentProcessorInterface;
use App\Http\Controllers\Concerns\UsesActiveTenant;
use App\Http\Controllers\Controller;
use App\Http\Requests\Billing\IndexInvoicesRequest;
use App\Http\Resources\Billing\InvoiceResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Facturas de la empresa activa. El tenant sale del contexto, nunca de la query ni del cuerpo.
 */
class TenantInvoiceController extends Controller
{
    use UsesActiveTenant;

    protected ?string $resource = InvoiceResource::class;

    public function __construct(
        private readonly InvoiceServiceInterface $invoices,
        private readonly PaymentProcessorInterface $payments,
    ) {}

    public function index(IndexInvoicesRequest $request): JsonResponse
    {
        return $this->sendPaginated($this->invoices->paginate(
            $this->activeTenant($request),
            $this->actor($request),
            $request->safe()->only(['status']),
            $this->perPage($request),
        ));
    }

    public function show(Request $request, int $id): JsonResponse
    {
        return $this->sendSuccess(new InvoiceResource($this->invoices->find($this->activeTenant($request), $this->actor($request), $id)), 'Factura');
    }

    /**
     * PDF de la factura (mismo permiso y rango que verla; la de otro tenant da 404).
     */
    public function pdf(Request $request, int $id): StreamedResponse
    {
        $pdf = $this->invoices->pdf($this->activeTenant($request), $this->actor($request), $id);

        return response()->streamDownload(function () use ($pdf): void {
            echo $pdf['content'];
        }, $pdf['filename'], ['Content-Type' => 'application/pdf']);   // `attachment`: ForceJsonResponse respeta las descargas
    }

    /**
     * Reintenta el cobro de una factura abierta (p. ej. tras cambiar de tarjeta).
     */
    public function pay(Request $request, int $id): JsonResponse
    {
        $invoice = $this->payments->pay($this->activeTenant($request), $this->actor($request), $id);

        return $this->sendSuccess(new InvoiceResource($invoice), 'Factura pagada');
    }

    private function actor(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
