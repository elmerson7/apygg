<?php

namespace App\Http\Middleware;

use App\Contracts\LocaleResolverInterface;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

/**
 * SetApiLocale
 *
 * Fija el idioma de la petición (usuario -> tenant -> Accept-Language -> instalación) y
 * responde `Content-Language`. Octane: el locale vive en el proceso, así que se RESTAURA al
 * terminar (también si hay excepción), y `ResolveTenant` lo reaplica al conocer el tenant.
 * El texto de los errores se genera dentro de este middleware (el handler renderiza antes de salir).
 */
class SetApiLocale
{
    public function __construct(private readonly LocaleResolverInterface $resolver) {}

    public function handle(Request $request, Closure $next): Response
    {
        $previous = App::getLocale();

        try {
            $this->apply();

            $response = $next($request);

            $response->headers->set('Content-Language', App::getLocale());

            return $response;
        } finally {
            App::setLocale($previous);
        }
    }

    /**
     * Reaplica el idioma resuelto (lo llama `ResolveTenant` tras fijar el tenant).
     */
    public function apply(): void
    {
        App::setLocale($this->resolver->locale());
    }
}
