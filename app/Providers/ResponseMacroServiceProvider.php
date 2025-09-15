<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Http\Response;
use Illuminate\Routing\ResponseFactory;

class ResponseMacroServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        ResponseFactory::macro('apiJson', function ($data, $status = 200) {
            $request = request();
            
            return $this->json([
                'success' => $status >= 200 && $status < 300,
                'data' => $data,
                'meta' => [
                    'trace_id' => $request->attributes->get('trace_id'),
                    'timestamp' => now()->toISOString(),
                    'version' => '1.0',
                ],
            ], $status);
        });
    }
}
