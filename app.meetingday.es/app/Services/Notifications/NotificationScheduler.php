<?php

namespace App\Services\Notifications;

use App\Services\Notifications\Handlers\GuestNotifications;
use App\Services\Notifications\Handlers\CreatorNotifications;
use App\Enums\NotificationType;

class NotificationScheduler
{
    /** @var \App\Services\Notifications\Handlers\GuestNotifications */
    protected $guest;

    /** @var \App\Services\Notifications\Handlers\CreatorNotifications */
    protected $creator;

    public function __construct(GuestNotifications $guest, CreatorNotifications $creator)
    {
        $this->guest   = $guest;
        $this->creator = $creator;
    }

    /**
     * Ejecuta TODAS las notificaciones soportadas.
     * - Invitados: usa métodos actuales (incluye el "auto" para 4/5/6).
     * - Creadores: respeta banderas en config/notifications.php:
     *      - creator_reminders_enabled (9/10/11)
     *      - auto_cancel_enabled (12)
     */
    public function runAll(bool $dry = false): void
    {
        // ----- Invitados -----
        $this->guest->inviteEvent($dry);                  // 1
        $this->guest->cancelEvent($dry);                  // 2
        $this->guest->locationConfirmation($dry);         // 3

        // Consolidado: reemplaza 4/5/6 por un único barrido idempotente
        $this->guest->attendanceRemindersAuto($dry);      // 4,5,6

        $this->guest->attendanceConfirmed($dry);          // 7
        $this->guest->eventStartReminder1Hour($dry);      // 8

        // ----- Creadores (protegidos por flags) -----
        if (config('notifications.creator_reminders_enabled', false)) {
            // Consolidado: reemplaza 9/10/11 por un único barrido idempotente
            $this->creator->confirmationRemindersAuto($dry); // 9,10,11
        }

        // 12: existe, pero NO se ejecuta si la bandera está en false
        if (config('notifications.auto_cancel_enabled', false)) {
            $this->creator->cancelByNoConfirmation($dry);     // 12
        }
    }

    /**
     * Ejecuta una sola notificación por tipo (según App\Enums\NotificationType).
     * Nota: los tipos agrupados llaman sus métodos "auto" y dependen de flags (creador).
     */
    public function runByType(int $type, bool $dry = false): void
    {
        switch ($type) {
            // ----- Invitados -----
            case NotificationType::INVITE_EVENT:                    // 1
                $this->guest->inviteEvent($dry);
                break;

            case NotificationType::CANCEL_EVENT:                    // 2
                $this->guest->cancelEvent($dry);
                break;

            case NotificationType::LOCATION_CONFIRMATION:           // 3
                $this->guest->locationConfirmation($dry);
                break;

            // Agrupados → disparan el barrido que decide 4/5/6 idempotentemente
            case NotificationType::ATTENDANCE_REMINDER_2DAYS:       // 4
            case NotificationType::ATTENDANCE_REMINDER_1DAY:        // 5
            case NotificationType::ATTENDANCE_REMINDER_2HOURS:      // 6
                $this->guest->attendanceRemindersAuto($dry);
                break;

            case NotificationType::ATTENDANCE_CONFIRMED:            // 7
                $this->guest->attendanceConfirmed($dry);
                break;

            case NotificationType::EVENT_START_REMINDER_1HOUR:      // 8
                $this->guest->eventStartReminder1Hour($dry);
                break;

            // ----- Creadores -----
            // Agrupados → barrido que decide 9/10/11 idempotentemente (respeta flag)
            case NotificationType::CREATOR_CONFIRMATION_REMINDER_2DAYS:   // 9
            case NotificationType::CREATOR_CONFIRMATION_REMINDER_1DAY:    // 10
            case NotificationType::CREATOR_CONFIRMATION_REMINDER_2HOURS:  // 11
                if (config('notifications.creator_reminders_enabled', false)) {
                    $this->creator->confirmationRemindersAuto($dry);
                }
                break;

            // 12 deshabilitado por bandera (aunque el código exista)
            default:
                // Opcional: log o excepción controlada
                // throw new \InvalidArgumentException("Tipo de notificación no soportado: {$type}");
                break;
        }
    }
}
