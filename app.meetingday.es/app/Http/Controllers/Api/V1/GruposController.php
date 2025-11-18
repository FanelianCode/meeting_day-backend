<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use App\Models\Data;
use App\Models\Grupo;
use App\Models\Miembro;

class GruposController extends Controller
{
    public function index(Request $request)
    {
        // Acepta ?user=<nick> (compat), y también ?nick=<nick> por si acaso
        $nick = $request->query('user') ?? $request->query('nick');

        // Si no hay nick => responde vacío (no 422)
        if (!$nick || !is_string($nick)) {
            return response()->json([
                'grupos' => [],
                'invitaciones' => []
            ]);
        }

        // 1) Buscar usuario por nick
        $usuario = Data::select('id_data')->where('nick', $nick)->first();

        // Si no existe => responde vacío
        if (!$usuario) {
            return response()->json([
                'grupos' => [],
                'invitaciones' => []
            ]);
        }

        $idData = $usuario->id_data;

        // 2) Grupos creados por el usuario
        $grupos = Grupo::where('id_user', $idData)
            ->orderByDesc('id_grupo')
            ->get();

        // 3) Invitaciones (confirm < 2)
        $invitaciones = DB::table('miembros as m')
            ->join('grupos as g', 'g.id_grupo', '=', 'm.id_grupo')
            ->join('data as d', 'd.id_data', '=', 'g.id_user') // creador
            ->where('m.id_user', $idData)
            ->where('m.confirm', '<', 2)
            ->select([
                'd.number as creador',
                'g.nombre as nombre_grupo',
                'm.confirm as confirmacion',
                'm.id_miembro as idMiembro',
                'm.id_grupo as idGrupo',
            ])
            ->get();

        return response()->json([
            'grupos'       => $grupos,
            'invitaciones' => $invitaciones,
        ]);
    }
    
    
    public function show(Request $request, $id = null)
    {
        // Acepta id por ruta o por query/body para compatibilidad
        $idGrupo = $id ?? $request->query('id') ?? $request->input('id');
    
        if (!is_numeric($idGrupo) || (int)$idGrupo <= 0) {
            return response()->json(['error' => 'ID inválido'], 422);
        }
        $idGrupo = (int) $idGrupo;
    
        // 1) Traer el grupo
        $grupo = Grupo::select('id_grupo','id_user','nombre','img_grupo')
            ->where('id_grupo', $idGrupo)
            ->first();
    
        if (!$grupo) {
            return response()->json(['error' => 'Grupo no encontrado.'], 404);
        }
    
        // Construir URL pública de la imagen (siguiendo tu store())
        $imgFile = $grupo->img_grupo ?: 'default.webp';
        $imgUrl  = url("media/img_grupos/{$imgFile}");
    
        // 2) Traer miembros (números). Usamos LEFT JOIN para incluir "no disponibles"
        $rows = DB::table('miembros as m')
            ->leftJoin('data as d', 'd.id_data', '=', 'm.id_user')
            ->where('m.id_grupo', $idGrupo)
            ->pluck('d.number'); // puede contener null si no hay match en data
    
        // Mapear a arreglo final
        $miembros = [];
        foreach ($rows as $numero) {
            $miembros[] = $numero ?? 'Número no disponible';
        }
    
        return response()->json([
            'grupo' => [
                'id_grupo'  => (int) $grupo->id_grupo,
                'id_user'   => (int) $grupo->id_user,
                'nombre'    => (string) $grupo->nombre,
                'img_grupo' => (string) $grupo->img_grupo,
                'img_url'   => $imgUrl,
            ],
            'miembros' => $miembros, // [] si no hay
        ]);
    }

    /**
     * NUEVO: POST /api/v1/grupos/registro
     * Body JSON: { "user": "<nick>", "titulo": "Nombre del grupo", "imagen": "<base64 opcional>" }
     * - Convierte imagen a .webp
     * - Guarda archivo en storage/app/media/img_grupos
     * - En DB se guarda SOLO el nombre del archivo (img_grupo)
     */
    public function store(Request $request)
    {
        // Validación simple (sin FormRequest)
        $v = Validator::make($request->all(), [
            'user'   => ['required', 'string'],       // es NICK (no correo)
            'titulo' => ['required', 'string', 'max:150'],
            'imagen' => ['nullable', 'string'],       // base64 opcional
        ]);

        if ($v->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validación fallida.',
                'errors'  => $v->errors(),
            ], 422);
        }

        $nick         = (string) $request->input('user');
        $titulo       = (string) $request->input('titulo');
        $imagenBase64 = $request->input('imagen');

        // Resolver id_data por nick
        $idData = Data::where('nick', $nick)->value('id_data');
        if (!$idData) {
            return response()->json(['success' => false, 'message' => 'Usuario no encontrado por nick.'], 404);
        }

        // Nombre final por defecto
        $finalFilename = 'default.webp';

        // Si llega imagen: convertir a WebP usando helpers de abajo
        if (!empty($imagenBase64)) {
            if (strpos($imagenBase64, 'base64,') !== false) {
                $imagenBase64 = explode('base64,', $imagenBase64, 2)[1];
            }

            $saved = $this->saveBase64AsWebp(
                $imagenBase64,
                storage_path('app/media/img_grupos')
            );

            if ($saved === false) {
                return response()->json(['success' => false, 'message' => 'Error al convertir/guardar la imagen.'], 500);
            }

            $finalFilename = $saved; // e.g., uploaded_XXXXXXXX.webp
        }

        // Crear grupo (en transacción)
        return DB::transaction(function () use ($idData, $titulo, $finalFilename) {
            $grupo = Grupo::create([
                'id_user'   => $idData,
                'nombre'    => $titulo,
                'img_grupo' => $finalFilename,
            ]);

            return response()->json([
                'success'   => true,
                'id_grupo'  => (int) $grupo->id_grupo,
                'nombre'    => $grupo->nombre,
                'img_grupo' => $grupo->img_grupo,                           // solo nombre
                'img_url'   => url("media/img_grupos/{$grupo->img_grupo}"), // URL pública
            ], 200);
        });
    }

    /**
     * Elimina un grupo y sus dependencias:
     *  - Borra miembros del grupo
     *  - Borra la imagen física (si existe y no es la default)
     *  - Borra el grupo
     *
     * Acepta:
     *  - id_group (preferido) o id_grupo (compat)
     */
    public function destroy(Request $request)
    {
        $request->validate([
            'id_group' => 'nullable|integer|min:1',
            'id_grupo' => 'nullable|integer|min:1',
        ]);

        $idGrupo = $request->input('id_group') ?? $request->input('id_grupo');
        if (!$idGrupo) {
            return response()->json([
                'success' => false,
                'error'   => 'Parámetros insuficientes: falta id_group o id_grupo',
            ], 422);
        }

        // Buscar grupo
        $grupo = Grupo::find($idGrupo);
        if (!$grupo) {
            return response()->json([
                'success' => false,
                'error'   => 'Grupo no encontrado',
            ], 404);
        }

        DB::beginTransaction();
        try {
            // 1) Eliminar miembros asociados
            Miembro::where('id_grupo', $idGrupo)->delete();

            // 2) Eliminar imagen si existe (y no es la default)
            $img = $grupo->img_grupo ?? null;
            if ($img && strtolower($img) !== 'default.webp') {
                // Path principal (storage/app/media/img_grupos)
                $storagePath = storage_path('app/media/img_grupos/' . $img);
                if (is_file($storagePath) && file_exists($storagePath)) {
                    @unlink($storagePath);
                }

                // Fallback por si sirves desde public/media/img_grupos
                $publicPath = public_path('media/img_grupos/' . $img);
                if (is_file($publicPath) && file_exists($publicPath)) {
                    @unlink($publicPath);
                }
            }

            // 3) Eliminar el grupo
            $grupo->delete();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Grupo eliminado correctamente',
                'data'    => ['id_grupo' => (int) $idGrupo],
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'error'   => 'Error al eliminar el grupo: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * == Helpers ==
     */

    /**
     * Convierte una imagen (path fuente) a .webp (path destino) con GD.
     * Retorna true si guarda exitosamente.
     */
    private function convertToWebp(string $sourcePath, string $destPath, int $quality = 80): bool
    {
        $info = @getimagesize($sourcePath);
        if (!$info || !isset($info['mime'])) {
            $raw = @file_get_contents($sourcePath);
            if ($raw === false) return false;
            $img = @imagecreatefromstring($raw);
            if (!$img) return false;
            $ok = @imagewebp($img, $destPath, $quality);
            @imagedestroy($img);
            return (bool) $ok;
        }

        $mime = $info['mime'];
        switch ($mime) {
            case 'image/jpeg':
                $img = @imagecreatefromjpeg($sourcePath);
                break;
            case 'image/png':
                $img = @imagecreatefrompng($sourcePath);
                if ($img) {
                    @imagepalettetotruecolor($img);
                    @imagealphablending($img, true);
                    @imagesavealpha($img, true);
                }
                break;
            case 'image/gif':
                $img = @imagecreatefromgif($sourcePath);
                break;
            case 'image/webp':
                // si ya es webp, copiar tal cual
                return (bool) @copy($sourcePath, $destPath);
            default:
                $raw = @file_get_contents($sourcePath);
                if ($raw === false) return false;
                $img = @imagecreatefromstring($raw);
                break;
        }

        if (!$img) return false;

        $ok = @imagewebp($img, $destPath, $quality);
        @imagedestroy($img);
        return (bool) $ok;
    }

    /**
     * Recibe base64, crea un archivo temporal, llama a convertToWebp,
     * escribe el .webp en $targetDir con nombre único "uploaded_*.webp".
     * Retorna el nombre del archivo guardado (sin ruta) o false si falla.
     */
    private function saveBase64AsWebp(string $base64, string $targetDir, int $quality = 90)
    {
        $binary = base64_decode($base64, true);
        if ($binary === false) {
            return false;
        }

        if (!is_dir($targetDir)) {
            @mkdir($targetDir, 0775, true);
        }

        $tmpPath = tempnam(sys_get_temp_dir(), 'grp_');
        if ($tmpPath === false) {
            return false;
        }
        $wrote = @file_put_contents($tmpPath, $binary);
        if ($wrote === false) {
            @unlink($tmpPath);
            return false;
        }

        // Generar nombre único .webp que no choque con FS ni DB
        do {
            $newFileName = uniqid('uploaded_', true) . '.webp';
            $destPath    = $targetDir . DIRECTORY_SEPARATOR . $newFileName;
            $existsFs    = file_exists($destPath);
            $existsDb    = Grupo::where('img_grupo', $newFileName)->exists();
        } while ($existsFs || $existsDb);

        $ok = $this->convertToWebp($tmpPath, $destPath, $quality);

        @unlink($tmpPath);

        if (!$ok) {
            if (file_exists($destPath)) {
                @unlink($destPath);
            }
            return false;
        }

        return $newFileName;
    }
}
