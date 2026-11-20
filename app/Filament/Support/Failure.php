<?php

namespace App\Filament\Support;

use App\Exceptions\ApiException;
use Filament\Notifications\Notification;
use Illuminate\Auth\Access\AuthorizationException;
use Throwable;

/**
 * Convierte en aviso del panel los errores de dominio que lanzan los servicios
 * (último admin, nombre duplicado, permiso que no se tiene...). Cualquier otra
 * excepción es un fallo real y se relanza: llega al handler global y se registra.
 */
final class Failure
{
    /**
     * Ejecuta $callback; si falla con un error de dominio, avisa y devuelve null.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T|null
     */
    public static function guard(callable $callback): mixed
    {
        try {
            return $callback();
        } catch (Throwable $e) {
            if (! self::isDomainError($e)) {
                throw $e;
            }

            Notification::make()->danger()->title($e->getMessage())->send();

            return null;
        }
    }

    public static function isDomainError(Throwable $e): bool
    {
        return // Todas las de dominio de la API (BusinessLogic, Audit, FeatureFlag, Privacy, PlanLimit...)
            $e instanceof ApiException
            || $e instanceof AuthorizationException
            || $e instanceof \InvalidArgumentException
            // Los servicios lanzan `\Exception` simples con un mensaje pensado para el usuario
            // (p. ej. rol con usuarios asignados). Las subclases (QueryException...) no cuentan.
            || $e::class === \Exception::class;
    }
}
