<?php

namespace App\Services\Notifications\Handlers;

use App\Enums\NotificationType as T;
use App\Services\Notifications\NotificationDispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Carbon;

class GuestNotifications
{
    protected $dispatcher;

    public function __construct(NotificationDispatcher $dispatcher)
    {
        $this->dispatcher = $dispatcher;
    }

    /** (1) INVITE_EVENT */
    public function inviteEvent(bool $dry = false): int
    {
        $rows = DB::table('data as d')
            ->join('invitados as i', 'i.id_user', '=', 'd.id_data')
            ->join('eventos as e', 'e.id_evento', '=', 'i.id_evento')
            ->leftJoin('cancelaciones as c', 'c.id_evento', '=', 'i.id_evento')
            ->leftJoin('data as d2', 'd2.id_data', '=', 'e.id_user')
            ->whereNull('c.id_evento')
            ->selectRaw("
                d.id_data,
                d.mail,
                d.token_movil,
                CONCAT(d.nombre, ' ', d.apellido) as nombreInvitado,
                i.id_evento,
                e.titulo,
                e.meeting,
                e.tipo,
                e.id_user as creador_id,
                CONCAT(d2.nombre, ' ', d2.apellido) as nombreCreador
            ")
            ->get();

        $processed = 0;

        foreach ($rows as $r) {
            $exists = DB::table('notifications')->where([
                'type'      => T::INVITE_EVENT,
                'type_user' => 1,
                'id_evento' => $r->id_evento,
                'id_user'   => $r->id_data,
            ])->exists();
            if ($exists) continue;

            $a = [
                'nombreInvitado' => $r->nombreInvitado,
                'nombreCreador'  => $r->nombreCreador,
                'id_user'        => $r->id_data,
            ];

            if (!empty($r->meeting) && (int)$r->meeting !== 0) {
                $a['tipo'] = 1;
                $gps = DB::table('gps')
                    ->select('location', 'place', 'fecha', 'hora')
                    ->where('id_gps', (int)$r->meeting)
                    ->first();
                if ($gps) {
                    $a['location'] = $gps->location;
                    $a['place']    = $gps->place;
                    $a['fecha']    = $gps->fecha;
                    $a['hora']     = $gps->hora;
                }
            } elseif ((int)$r->tipo === 2 && (int)$r->meeting === 0) {
                $a['tipo'] = 2;
                $a['propuestas'] = 'Este evento tiene más de una propuesta, entra en Meetingday para votar por la que más te guste.';
            }

            $fmt = $this->formatPushAndMail(T::INVITE_EVENT, (string)$r->titulo, '', $a);

            $payload = [
                'title'        => $fmt['push_title'],
                'body'         => $fmt['push_body'],
                'push_title'   => $fmt['push_title'],
                'push_body'    => $fmt['push_body'],
                'mail_subject' => $fmt['mail_subject'],
                'mail_body'    => $fmt['mail_body'],
                'token_movil'  => $r->token_movil ?: null,
                'mail'         => $r->mail ?: null,
                'data'         => $a,
                'action_url'   => null,
                'action_text'  => null,
            ];

            $ok = $this->dispatcher->dispatch(T::INVITE_EVENT, (int)$r->id_evento, (int)$r->id_data, $payload, [
                'dry_run' => $dry, 'type_user' => 1
            ]);

            if ($ok && !$dry) $this->insertNotificationSafe(T::INVITE_EVENT, 1, (int)$r->id_evento, (int)$r->id_data);
            if ($ok) $processed++;
        }

        return $processed;
    }

    /** (2) CANCEL_EVENT */
    public function cancelEvent(bool $dry = false): int
    {
        $rows = DB::table('data as d')
        ->join('invitados as i', 'i.id_user', '=', 'd.id_data')
        ->join('cancelaciones as c', 'c.id_evento', '=', 'i.id_evento')
        ->join('eventos as e', 'e.id_evento', '=', 'i.id_evento')
        ->leftJoin('data as d2', 'd2.id_data', '=', 'e.id_user')
        ->where('i.confirm', 2) // <-- solo confirmados (=2)
        ->selectRaw("
            d.id_data,
            d.mail,
            d.token_movil,
            CONCAT(d.nombre, ' ', d.apellido) as nombreInvitado,
            i.id_evento,
            c.motivo,
            e.titulo,
            e.id_user as creador_id,
            CONCAT(d2.nombre, ' ', d2.apellido) as nombreCreador
        ")
        ->get();

        $processed = 0;

        foreach ($rows as $r) {
            $exists = DB::table('notifications')->where([
                'type'      => T::CANCEL_EVENT,
                'type_user' => 1,
                'id_evento' => $r->id_evento,
                'id_user'   => $r->id_data,
            ])->exists();
            if ($exists) continue;

            $a = [
                'nombreInvitado' => $r->nombreInvitado,
                'nombreCreador'  => $r->nombreCreador,
                'id_user'        => $r->id_data,
                'motivo'         => (string)($r->motivo ?? ''),
            ];

            $fmt = $this->formatPushAndMail(T::CANCEL_EVENT, (string)$r->titulo, $a['motivo'], $a);

            $payload = [
                'title'        => $fmt['push_title'],
                'body'         => $fmt['push_body'],
                'push_title'   => $fmt['push_title'],
                'push_body'    => $fmt['push_body'],
                'mail_subject' => $fmt['mail_subject'],
                'mail_body'    => $fmt['mail_body'],
                'token_movil'  => $r->token_movil ?: null,
                'mail'         => $r->mail ?: null,
                'data'         => $a,
                'action_url'   => null,
                'action_text'  => null,
            ];

            $ok = $this->dispatcher->dispatch(T::CANCEL_EVENT, (int)$r->id_evento, (int)$r->id_data, $payload, [
                'dry_run' => $dry, 'type_user' => 1
            ]);

            if ($ok && !$dry) $this->insertNotificationSafe(T::CANCEL_EVENT, 1, (int)$r->id_evento, (int)$r->id_data);
            if ($ok) $processed++;
        }

        return $processed;
    }

    /** (3) LOCATION_CONFIRMATION */
    public function locationConfirmation(bool $dry = false): int
    {
        $rows = DB::table('data as d')
            ->join('invitados as i', 'i.id_user', '=', 'd.id_data')
            ->join('eventos as e', 'e.id_evento', '=', 'i.id_evento')
            ->leftJoin('cancelaciones as c', 'c.id_evento', '=', 'i.id_evento')
            ->join('gps as g', 'g.id_evento', '=', 'e.id_evento')
            ->leftJoin('data as d2', 'd2.id_data', '=', 'e.id_user')
            ->whereNull('c.id_evento')
            ->where('e.tipo', 2)->where('e.meeting', 0)->where('g.marker', 1)
            ->selectRaw("
                d.id_data,
                d.mail,
                d.token_movil,
                CONCAT(d.nombre, ' ', d.apellido) as nombreInvitado,
                i.id_evento,
                e.titulo,
                e.flimit, e.hlimit, e.time_zone,
                e.id_user as creador_id,
                g.location, g.place, g.fecha, g.hora,
                CONCAT(d2.nombre, ' ', d2.apellido) as nombreCreador
            ")
            ->get();

        $processed = 0;

        foreach ($rows as $r) {
            // deadline
            $tz = $r->time_zone ?: 'UTC';
            try {
                $parts = explode('/', (string)$r->flimit);
                $flimitIso = count($parts) === 3 ? "{$parts[2]}-{$parts[1]}-{$parts[0]}" : (new Carbon($r->flimit, $tz))->format('Y-m-d');
                $deadline = Carbon::parse($flimitIso . ' ' . $r->hlimit, $tz);
                if (Carbon::now($tz)->greaterThan($deadline)) continue;
            } catch (\Throwable $e) {
                Log::warning('LOCATION_CONFIRMATION parse error', ['evento' => $r->id_evento, 'user' => $r->id_data, 'err' => $e->getMessage()]);
                continue;
            }

            $exists = DB::table('notifications')->where([
                'type' => T::LOCATION_CONFIRMATION, 'type_user' => 1,
                'id_evento' => $r->id_evento, 'id_user' => $r->id_data,
            ])->exists();
            if ($exists) continue;

            $a = [
                'nombreInvitado' => $r->nombreInvitado,
                'nombreCreador'  => $r->nombreCreador,
                'id_user'        => $r->id_data,
                'location'       => $r->location,
                'place'          => $r->place,
                'fecha'          => $r->fecha,
                'hora'           => $r->hora,
            ];

            $fmt = $this->formatPushAndMail(T::LOCATION_CONFIRMATION, (string)$r->titulo, '', $a);

            $payload = [
                'title'        => $fmt['push_title'],
                'body'         => $fmt['push_body'],
                'push_title'   => $fmt['push_title'],
                'push_body'    => $fmt['push_body'],
                'mail_subject' => $fmt['mail_subject'],
                'mail_body'    => $fmt['mail_body'],
                'token_movil'  => $r->token_movil ?: null,
                'mail'         => $r->mail ?: null,
                'data'         => $a,
                'action_url'   => null,
                'action_text'  => null,
            ];

            $ok = $this->dispatcher->dispatch(T::LOCATION_CONFIRMATION, (int)$r->id_evento, (int)$r->id_data, $payload, [
                'dry_run' => $dry, 'type_user' => 1
            ]);

            if ($ok && !$dry) $this->insertNotificationSafe(T::LOCATION_CONFIRMATION, 1, (int)$r->id_evento, (int)$r->id_data);
            if ($ok) $processed++;
        }

        return $processed;
    }

    /** (4,5,6) ATTENDANCE_REMINDER_* (auto) */
    public function attendanceRemindersAuto(bool $dry = false): int
    {
        $rows = DB::table('data as d')
            ->join('invitados as i', 'i.id_user', '=', 'd.id_data')
            ->join('eventos as e', 'e.id_evento', '=', 'i.id_evento')
            ->leftJoin('cancelaciones as c', 'c.id_evento', '=', 'i.id_evento')
            ->leftJoin('data as d2', 'd2.id_data', '=', 'e.id_user')
            ->whereNull('c.id_evento')->where('i.confirm', 0)
            ->selectRaw("
                d.id_data, d.mail, d.token_movil,
                CONCAT(d.nombre, ' ', d.apellido) as nombreInvitado,
                i.id_evento, e.titulo, e.flimit, e.hlimit, e.time_zone,
                e.id_user as creador_id, CONCAT(d2.nombre, ' ', d2.apellido) as nombreCreador
            ")
            ->get();

        $processed = 0;

        foreach ($rows as $r) {
            $tz = $r->time_zone ?: 'UTC';
            try {
                $parts = explode('/', (string)$r->flimit);
                $flimitIso = count($parts) === 3 ? "{$parts[2]}-{$parts[1]}-{$parts[0]}" : (new Carbon($r->flimit, $tz))->format('Y-m-d');
                $deadline = Carbon::parse($flimitIso . ' ' . $r->hlimit, $tz);
                $nowTz    = Carbon::now($tz);
                if ($nowTz->greaterThan($deadline)) continue;

                $hoursLeft = $nowTz->diffInHours($deadline);
                $daysLeft  = $nowTz->diffInDays($deadline);
            } catch (\Throwable $e) {
                Log::warning('ATTENDANCE_REMINDERS parse error', ['evento' => $r->id_evento, 'user' => $r->id_data, 'err' => $e->getMessage()]);
                continue;
            }

            $type = 0;
            if ($hoursLeft < 2)       $type = T::ATTENDANCE_REMINDER_2HOURS;
            elseif ($daysLeft < 1)    $type = T::ATTENDANCE_REMINDER_1DAY;
            elseif ($daysLeft <= 2)   $type = T::ATTENDANCE_REMINDER_2DAYS;
            else continue;

            $exists = DB::table('notifications')->where([
                'type' => $type, 'type_user' => 1,
                'id_evento' => $r->id_evento, 'id_user' => $r->id_data,
            ])->exists();
            if ($exists) continue;

            $a = [
                'nombreInvitado' => $r->nombreInvitado,
                'nombreCreador'  => $r->nombreCreador,
                'id_user'        => $r->id_data,
            ];

            $fmt = $this->formatPushAndMail($type, (string)$r->titulo, '', $a);

            $payload = [
                'title'        => $fmt['push_title'],
                'body'         => $fmt['push_body'],
                'push_title'   => $fmt['push_title'],
                'push_body'    => $fmt['push_body'],
                'mail_subject' => $fmt['mail_subject'],
                'mail_body'    => $fmt['mail_body'],
                'token_movil'  => $r->token_movil ?: null,
                'mail'         => $r->mail ?: null,
                'data'         => $a,
                'action_url'   => null,
                'action_text'  => null,
            ];

            $ok = $this->dispatcher->dispatch($type, (int)$r->id_evento, (int)$r->id_data, $payload, [
                'dry_run' => $dry, 'type_user' => 1
            ]);

            if ($ok && !$dry) $this->insertNotificationSafe($type, 1, (int)$r->id_evento, (int)$r->id_data);
            if ($ok) $processed++;
        }

        return $processed;
    }

    /** (7) ATTENDANCE_CONFIRMED */
    public function attendanceConfirmed(bool $dry = false): int
    {
        $rows = DB::table('data as d')
            ->join('invitados as i', 'i.id_user', '=', 'd.id_data')
            ->join('eventos as e', 'e.id_evento', '=', 'i.id_evento')
            ->leftJoin('cancelaciones as c', function ($q) { $q->on('c.id_evento', '=', 'i.id_evento'); })
            ->leftJoin('data as d2', 'd2.id_data', '=', 'e.id_user')
            ->whereNull('c.id_evento')->where('i.confirm', 2)
            ->selectRaw("
                d.id_data, d.mail, d.token_movil,
                CONCAT(d.nombre, ' ', d.apellido) as nombreInvitado,
                i.id_evento, e.titulo, e.flimit, e.hlimit, e.time_zone,
                CONCAT(d2.nombre, ' ', d2.apellido) as nombreCreador
            ")
            ->get();

        $processed = 0;

        foreach ($rows as $r) {
            // deadline
            $tz = $r->time_zone ?: 'UTC';
            try {
                $parts = explode('/', (string)$r->flimit);
                $flimitIso = count($parts) === 3 ? "{$parts[2]}-{$parts[1]}-{$parts[0]}" : (new Carbon($r->flimit, $tz))->format('Y-m-d');
                $deadline = Carbon::parse($flimitIso . ' ' . $r->hlimit, $tz);
                if (Carbon::now($tz)->greaterThan($deadline)) continue;
            } catch (\Throwable $e) {
                Log::warning('ATTENDANCE_CONFIRMED parse error', ['evento' => $r->id_evento, 'user' => $r->id_data, 'err' => $e->getMessage()]);
                continue;
            }

            $exists = DB::table('notifications')->where([
                'type' => T::ATTENDANCE_CONFIRMED, 'type_user' => 1,
                'id_evento' => $r->id_evento, 'id_user' => $r->id_data,
            ])->exists();
            if ($exists) continue;

            $a = [
                'nombreInvitado' => $r->nombreInvitado,
                'nombreCreador'  => $r->nombreCreador,
                'id_user'        => $r->id_data,
            ];

            $fmt = $this->formatPushAndMail(T::ATTENDANCE_CONFIRMED, (string)$r->titulo, '', $a);

            $payload = [
                'title'        => $fmt['push_title'],
                'body'         => $fmt['push_body'],
                'push_title'   => $fmt['push_title'],
                'push_body'    => $fmt['push_body'],
                'mail_subject' => $fmt['mail_subject'],
                'mail_body'    => $fmt['mail_body'],
                'token_movil'  => $r->token_movil ?: null,
                'mail'         => $r->mail ?: null,
                'data'         => $a,
                'action_url'   => null,
                'action_text'  => null,
            ];

            $ok = $this->dispatcher->dispatch(T::ATTENDANCE_CONFIRMED, (int)$r->id_evento, (int)$r->id_data, $payload, [
                'dry_run' => $dry, 'type_user' => 1
            ]);

            if ($ok && !$dry) $this->insertNotificationSafe(T::ATTENDANCE_CONFIRMED, 1, (int)$r->id_evento, (int)$r->id_data);
            if ($ok) $processed++;
        }

        return $processed;
    }

    /** helpers */

    private function formatPushAndMail(int $type, string $title, string $message, array $a): array
    {
        $pushTitle = $title; $pushBody = $message;
        $mailSubject = $title; $mailBody = '';

        switch ($type) {
            case T::INVITE_EVENT: {
                $pushTitle   = "📨 Tienes una invitación";
                $pushBody    = ($a['nombreCreador'] ?? 'Alguien') . " te ha invitado al evento '{$title}'";
                $mailSubject = "Invitación al evento: {$title}";

                $chunks = [];
                $chunks[] = "Hola " . e($a['nombreInvitado'] ?? 'invitado') . ",";
                $chunks[] = e($a['nombreCreador'] ?? 'El creador') . " te ha invitado al evento <strong>" . e($title) . "</strong>.";
                if (($a['tipo'] ?? null) === 2 && !empty($a['propuestas'])) {
                    $chunks[] = e($a['propuestas']);
                }
                $loc = $this->locationHtml($a);
                if ($loc !== '') $chunks[] = $loc;
                $chunks[] = "¡Te esperamos!";

                $mailBody = '<p>' . implode('</p><p>', $chunks) . '</p>';
                break;
            }

            case T::CANCEL_EVENT: {
                $motivo     = $this->safeReason($message);
                $pushTitle  = "❌ Evento Cancelado";
                $pushBody   = ($a['nombreCreador'] ?? 'El creador') . " ha cancelado el evento '{$title}'. Motivo: " . $motivo;
                $mailSubject= "Cancelación del evento: {$title}";

                $mailBody = sprintf(
                    '<p>Hola %s,</p><p>El evento <strong>%s</strong> fue cancelado por %s.</p><p>Motivo: %s</p><p>Gracias por tu comprensión.</p>',
                    e($a['nombreInvitado'] ?? 'invitado'),
                    e($title),
                    e($a['nombreCreador'] ?? 'el creador'),
                    e($motivo)
                );
                break;
            }

            case T::LOCATION_CONFIRMATION: {
                $pushTitle   = "✅ Evento confirmado";
                $pushBody    = ($a['nombreCreador'] ?? 'El creador') . " ha confirmado el evento para '{$title}'";
                $mailSubject = "Ubicación confirmada: {$title}";

                $chunks = [];
                $chunks[] = "Hola " . e($a['nombreInvitado'] ?? 'invitado') . ",";
                $chunks[] = "La ubicación del evento <strong>" . e($title) . "</strong> ha sido confirmada.";
                $loc = $this->locationHtml($a, true);
                if ($loc !== '') $chunks[] = $loc;
                $chunks[] = "¡Nos vemos!";

                $mailBody = '<p>' . implode('</p><p>', $chunks) . '</p>';
                break;
            }

            case T::ATTENDANCE_REMINDER_2DAYS: {
                $pushTitle   = "⏰ Recordatorio de Confirmación";
                $pushBody    = "Te quedan 2 días para confirmar tu asistencia al evento '{$title}' de " . ($a['nombreCreador'] ?? 'el creador');
                $mailSubject = "Recordatorio: confirma tu asistencia (faltan 2 días)";
                $mailBody    = sprintf(
                    '<p>Hola %s,</p><p>Te quedan <strong>2 días</strong> para confirmar tu asistencia al evento <strong>%s</strong> de %s.</p><p>Ingresa a Meetingday para confirmar.</p>',
                    e($a['nombreInvitado'] ?? 'invitado'), e($title), e($a['nombreCreador'] ?? 'el creador')
                );
                break;
            }

            case T::ATTENDANCE_REMINDER_1DAY: {
                $pushTitle   = "⏰ Recordatorio de Confirmación";
                $pushBody    = "Te queda 1 día para confirmar tu asistencia al evento '{$title}' de " . ($a['nombreCreador'] ?? 'el creador');
                $mailSubject = "Recordatorio: confirma tu asistencia (último día)";
                $mailBody    = sprintf(
                    '<p>Hola %s,</p><p>Te queda <strong>1 día</strong> para confirmar tu asistencia al evento <strong>%s</strong> de %s.</p><p>Ingresa a Meetingday para confirmar.</p>',
                    e($a['nombreInvitado'] ?? 'invitado'), e($title), e($a['nombreCreador'] ?? 'el creador')
                );
                break;
            }

            case T::ATTENDANCE_REMINDER_2HOURS: {
                $pushTitle   = "⏰ Recordatorio de Confirmación";
                $pushBody    = "Te quedan 2 horas para confirmar tu asistencia al evento '{$title}' de " . ($a['nombreCreador'] ?? 'el creador');
                $mailSubject = "Recordatorio: confirma tu asistencia (faltan 2 horas)";
                $mailBody    = sprintf(
                    '<p>Hola %s,</p><p>Te quedan <strong>2 horas</strong> para confirmar tu asistencia al evento <strong>%s</strong> de %s.</p><p>Ingresa a Meetingday para confirmar.</p>',
                    e($a['nombreInvitado'] ?? 'invitado'), e($title), e($a['nombreCreador'] ?? 'el creador')
                );
                break;
            }

            case T::ATTENDANCE_CONFIRMED: {
                $pushTitle   = "✅ Asistencia Confirmada";
                $pushBody    = "Has confirmado tu asistencia al evento '{$title}' de " . ($a['nombreCreador'] ?? 'el creador');
                $mailSubject = "Asistencia confirmada: {$title}";
                $mailBody    = sprintf(
                    '<p>Hola %s,</p><p>Has confirmado tu asistencia al evento <strong>%s</strong>.</p><p>¡Gracias por confirmar!</p>',
                    e($a['nombreInvitado'] ?? 'invitado'), e($title)
                );
                break;
            }

            case T::EVENT_START_REMINDER_1HOUR: {
                $pushTitle   = "🕐 Evento Iniciando Pronto";
                $pushBody    = "El evento '{$title}' de " . ($a['nombreCreador'] ?? 'el creador') . " inicia en 1 hora";
                $mailSubject = "Tu evento inicia en 1 hora: {$title}";

                $chunks = [];
                $chunks[] = "Hola " . e($a['nombreInvitado'] ?? 'invitado') . ",";
                $chunks[] = "El evento <strong>" . e($title) . "</strong> inicia en <strong>1 hora</strong>.";
                $loc = $this->locationHtml($a, true);
                if ($loc !== '') $chunks[] = $loc;
                $chunks[] = "¡Prepárate!";

                $mailBody = '<p>' . implode('</p><p>', $chunks) . '</p>';
                break;
            }

            default: {
                $pushTitle   = $title;
                $pushBody    = $message ?: 'Tienes una notificación';
                $mailSubject = $title;
                $mailBody    = '<p>' . nl2br(e($message ?: 'Tienes una notificación.')) . '</p>';
            }
        }

        return [
            'push_title'   => $pushTitle,
            'push_body'    => $pushBody,
            'mail_subject' => $mailSubject,
            'mail_body'    => $mailBody,
        ];
    }

    private function safeReason(string $message): string
    {
        $m = trim($message);
        return $m === '' ? 'El usuario no colocó motivo alguno.' : $m;
    }

    private function locationHtml(array $a, bool $includeLabels = false): string
    {
        $loc = [];

        if (!empty($a['place']))    $loc[] = ($includeLabels ? '<strong>Lugar:</strong> ' : '') . e($a['place']);
        if (!empty($a['location'])) $loc[] = ($includeLabels ? '<strong>Link/Ubicación:</strong> ' : '') . e($a['location']);
        if (!empty($a['fecha']))    $loc[] = ($includeLabels ? '<strong>Fecha:</strong> ' : '') . e($a['fecha']);
        if (!empty($a['hora']))     $loc[] = ($includeLabels ? '<strong>Hora:</strong> ' : '') . e($a['hora']);

        return !empty($loc) ? implode('<br>', $loc) : '';
    }

    private function insertNotificationSafe(int $type, int $typeUser, int $idEvento, int $idUser): void
    {
        try {
            DB::table('notifications')->insert([
                'type'       => $type,
                'type_user'  => $typeUser,
                'id_evento'  => $idEvento,
                'id_user'    => $idUser,
                'created_at' => Carbon::now(),
            ]);
        } catch (\Throwable $e) {
            if (stripos((string)$e->getMessage(), 'duplicate') === false) {
                Log::warning('insertNotificationSafe failed', [
                    'err'       => $e->getMessage(),
                    'type'      => $type,
                    'type_user' => $typeUser,
                    'evento'    => $idEvento,
                    'user'      => $idUser,
                ]);
            }
        }
    }
}
