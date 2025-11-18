<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;
use App\Services\Notifications\NotificationScheduler;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        // Ejecuta TODA la lógica de notificaciones cada minuto
        $schedule->call(function (NotificationScheduler $notifier) {
            // puedes pasar false o true si quieres dry-run global
            $notifier->runAll(false);
        })
        ->name('notifications:run-all')   // ✅ primero darle nombre
        ->withoutOverlapping()           // ✅ luego el lock
        ->everyMinute()
        ->onOneServer();                 // opcional en single server, no hace daño
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
