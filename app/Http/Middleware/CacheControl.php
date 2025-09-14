<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CacheControl
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     * @param  string  $directive  Cache-Control directive (default: private, no-cache)
     */
    public function handle(Request $request, Closure $next, string $directive = 'private, no-cache'): Response
    {
        $response = $next($request);
        
        // Solo aplicar si no existe ya una cabecera Cache-Control
        if (!$response->headers->has('Cache-Control')) {
            $response->headers->set('Cache-Control', $directive);
        }
        
        return $response;
    }
}
