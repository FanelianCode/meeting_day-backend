<?php

namespace App\Services\Notifications\Handlers;

use App\Enums\NotificationType as T;
use App\Services\Notifications\NotificationDispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Carbon;

class CreatorNotifications
{
    /** @var \App\Services\Notifications\NotificationDispatcher */
    protected $dispatcher;

    public function __construct(NotificationDispatcher $dispatcher)
    {
        $this->dispatcher = $dispatcher;
    }

    /**
     * Recordatorios al creador (9/10/11).
     * No corre si notifications.creator_reminders_enabled = false
     */
    public function confirmationRemindersAuto(bool $dry = false): int
    {
        if (!config('notifications.creator_reminders_enabled', false)) {
            return 0; // 🔒 apagado por bandera
        }

        $rows = DB::table('data as d') // creador
            ->join('eventos as e', 'e.id_user', '=', 'd.id_data')
            ->where('e.confirm', 0)
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                  ->from('cancelaciones as c')
                  ->whereRaw('c.id_evento = e.id_evento');
            })
            ->selectRaw("
                d.id_data,
                d.mail,
                d.token_movil,
                CONCAT(d.nombre, ' ', d.apellido) AS nombreCreador,
                e.id_evento,
                e.titulo,
                e.flimit,
                e.hlimit,
                e.time_zone
            ")
            ->get();

        $processed = 0;

        foreach ($rows as $r) {
            $tz = $r->time_zone ?: 'UTC';

            try {
                // flimit dd/mm/YYYY -> Y-m-d
                $parts = explode('/', (string)$r->flimit);
                $flimitIso = count($parts) === 3
                    ? "{$parts[2]}-{$parts[1]}-{$parts[0]}"
                    : (new Carbon($r->flimit, $tz))->format('Y-m-d');

                $deadline = Carbon::parse($flimitIso . ' ' . $r->hlimit, $tz);
                $nowTz    = Carbon::now($tz);

                if ($nowTz->greaterThan($deadline)) {
                    // vencido → lo maneja cancelByNoConfirmation()
                    continue;
                }

                $hoursLeft = $nowTz->diffInHours($deadline);
                $daysLeft  = $nowTz->diffInDays($deadline);

            } catch (\Throwable $e) {
                Log::warning('CREATOR REMINDERS: error parseando flimit/hlimit/time_zone', [
                    'evento' => $r->id_evento,
                    'user'   => $r->id_data,
                    'flimit' => $r->flimit,
                    'hlimit' => $r->hlimit,
                    'tz'     => $tz,
                    'err'    => $e->getMessage(),
                ]);
                continue;
            }

            // Tipo + mensaje
            $type = 0; $message = '';
            if ($hoursLeft < 2) {
                $type = T::CREATOR_CONFIRMATION_REMINDER_2HOURS; // 11
                $message = '¡Tienes menos de 2 horas para confirmar la realización de tu evento!';
            } elseif ($daysLeft < 1) {
                $type = T::CREATOR_CONFIRMATION_REMINDER_1DAY;   // 10
                $message = 'Te queda un día para confirmar la realización de tu evento.';
            } elseif ($daysLeft <= 2) {
                $type = T::CREATOR_CONFIRMATION_REMINDER_2DAYS;  // 9
                $message = 'Te quedan menos de 2 días para confirmar la realización de tu evento.';
            } else {
                continue;
            }

            // Idempotencia
            $exists = DB::table('notifications')->where([
                'type'      => $type,
                'type_user' => 2, // creador
                'id_evento' => $r->id_evento,
                'id_user'   => $r->id_data,
            ])->exists();
            if ($exists) continue;

            // Payload + despacho (posicional)
            $payload = [
                'title'       => $r->titulo,
                'body'        => $message,
                'token_movil' => $r->token_movil ?: null,
                'mail'        => $r->mail ?: null,
                'data'        => [
                    'nombreCreador' => $r->nombreCreador,
                    'id_user'       => $r->id_data,
                ],
            ];

            $ok = $this->dispatcher->dispatch(
                $type,
                (int)$r->id_evento,
                (int)$r->id_data,
                $payload,
                [
                    'dry_run'   => $dry,
                    'type_user' => 2,
                ]
            );

            if ($ok) $processed++;
        }

        return $processed;
    }

    /**
     * Auto-cancel (12) — existe pero no corre si notifications.auto_cancel_enabled = false
     */
    public function cancelByNoConfirmation(bool $dry = false): int
    {
        if (!config('notifications.auto_cancel_enabled', false)) {
            return 0; // 🔒 apagado por bandera
        }

        $rows = DB::table('data as d') // creador
            ->join('eventos as e', 'e.id_user', '=', 'd.id_data')
            ->where('e.confirm', 0)
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                  ->from('cancelaciones as c')
                  ->whereRaw('c.id_evento = e.id_evento');
            })
            ->selectRaw("
                d.id_data,
                d.mail,
                d.token_movil,
                CONCAT(d.nombre, ' ', d.apellido) AS nombreCreador,
                e.id_evento,
                e.titulo,
                e.flimit,
                e.hlimit,
                e.time_zone
            ")
            ->get();

        $processed = 0;

        foreach ($rows as $r) {
            $tz = $r->time_zone ?: 'UTC';

            try {
                $parts = explode('/', (string)$r->flimit);
                $flimitIso = count($parts) === 3
                    ? "{$parts[2]}-{$parts[1]}-{$parts[0]}"
                    : (new Carbon($r->flimit, $tz))->format('Y-m-d');

                $deadline = Carbon::parse($flimitIso . ' ' . $r->hlimit, $tz);
                $nowTz    = Carbon::now($tz);

                if ($nowTz->lessThanOrEqualTo($deadline)) {
                    continue; // aún no vence
                }
            } catch (\Throwable $e) {
                Log::warning('AUTO-CANCEL: error parseando flimit/hlimit/time_zone', [
                    'evento' => $r->id_evento,
                    'user'   => $r->id_data,
                    'flimit' => $r->flimit,
                    'hlimit' => $r->hlimit,
                    'tz'     => $tz,
                    'err'    => $e->getMessage(),
                ]);
                continue;
            }

            // Idempotencia
            $exists = DB::table('notifications')->where([
                'type'      => T::CANCEL_BY_NO_CONFIRMATION,
                'type_user' => 2,
                'id_evento' => $r->id_evento,
                'id_user'   => $r->id_data,
            ])->exists();
            if ($exists) continue;

            $message = 'Tu evento ha sido cancelado automáticamente porque no lo confirmaste a tiempo.';

            $payload = [
                'title'       => $r->titulo,
                'body'        => $message,
                'token_movil' => $r->token_movil ?: null,
                'mail'        => $r->mail ?: null,
                'data'        => [
                    'nombreCreador' => $r->nombreCreador,
                    'id_user'       => $r->id_data,
                ],
            ];

            $ok = $this->dispatcher->dispatch(
                T::CANCEL_BY_NO_CONFIRMATION,
                (int)$r->id_evento,
                (int)$r->id_data,
                $payload,
                [
                    'dry_run'   => $dry,
                    'type_user' => 2,
                ]
            );

            if (!$dry) {
                DB::transaction(function () use ($r) {
                    DB::table('cancelaciones')->insert([
                        'id_evento'  => $r->id_evento,
                        'motivo'     => 'Se canceló el evento porque el creador no confirmó la realización en el tiempo establecido.',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                    DB::table('eventos')
                        ->where('id_evento', $r->id_evento)
                        ->update(['estado' => 0, 'updated_at' => now()]);

                    DB::table('notifications')->insert([
                        'type'       => T::CANCEL_BY_NO_CONFIRMATION,
                        'type_user'  => 2,
                        'id_evento'  => $r->id_evento,
                        'id_user'    => $r->id_data,
                        'created_at' => now(),
                    ]);
                });
            }

            if ($ok) $processed++;
        }

        return $processed;
    }

    // (Opcional) Wrappers para compatibilidad con firmas antiguas:
    public function confirmationReminder2Days(bool $dry = false): int  { return $this->confirmationRemindersAuto($dry); }
    public function confirmationReminder1Day(bool $dry = false): int   { return $this->confirmationRemindersAuto($dry); }
    public function confirmationReminder2Hours(bool $dry = false): int { return $this->confirmationRemindersAuto($dry); }
}
