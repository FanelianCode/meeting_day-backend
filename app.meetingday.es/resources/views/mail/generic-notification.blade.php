@php
    /** @var string $subject */
    /** @var string $body_html  HTML confiable ya formateado (NO escapar) */
    /** @var string|null $action_url */
    /** @var string|null $action_text */
    /** @var string|null $preheader */
@endphp
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title>{{ $subject }}</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <!-- Preheader (oculto, ayuda al preview en inbox) -->
  <style>
    .preheader { display:none !important; visibility:hidden; opacity:0; color:transparent; height:0; width:0; overflow:hidden; mso-hide:all; }
    body { margin:0; padding:0; background:#f5f6f8; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; }
    .wrapper { width:100%; background:#f5f6f8; padding:24px 0; }
    .container { max-width:640px; margin:0 auto; background:#ffffff; border-radius:12px; overflow:hidden; box-shadow:0 2px 8px rgba(16,24,40,.08); }
    .header { padding:20px 28px; background:#0f172a; color:#fff; }
    .brand { font-size:16px; line-height:1.2; margin:0; opacity:.9; }
    .title { font-size:20px; margin:8px 0 0; line-height:1.3; font-weight:700; }
    .content { padding:24px 28px 12px; color:#111827; font-size:15px; line-height:1.6; }
    .content p { margin:0 0 14px; }
    .btn-wrap { padding:8px 28px 28px; text-align:center; }
    .btn { display:inline-block; padding:12px 18px; border-radius:10px; text-decoration:none; background:#2563eb; color:#fff !important; font-weight:600; }
    .meta { padding:0 28px 28px; color:#6b7280; font-size:12px; line-height:1.5; }
    .footer { text-align:center; color:#94a3b8; font-size:12px; padding:18px 10px; }
    .divider { height:1px; background:#eef2f7; margin:16px 28px 0; }
    /* Dark mode tweaks (opcionales) */
    @media (prefers-color-scheme: dark) {
      body { background:#0b1020; }
      .container { background:#0f172a; color:#e5e7eb; }
      .header { background:#0b1020; }
      .content { color:#e5e7eb; }
      .meta { color:#94a3b8; }
      .divider { background:#1f2937; }
    }
  </style>
</head>
<body>
  <span class="preheader">{{ $preheader }}</span>

  <div class="wrapper">
    <div class="container">

      <div class="header">
        {{-- Marca (ajusta si tienes logo) --}}
        <p class="brand">Meetingday</p>
        <h1 class="title">{{ $subject }}</h1>
      </div>

      <div class="content">
        {{-- Render directo del HTML formateado desde el Dispatcher/Handler --}}
        {!! $body_html !!}
      </div>

      @if(!empty($action_url))
        <div class="btn-wrap">
          <a href="{{ $action_url }}" target="_blank" class="btn">
            {{ $action_text ?? 'Ver detalle' }}
          </a>
        </div>
      @endif

      <div class="divider"></div>

      <div class="meta">
        <p>Este mensaje fue enviado automáticamente por el sistema de notificaciones de Meetingday.</p>
      </div>

      <div class="footer">
        © {{ date('Y') }} Meetingday · Todos los derechos reservados
      </div>

    </div>
  </div>
</body>
</html>
