<?php

use App\Enums\NotificationType as T;

return [
    // === Canales por tipo ===
    T::INVITE_EVENT                  => ['push' => true,  'mail' => true ],
    T::CANCEL_EVENT                  => ['push' => true, 'mail' => true],
    T::LOCATION_CONFIRMATION         => ['push' => false, 'mail' => true ],

    T::ATTENDANCE_REMINDER_2DAYS     => ['push' => false, 'mail' => true ],
    T::ATTENDANCE_REMINDER_1DAY      => ['push' => false, 'mail' => true ],
    T::ATTENDANCE_REMINDER_2HOURS    => ['push' => false, 'mail' => true ],
    T::ATTENDANCE_CONFIRMED          => ['push' => false, 'mail' => true ],
    T::EVENT_START_REMINDER_1HOUR    => ['push' => false, 'mail' => true ],

    // Solo correo para recordatorios del creador
    T::CREATOR_CONFIRMATION_REMINDER_2DAYS  => ['push' => false, 'mail' => true ],
    T::CREATOR_CONFIRMATION_REMINDER_1DAY   => ['push' => false, 'mail' => true ],
    T::CREATOR_CONFIRMATION_REMINDER_2HOURS => ['push' => false, 'mail' => true ],

    T::CANCEL_BY_NO_CONFIRMATION     => ['push' => false, 'mail' => false ],

    // === BANDERAS DE EJECUCIÓN (no afectan canales) ===
    'creator_reminders_enabled' => false,
    'auto_cancel_enabled' => false, // ⬅️ mantenlo en false para NO ejecutar el auto-cancel
];
