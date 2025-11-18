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
     * @param string|null $actionUrl   URL del botón CTA
     * @param string|null $actionText  Texto del botón CTA
     * @param string|null $preheader   Texto breve para previsualización
     */
    public function __construct(
        string $subject,
        string $bodyHtml,
        ?string $actionUrl = null,
        ?string $actionText = null,
        ?string $preheader = null
    ) {
        $this->subjectLine = $subject !== '' ? $subject : 'Notificación';
        $this->bodyHtml    = $bodyHtml;
        $this->actionUrl   = $actionUrl;
        $this->actionText  = $actionText ?: 'Ver detalle';
        $this->preheader   = $preheader ?: strip_tags($bodyHtml);
        // Si quieres, limita el preheader a 120 chars:
        if (mb_strlen($this->preheader) > 120) {
            $this->preheader = mb_substr($this->preheader, 0, 117) . '...';
        }
    }

    public function build()
    {
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
