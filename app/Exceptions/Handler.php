<?php

namespace App\Exceptions;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

class Handler extends ExceptionHandler
{
    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    public function register(): void
    {
        $this->reportable(function (Throwable $e) {
            //
        });
    }

    public function render($request, Throwable $e): JsonResponse|\Illuminate\Http\Response|\Symfony\Component\HttpFoundation\Response
    {
        if ($request->expectsJson() || $request->is('api/*') || $request->is('docs/*')) {
            return $this->handleApiException($request, $e);
        }

        return parent::render($request, $e);
    }

    protected function handleApiException($request, Throwable $e): JsonResponse
    {
        if ($e instanceof ValidationException) {
            return $this->handleValidationException($e);
        }

        if ($e instanceof AuthenticationException) {
            return $this->handleAuthenticationException($e);
        }

        if ($e instanceof NotFoundHttpException) {
            return $this->handleNotFoundException($e);
        }

        if ($e instanceof MethodNotAllowedHttpException) {
            return $this->handleMethodNotAllowedException($e);
        }

        if ($e instanceof HttpException) {
            return $this->handleHttpException($e);
        }

        return $this->handleGenericException($e);
    }

    protected function handleValidationException(ValidationException $e): JsonResponse
    {
        $errors = [];
        foreach ($e->validator->errors()->messages() as $field => $messages) {
            $errors[$field] = array_map(function ($message) {
                return is_string($message) ? $message : current($message);
            }, $messages);
        }

        return response()->json([
            'success' => false,
            'message' => 'Validation failed',
            'errors' => $errors,
        ], 422);
    }

    protected function handleAuthenticationException(AuthenticationException $e): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'Unauthenticated',
            'error' => 'Token de autenticación inválido o no proporcionado',
        ], 401);
    }

    protected function handleNotFoundException(NotFoundHttpException $e): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'Resource not found',
            'error' => 'El endpoint o recurso solicitado no existe',
        ], 404);
    }

    protected function handleMethodNotAllowedException(MethodNotAllowedHttpException $e): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'Method not allowed',
            'error' => 'El método HTTP utilizado no está permitido para este endpoint',
        ], 405);
    }

    protected function handleHttpException(HttpException $e): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $e->getMessage() ?: 'HTTP Error',
            'error' => $this->getHttpErrorMessage($e->getStatusCode()),
        ], $e->getStatusCode());
    }

    protected function handleGenericException(Throwable $e): JsonResponse
    {
        $statusCode = 500;

        if (method_exists($e, 'getStatusCode')) {
            $statusCode = $e->getStatusCode();
        }

        $response = [
            'success' => false,
            'message' => 'Internal server error',
            'error' => config('app.debug') ? $e->getMessage() : 'Ha ocurrido un error interno',
        ];

        if (config('app.debug')) {
            $response['exception'] = get_class($e);
            $response['trace'] = $e->getTraceAsString();
        }

        return response()->json($response, $statusCode);
    }

    protected function getHttpErrorMessage(int $statusCode): string
    {
        return match ($statusCode) {
            400 => 'Solicitud malformada',
            401 => 'No autenticado',
            403 => 'No autorizado',
            404 => 'Recurso no encontrado',
            405 => 'Método no permitido',
            408 => 'Tiempo de espera agotado',
            429 => 'Demasiadas solicitudes',
            500 => 'Error interno del servidor',
            502 => 'Bad Gateway',
            503 => 'Servicio no disponible',
            504 => 'Gateway timeout',
            default => 'Error HTTP',
        };
    }
}