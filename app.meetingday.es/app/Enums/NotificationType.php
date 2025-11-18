<?php

declare(strict_types=1);

namespace App\Enums;

final class NotificationType
{
    // Invitados
    public const INVITE_EVENT = 1;
    public const CANCEL_EVENT = 2;
    public const LOCATION_CONFIRMATION = 3;
    public const ATTENDANCE_REMINDER_2DAYS = 4;
    public const ATTENDANCE_REMINDER_1DAY = 5;
    public const ATTENDANCE_REMINDER_2HOURS = 6;
    public const ATTENDANCE_CONFIRMED = 7;
    public const EVENT_START_REMINDER_1HOUR = 8;

    // Creadores
    public const CREATOR_CONFIRMATION_REMINDER_2DAYS = 9;
    public const CREATOR_CONFIRMATION_REMINDER_1DAY = 10;
    public const CREATOR_CONFIRMATION_REMINDER_2HOURS = 11;
    public const CANCEL_BY_NO_CONFIRMATION = 12;
}
