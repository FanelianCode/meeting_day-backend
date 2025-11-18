<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Response;

class MediaController extends Controller
{
    public function __construct()
    {
        // 🚧 Preparado para seguridad futura:
        // $this->middleware('auth:sanctum');
    }

    /**
     * Sirve archivos de las carpetas privadas:
     * - img_eventos
     * - img_grupos
     * - img_profiles
     */
    public function show($folder, $file)
    {
        $path = $folder . '/' . $file;

        // Verificamos existencia
        if (!Storage::disk('media')->exists($path)) {
            abort(404, 'Archivo no encontrado');
        }

        // Obtenemos mime y contenido
        $mime = Storage::disk('media')->mimeType($path);
        $stream = Storage::disk('media')->readStream($path);

        // Devolvemos como stream (mejor para imágenes grandes)
        return Response::stream(function () use ($stream) {
            fpassthru($stream);
        }, 200, [
            'Content-Type' => $mime,
            'Cache-Control' => 'private, max-age=60',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
