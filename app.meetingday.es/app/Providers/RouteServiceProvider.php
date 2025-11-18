<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

class RouteServiceProvider extends ServiceProvider
{
    public const HOME = '/home';

    public function boot(): void
    {
        // Global (para endpoints no-polling)
        RateLimiter::for('api', function (Request $request) {
            $key = $this->keyFor($request, 'api-global', null); // <-- antes usaba paramName:
            return [ Limit::perMinute(200)->by($key) ];
        });

        // ===== Limiters POR RUTA =====
        RateLimiter::for('poll-activos', function (Request $request) {
            $key = $this->keyFor($request, 'poll-activos', 'user'); // <-- posicional
            return [ Limit::perMinute(120)->by($key)->response($this->tooMany()) ];
        });
        
        RateLimiter::for('poll-creados', function (Request $request) {
            $key = $this->keyFor($request, 'poll-creados', 'user'); // <-- posicional
            return [ Limit::perMinute(120)->by($key)->response($this->tooMany()) ];
        });
        
        RateLimiter::for('poll-listar', function (Request $request) {
            $key = $this->keyFor($request, 'poll-listar', 'nick'); // <-- posicional
            return [ Limit::perMinute(120)->by($key)->response($this->tooMany()) ];
        });
        // ============================

        $this->routes(function () {
            Route::middleware('api')->prefix('api')->group(base_path('routes/api.php'));
            Route::middleware('web')->group(base_path('routes/web.php'));
        });
    }

    private function tooMany(): \Closure
    {
        return function () {
            return response()->json(['message' => 'Too Many Requests'], 429)
                ->header('Retry-After', 3);
        };
    }

    /**
     * Key sin auth:
     * - Usa el parámetro esperado (user|nick) si llega, si no "anon"
     * - Incluye IP real, path y User-Agent para separar buckets
     * - IMPORTANTE: configura TrustProxies para que $request->ip() sea la IP del cliente
     */
    private function keyFor(Request $request, string $bucket, ?string $paramName): string
    {
        $ip   = $request->ip() ?: '0.0.0.0';
        $path = trim($request->path(), '/');                  // ej: api/v1/eventos/invitado/listar
        $ua   = substr($request->userAgent() ?: 'no-ua', 0, 80);

        $idFromQuery = 'anon';
        if ($paramName) {
            $raw = $request->query($paramName);
            if (is_string($raw) && $raw !== '') {
                $idFromQuery = strtolower(trim($raw));        // normaliza correo/nick
            }
        }

        // hash compacto
        return sha1($bucket.'|id:'.$idFromQuery.'|ip:'.$ip.'|p:'.$path.'|ua:'.$ua);
    }
}
