<?php

declare(strict_types=1);

namespace App\Services;

use App\Mail\GenericNotificationMail;
use Illuminate\Support\Facades\Mail;

final class MailService
{
    public function sendFormatted(string $to, int $type, string $subject, string $body, array $data = []): bool
    {
        if (!$to) {
            return false;
        }

        try {
            Mail::to($to)->send(new GenericNotificationMail($subject, $body, $data + ['type' => $type]));
            return true;
        } catch (\Throwable $e) {
            report($e);
            return false;
        }
    }
}
