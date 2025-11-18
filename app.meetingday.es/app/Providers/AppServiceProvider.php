<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use GuzzleHttp\Client;            // ✅ ESTO es lo correcto
use GuzzleHttp\ClientInterface;   // (opcional pero recomendado)

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Alias string que algunos lugares de tu código usan: app('app.client')
        $this->app->singleton('app.client', function () {
            return new Client([
                'timeout' => 10,
                // 'verify' => false, // solo si tienes problemas de SSL; ideal dejarlo en true con cert válido
            ]);
        });

        // Si tipeas ClientInterface en constructores, esto lo resuelve
        $this->app->bind(ClientInterface::class, function () {
            return new Client(['timeout' => 10]);
        });
    }

    public function boot(): void
    {
        //
    }
}
