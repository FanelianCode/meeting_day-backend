<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Mail\GenericNotificationMail;
use App\Services\PushService;
use App\Models\Notification as NotificationModel;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

final class NotificationDispatcher
{
    /** @var PushService */
    private $push;

    public function __construct(PushService $push)
    {
        $this->push = $push;
    }

    /**
     * @param int   $type
     * @param int   $idEvento
     * @param int   $idUser
     * @param array $payload [
     *   'title','body','push_title','push_body','mail_subject','mail_body',
     *   'token_movil','mail','data','action_url','action_text'
     * ]
     * @param array $options [
     *   'dry_run' => bool,
     *   'channels' => ['push'=>bool,'mail'=>bool],
     *   'type_user'=>int
     * ]
     */
    public function dispatch(int $type, int $idEvento, int $idUser, array $payload, array $options = []): bool
    {
        Log::info('📬 NotificationDispatcher::dispatch - ENTRY', [
            'type' => $type,
            'idEvento' => $idEvento,
            'idUser' => $idUser,
            'payload_keys' => array_keys($payload),
            'has_mail' => isset($payload['mail']) ? 'YES' : 'NO',
            'mail_subject_raw' => substr($payload['mail_subject'] ?? '', 0, 100),
            'mail_body_length' => strlen($payload['mail_body'] ?? ''),
        ]);

        $dryRun = (bool)($options['dry_run'] ?? false);

        // Matriz de canales (config + override)
        $matrixConfig   = config("notifications.$type", ['push' => true, 'mail' => true]);
        $matrixOverride = (array)($options['channels'] ?? []);
        $matrix         = array_merge(
            ['push' => true, 'mail' => true],
            is_array($matrixConfig) ? $matrixConfig : [],
            $matrixOverride
        );

        $sendPush = (bool)($matrix['push'] ?? true);
        $sendMail = (bool)($matrix['mail'] ?? true);

        // ----- Campos formateados y reparados (anti-mojibake) -----
        $pushTitle = $this->fixAndNormalize((string)($payload['push_title']   ?? $payload['title'] ?? 'Notificación'));
        $pushBody  = $this->fixAndNormalize((string)($payload['push_body']    ?? $payload['body']  ?? ''));
        $mailSubj  = $this->fixAndNormalize((string)($payload['mail_subject'] ?? $payload['title'] ?? 'Notificación'));
        $mailBody  = $this->fixAndNormalize((string)($payload['mail_body']    ?? $payload['body']  ?? ''));

        $tokenMovil = $payload['token_movil'] ?? null;
        $email      = $payload['mail'] ?? null;
        $dataRaw    = (array)($payload['data'] ?? []);
        $actionUrl  = Arr::get($payload, 'action_url');
        $actionText = Arr::get($payload, 'action_text');

        $pushOk = true;
        $mailOk = true;

        // ---------- MAIL ----------
        if ($sendMail) {
            if (empty($email)) {
                Log::warning('MAIL skipped: empty email', compact('type', 'idEvento', 'idUser'));
            } elseif (!filter_var((string)$email, FILTER_VALIDATE_EMAIL)) {
                Log::warning('MAIL skipped: invalid email format', compact('type', 'idEvento', 'idUser', 'email'));
            } else {
                if ($dryRun) {
                    Log::info('[DRY-RUN] MAIL', [
                        'type'    => $type,
                        'idEvento'=> $idEvento,
                        'idUser'  => $idUser,
                        'email'   => $email,
                        'subject' => $mailSubj,
                    ]);
                } else {
                    try {
                        $actionUrlSafe  = is_string($actionUrl) ? $actionUrl : null;
                        $actionTextSafe = is_string($actionText) ? $this->fixAndNormalize($actionText) : null;

                        Log::debug('📧 Before Mail::send()', [
                            'email' => $email,
                            'subject' => $mailSubj,
                            'body_length' => strlen($mailBody),
                            'driver' => config('mail.driver'),
                            'has_action_url' => !empty($actionUrlSafe),
                        ]);

                        Mail::to((string)$email)->send(
                            new GenericNotificationMail(
                                $mailSubj,
                                $mailBody,
                                $actionUrlSafe,
                                $actionTextSafe
                            )
                        );

                        Log::info('✅ MAIL queued successfully', [
                            'type' => $type,
                            'idEvento' => $idEvento,
                            'idUser' => $idUser,
                            'email' => $email,
                            'subject_length' => strlen($mailSubj),
                            'body_length' => strlen($mailBody),
                        ]);
                    } catch (\Throwable $e) {
                        $mailOk = false;
                        Log::error('❌ MAIL exception - COMPLETE TRACE', [
                            'type' => $type,
                            'idEvento' => $idEvento,
                            'idUser' => $idUser,
                            'email' => $email,
                            'exception_class' => get_class($e),
                            'exception_message' => $e->getMessage(),
                            'exception_file' => $e->getFile(),
                            'exception_line' => $e->getLine(),
                            'exception_trace' => $e->getTraceAsString(),
                        ]);
                    }
                }
            }
        }

        // ========== PERSISTENCIA PREVIA (para cálculo correcto del badge) ==========

        // Creamos registro de notificación no leída (si no es dry-run)
        if (!$dryRun) {
            try {
                NotificationModel::create([
                    'type'       => $type,
                    'type_user'  => (int)($options['type_user'] ?? 1),
                    'id_evento'  => $idEvento,
                    'id_user'    => $idUser,
                    'is_read'    => 0,
                    'created_at' => now(),
                ]);
            } catch (\Throwable $e) {
                // Si tienes índice único para evitar duplicados, ignora violaciones de unique
                if (stripos($e->getMessage(), 'duplicate') === false) {
                    report($e);
                }
            }
        }

        // Ahora calculamos el badge REAL desde BD:
        // incluye la notificación recién creada (si no es dry-run)
        $badge = $this->getUnreadCountForUser($idUser);

        // Construimos data para FCM/APNs con el badge incluido
        $dataForPush = $this->buildPushData($type, $idEvento, $idUser, $dataRaw);
        $dataForPush['badge'] = (string)$badge;

        // ========== PUSH ==========

        if ($sendPush) {
            if (empty($tokenMovil)) {
                Log::warning('PUSH skipped: empty token', compact('type', 'idEvento', 'idUser'));
            } else {
                if ($dryRun) {
                    Log::info('[DRY-RUN] PUSH', [
                        'type'       => $type,
                        'idEvento'   => $idEvento,
                        'idUser'     => $idUser,
                        'tokenMovil' => $tokenMovil,
                        'title'      => $pushTitle,
                        'badge'      => $badge,
                    ]);
                } else {
                    try {
                        $pushOk = $this->push->send(
                            $type,
                            $this->fixAndNormalize($pushTitle),
                            $this->fixAndNormalize($pushBody),
                            (string)$tokenMovil,
                            $dataForPush,
                            $badge
                        );

                        Log::log(
                            $pushOk ? 'info' : 'warning',
                            $pushOk ? 'PUSH sent' : 'PUSH failed',
                            [
                                'type'     => $type,
                                'idEvento' => $idEvento,
                                'idUser'   => $idUser,
                                'token'    => $tokenMovil,
                                'badge'    => $badge,
                            ]
                        );
                    } catch (\Throwable $e) {
                        $pushOk = false;
                        Log::error('PUSH exception', [
                            'type'     => $type,
                            'idEvento' => $idEvento,
                            'idUser'   => $idUser,
                            'token'    => $tokenMovil,
                            'badge'    => $badge,
                            'err'      => $e->getMessage(),
                        ]);
                    }
                }
            }
        }

        if (!($pushOk && $mailOk)) {
            return false;
        }

        return true;
    }

    /**
     * Empaqueta data para FCM:
     * - Todas las values deben ser STRING.
     * - Metadatos simples en texto.
     * - Resto del payload: JSON (UTF-8) → base64 en "payload".
     */
    private function buildPushData(int $type, int $idEvento, int $idUser, array $dataRaw): array
    {
        $clean = $this->sanitizeUtf8Rec($dataRaw);

        $json = json_encode($clean, JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            $json = json_encode(
                $this->sanitizeUtf8Rec($this->forceScalarStrings($clean)),
                JSON_UNESCAPED_UNICODE
            );
            if ($json === false) {
                $json = '{}';
            }
        }

        return [
            'type'     => (string)$type,
            'idEvento' => (string)$idEvento,
            'idUser'   => (string)$idUser,
            'payload'  => base64_encode($json),
        ];
    }

    /** Convierte escalares a string; mantiene arrays/objetos (recursivo). */
    private function forceScalarStrings($value)
    {
        if (is_array($value)) {
            $out = [];
            foreach ($value as $k => $v) {
                $kk = is_string($k) ? $k : (string)$k;
                $out[$kk] = $this->forceScalarStrings($v);
            }
            return $out;
        }
        if (is_object($value)) {
            return $this->forceScalarStrings((array)$value);
        }
        if ($value === null) {
            return '';
        }
        return is_string($value) ? $value : (string)$value;
    }

    /** Cuenta notificaciones no leídas para el usuario. */
    private function getUnreadCountForUser(int $idUser): int
    {
        return NotificationModel::where('id_user', $idUser)
            ->where('is_read', 0)
            ->count();
    }

    /**
     * Repara mojibake y normaliza a UTF-8 NFC.
     */
    private function fixAndNormalize(?string $s): string
    {
        if ($s === null || $s === '') return '';
        $s = (string)$s;

        if ($this->looksLikeMojibake($s)) {
            $decoded = @utf8_decode($s);
            $decoded = @mb_convert_encoding($decoded, 'UTF-8', 'ISO-8859-1');

            if ($this->seemsBetter($s, $decoded)) {
                $s = $decoded;
            } else {
                $alt = @iconv('UTF-8', 'ISO-8859-1//IGNORE', $s);
                if ($alt !== false && $alt !== '') {
                    $alt = @mb_convert_encoding($alt, 'UTF-8', 'ISO-8859-1');
                    if ($this->seemsBetter($s, $alt)) {
                        $s = $alt;
                    }
                }
            }
        }

        if (!mb_detect_encoding($s, 'UTF-8', true)) {
            foreach (['Windows-1252', 'ISO-8859-1', 'ASCII'] as $enc) {
                $try = @mb_convert_encoding($s, 'UTF-8', $enc);
                if ($try !== false && mb_detect_encoding($try, 'UTF-8', true)) {
                    $s = $try;
                    break;
                }
            }
        }

        return $this->normalizeUnicode($s);
    }

    private function looksLikeMojibake(string $s): bool
    {
        return (bool) preg_match('/(Ã|Â|¢|¤|¨®|�)/u', $s);
    }

    private function seemsBetter(string $a, string $b): bool
    {
        $score = function (string $x): int {
            $m = [];
            $m2 = [];
            return preg_match_all('/(Ã|Â|¢|¤|¨®|�)/u', $x, $m) * 5
                - preg_match_all('/[^\x00-\x7F]/u', $x, $m2);
        };

        return $score($b) < $score($a);
    }

    private function normalizeUnicode(string $s): string
    {
        if (class_exists('\Normalizer')) {
            return \Normalizer::normalize($s, \Normalizer::FORM_C);
        }
        return $s;
    }

    private function sanitizeUtf8Rec($v)
    {
        if (is_array($v)) {
            $out = [];
            foreach ($v as $k => $vv) {
                $kk = is_string($k) ? $this->fixAndNormalize($k) : (string)$k;
                $out[$kk] = $this->sanitizeUtf8Rec($vv);
            }
            return $out;
        }
        if (is_object($v)) {
            return $this->sanitizeUtf8Rec((array)$v);
        }
        if ($v === null) {
            return '';
        }
        return is_string($v) ? $this->fixAndNormalize($v) : (string)$v;
    }
}
