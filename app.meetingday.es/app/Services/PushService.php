<?php

declare(strict_types=1);

namespace App\Services;

use Kreait\Firebase\Contract\Messaging;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\Notification as FcmNotification;
use Kreait\Firebase\Messaging\ApnsConfig;
use Illuminate\Support\Facades\Log;

class PushService
{
    /**
     * @var Messaging
     */
    private $messaging;

    /**
     * PushService constructor.
     *
     * @param Messaging $messaging
     */
    public function __construct(Messaging $messaging)
    {
        $this->messaging = $messaging;
    }

    /**
     * Envía una notificación push.
     *
     * @param int         $type    Tipo interno de notificación
     * @param string      $title   Título visible
     * @param string      $body    Cuerpo visible
     * @param string      $token   Token FCM del dispositivo
     * @param array       $data    Payload de datos (solo strings)
     * @param int|null    $badge   Valor de badge para iOS (null = sin tocar)
     *
     * @return bool
     */
    public function send(
        int $type,
        string $title,
        string $body,
        string $token,
        array $data = [],
        ?int $badge = null
    ): bool {
        // Normalizar data (FCM exige strings)
        $normalizedData = [];
        foreach ($data as $k => $v) {
            $key = (string) $k;

            if (is_scalar($v) || $v === null) {
                $normalizedData[$key] = (string) $v;
            } else {
                $normalizedData[$key] = json_encode($v, JSON_UNESCAPED_UNICODE);
            }
        }

        $notification = FcmNotification::create($title, $body);

        $message = CloudMessage::withTarget('token', $token)
            ->withNotification($notification)
            ->withData($normalizedData);

        // Config específica para iOS (APNs) con badge
        if ($badge !== null) {
            $badge = max(0, (int) $badge);

            $apnsConfig = ApnsConfig::new()
                ->withBadge($badge)
                ->withSound('default');

            $message = $message->withApnsConfig($apnsConfig);
        }

        try {
            $this->messaging->send($message);

            Log::info('PUSH sent', [
                'type'  => $type,
                'token' => $token,
                'badge' => $badge,
            ]);

            return true;
        } catch (\Throwable $e) {
            Log::error('PUSH send failed', [
                'type'  => $type,
                'token' => $token,
                'badge' => $badge,
                'err'   => $e->getMessage(),
            ]);

            return false;
        }
    }
}
