<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class GenericNotificationMail extends Mailable
{
    use Queueable, SerializesModels;

    /** @var string */
    public $subjectLine;

    /** @var string HTML ya formateado */
    public $bodyHtml;

    /** @var string|null */
    public $actionUrl;

    /** @var string|null */
    public $actionText;

    /** @var string|null Texto preheader (opcional, para inbox preview) */
    public $preheader;

    /**
     * @param string      $subject     Asunto del correo (formateado)
     * @param string      $bodyHtml    Cuerpo del correo en HTML (NO escapado)
     * @param string|null $actionUrl   URL del bot�n CTA
     * @param string|null $actionText  Texto del bot�n CTA
     * @param string|null $preheader   Texto breve para previsualizaci�n
     */
    public function __construct(
        string $subject,
        string $bodyHtml,
        ?string $actionUrl = null,
        ?string $actionText = null,
        ?string $preheader = null
    ) {
        $this->subjectLine = $subject !== '' ? $subject : 'Notificaci�n';
        $this->bodyHtml    = $bodyHtml;
        $this->actionUrl   = $actionUrl;
        $this->actionText  = $actionText ?: 'Ver detalle';
        $this->preheader   = $preheader ?: strip_tags($bodyHtml);
        // Si quieres, limita el preheader a 120 chars:
        if (mb_strlen($this->preheader) > 120) {
            $this->preheader = mb_substr($this->preheader, 0, 117) . '...';
        }
        \Illuminate\Support\Facades\Log::debug('🎨 GenericNotificationMail::__construct', [
            'subject_length' => strlen($this->subjectLine),
            'body_length' => strlen($this->bodyHtml),
            'preheader_length' => strlen($this->preheader),
            'has_action_url' => !empty($this->actionUrl),
            'action_text' => substr($this->actionText, 0, 50),
        ]);    }

    public function build()
    {
        \Illuminate\Support\Facades\Log::debug('🔨 GenericNotificationMail::build - TEMPLATE RENDER', [
            'template' => 'mail.generic-notification',
            'subject' => substr($this->subjectLine, 0, 100),
            'body_length' => strlen($this->bodyHtml),
            'action_url' => $this->actionUrl ? substr($this->actionUrl, 0, 100) : null,
            'action_text' => $this->actionText,
            'preheader' => substr($this->preheader, 0, 100),
        ]);

        // SUBJECT + VIEW (Blade)
        return $this
            ->subject($this->subjectLine)
            ->view('mail.generic-notification', [
                'subject'    => $this->subjectLine,
                'body_html'  => $this->bodyHtml,    // Se renderiza con {!! !!}
                'action_url' => $this->actionUrl,
                'action_text'=> $this->actionText,
                'preheader'  => $this->preheader,
            ]);
    }
}
