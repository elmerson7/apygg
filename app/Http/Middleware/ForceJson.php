<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ForceJson
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {

        $request->headers->set('Accept', 'application/json');

        if (! in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'])
            && ! str_starts_with($request->header('Content-Type', ''), 'application/json')) {
            return response()->json([
                'type' => 'https://damblix.dev/errors/UnsupportedMediaType',
                'title' => 'Unsupported Media Type',
                'status' => 415,
                'detail' => 'Use Content-Type: application/json',
                'instance' => method_exists($request, 'fullUrl')
                    ? $request->fullUrl()
                    : (request()?->fullUrl() ?? null),
            ], 415, ['Content-Type' => 'application/problem+json']);
        }

        $response = $next($request);

        $existing = $response->headers->get('Vary');
        $vary = array_filter(array_unique(array_merge(
            $existing ? array_map('trim', explode(',', $existing)) : [],
            ['Accept', 'Accept-Language']
        )));
        if ($vary) {
            $response->headers->set('Vary', implode(', ', $vary));
        }

        return $response;
    }
}
