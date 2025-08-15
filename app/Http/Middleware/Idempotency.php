<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Support\Facades\Cache;

class Idempotency
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next, $ttlSeconds = 120): Response
    {
        if (! $request->isMethodSafe() && ($key = $request->header('Idempotency-Key'))) {
            $cacheKey = 'idemp:' . sha1(
                $request->method() .
                $request->path() .
                $key .
                ($request->user()->id ?? 'guest')
            );

            if ($cached = Cache::store('redis')->get($cacheKey)) {
                return response($cached['body'], $cached['status'], $cached['headers']);
            }

            /** @var Response $response */
            $response = $next($request);

            Cache::store('redis')->put($cacheKey, [
                'status'  => $response->getStatusCode(),
                'headers' => ['Content-Type' => $response->headers->get('Content-Type')],
                'body'    => $response->getContent(),
            ], $ttlSeconds);

            return $response;
        }

        return $next($request);
    }
}
