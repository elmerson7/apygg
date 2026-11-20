<?php

namespace App\Http\Controllers\Privacy;

use App\Contracts\ConsentServiceInterface;
use App\Http\Controllers\Controller;
use App\Http\Requests\Privacy\AcceptConsentsRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Consentimientos del usuario en sesión (`/user/consents`): cada uno toca solo lo suyo.
 */
class ConsentController extends Controller
{
    public function __construct(private readonly ConsentServiceInterface $consents) {}

    public function index(Request $request): JsonResponse
    {
        return $this->sendSuccess([
            'consents' => $this->consents->stateFor($request->user()),
            'pending' => $this->consents->pendingFor($request->user()),
        ], 'Consentimientos');
    }

    public function accept(AcceptConsentsRequest $request): JsonResponse
    {
        /** @var list<array{key: string, version: string}> $documents */
        $documents = $request->validated('documents');
        $this->consents->accept($request->user(), $documents, $request->ip(), $request->userAgent());

        return $this->sendSuccess([
            'consents' => $this->consents->stateFor($request->user()),
            'pending' => $this->consents->pendingFor($request->user()),
        ], 'Consentimientos registrados');
    }

    public function withdraw(Request $request, string $key): JsonResponse
    {
        $this->consents->withdraw($request->user(), $key, $request->ip(), $request->userAgent());

        return $this->sendSuccess(['consents' => $this->consents->stateFor($request->user())], 'Consentimiento retirado');
    }
}
