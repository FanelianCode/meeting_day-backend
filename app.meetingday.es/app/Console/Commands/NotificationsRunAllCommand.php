<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\Notifications\NotificationScheduler;

class NotificationsRunAllCommand extends Command
{
    protected $signature = 'notifications:run-all {--dry-run}';
    protected $description = 'Ejecuta todos los procesos de notificaciones (invitados + creadores).';

    public function handle(NotificationScheduler $scheduler): int
    {
        $dry = (bool) $this->option('dry-run');
        $scheduler->runAll($dry);
        $this->info('✔️ Notificaciones ejecutadas correctamente' . ($dry ? ' (dry-run)' : ''));
        return self::SUCCESS;
    }
}
