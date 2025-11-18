<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\DataStoreRequest;
use App\Http\Requests\Api\V1\DataUpdateRequest;
use App\Http\Resources\Api\V1\DataResource;
use App\Models\Data;
use App\Models\Evento;
use App\Models\ImgEvento;
use App\Models\Grupo;
use App\Models\Miembro;
use App\Models\Invitado;
use App\Models\Notification;
use App\Models\Cancelaciones;
use App\Models\Votaciones;
use App\Models\Gps;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;

class DataController extends Controller
{
    public function __construct()
    {
        // $this->middleware('auth:sanctum');
    }

    // =============================
    // CREATE (body-only)
    // =============================
    public function store(DataStoreRequest $request)
    {
        $payload = $request->validated();

        if (Data::where('nick', $payload['nick'])->exists()) {
            // semántica legacy original
            return response()->json(['success' => 'so']);
        }

        $fileName = 'default.png';

        if (!empty($payload['img_profile'])) {
            $saved = $this->saveBase64Webp($payload['img_profile'], 'img_profiles');
            if ($saved === false) {
                return response()->json([
                    'success' => 'false',
                    'message' => 'Imagen base64 inválida o no soportada'
                ], 422);
            }
            $fileName = $saved;
        }

        $item = Data::create([
            'nombre'      => $payload['nombre'],
            'apellido'    => $payload['apellido'],
            'nick'        => $payload['nick'],
            'img_profile' => $fileName,
            'method'      => (int) $payload['met'],
            'indicativo'  => $payload['indi'] ,
            'number'      => $payload['num'],
            'mail'        => $payload['mail'],
            'token_movil' => $payload['token'],
        ]);

        return (new DataResource($item))
            ->additional(['success' => 'true'])
            ->response()
            ->setStatusCode(200);
    }

    // =============================
    // READ (body-only: nick + token)
    // =============================
      public function show(Request $request)
        {
            // Tomamos SOLO del body (no query). Evitamos 422: si falta, devolvemos success=false
            $nick  = $request->input('nick');
            $token = $request->input('token');
    
            if (!$nick || !$token) {
                return response()->json([
                    'success' => 'false',
                    'message' => 'No nick or token provided'
                ]);
            }
    
            // Buscar usuario por nick (solo las columnas que necesitas en la respuesta)
            $user = Data::select(
                    'id_data',
                    'nombre',
                    'apellido',
                    'nick',
                    'img_profile',
                    'method',
                    'indicativo',
                    'number',
                    'mail',
                    'token_movil'
                )
                ->where('nick', $nick)
                ->first();
    
            if (!$user) {
                // Mismo comportamiento del script original
                return response()->json(['success' => 'false']);
            }
    
            // Actualizar token_movil si es diferente
            if ($user->token_movil !== $token) {
                Data::where('id_data', $user->id_data)->update(['token_movil' => $token]);
                $user->token_movil = $token; // reflejar el cambio en la respuesta
            }
    
            return response()->json([
                'success'   => 'true',
                'user_data' => [
                    'id_data'     => $user->id_data,
                    'nombre'      => $user->nombre,
                    'apellido'    => $user->apellido,
                    'nick'        => $user->nick,
                    'img_profile' => $user->img_profile,
                    'method'      => $user->method,
                    'indicativo'  => $user->indicativo,
                    'number'      => $user->number,
                    'mail'        => $user->mail,
                    'token_movil' => $user->token_movil,
                ],
            ]);
        }
    

    // =============================
    // UPDATE (body-only)
    // =============================
    public function update(DataUpdateRequest $request)
    {
        // ✅ ID sólo desde el body
        $id = (int)($request->input('id') ?? $request->input('id_data') ?? 0);
        if ($id <= 0) {
            return response()->json([
                'success' => 'false',
                'message' => 'Falta el id de usuario'
            ], 422);
        }

        $item = Data::where('id_data', $id)->firstOrFail();
        $payload = $request->validated();

        if (array_key_exists('nombre', $payload)) {
            $item->nombre = $payload['nombre'];
        }
        if (array_key_exists('apellido', $payload)) {
            $item->apellido = $payload['apellido'];
        }

        if (array_key_exists('img_profile', $payload)) {
            $val = trim((string)$payload['img_profile']);
            if ($val !== '') {
                if ($this->looksLikeBase64($val)) {
                    $newFile = $this->saveBase64Webp($val, 'img_profiles');
                    if ($newFile === false) {
                        return response()->json([
                            'success' => 'false',
                            'message' => 'Imagen base64 inválida o no soportada'
                        ], 422);
                    }
                    $this->deleteProfileIfNotDefault($item->img_profile, 'img_profiles');
                    $item->img_profile = $newFile;
                } else {
                    if ($val === 'default.png') {
                        $this->deleteProfileIfNotDefault($item->img_profile, 'img_profiles');
                        $item->img_profile = 'default.png';
                    } else {
                        // nombre de archivo existente
                        $item->img_profile = $val;
                    }
                }
            }
        }

        $item->save();

        return (new DataResource($item))
            ->additional(['success' => 'true']);
    }
    
    
    //**--- capturamos el indicativo para su uso
    
    public function getIndicativoByNick(Request $request)
    {
        
        $nick = $request->query('user');
    
        if (!$nick) {
            return response()->json([
                'success' => 'false',
                'message' => 'Falta el parámetro nick'
            ], 422);
        }
    
        $user = Data::select('indicativo')->where('nick', $nick)->first();
    
        if (!$user) {
            return response()->json([
                'success' => 'false',
                'message' => 'Usuario no encontrado'
            ], 404);
        }
    
        return response()->json([
            'success' => 'true',
            'indicativo' => $user->indicativo ?? null
        ], 200);
    }

    // =============================
    // DELETE (body-only, cascada por software)
    // =============================
    public function destroy(Request $request)
    {
        $data = $request->validate([
            // ✅ id sólo desde el body
            'id'      => ['nullable','integer','required_without:id_data'],
            'id_data' => ['nullable','integer','required_without:id'],
        ]);

        $id = (int)($data['id'] ?? $data['id_data'] ?? 0);
        if ($id <= 0) {
            return response()->json([
                'success' => 'false',
                'message' => 'Falta el id de usuario'
            ], 422);
        }

        $user = Data::where('id_data', $id)->firstOrFail();

        try {
            DB::transaction(function () use ($user, $id) {

                /* ========================
                 * A) EVENTOS del usuario
                 * ======================== */
                $eventoIds = Evento::where('id_user', $id)->pluck('id_evento');

                if ($eventoIds->isNotEmpty()) {
                    // A.1) borrar imágenes físicas de eventos
                    $imgs = ImgEvento::whereIn('id_evento', $eventoIds)->pluck('img_url');
                    foreach ($imgs as $file) {
                        if ($file) {
                            $this->deleteMediaIfExists('img_eventos/'.$file);
                        }
                    }

                    // A.2) tablas hijas de eventos (orden recomendado)
                    Invitado::whereIn('id_evento', $eventoIds)->delete();
                    Votaciones::whereIn('id_evento', $eventoIds)->delete();
                    Notification::whereIn('id_evento', $eventoIds)->delete();
                    Cancelaciones::whereIn('id_evento', $eventoIds)->delete();
                    ImgEvento::whereIn('id_evento', $eventoIds)->delete();
                    Gps::whereIn('id_evento', $eventoIds)->delete();

                    // A.3) eventos
                    Evento::whereIn('id_evento', $eventoIds)->delete();
                }

                /* ========================
                 * B) GRUPOS del usuario
                 * ======================== */
                $grupoIds = Grupo::where('id_user', $id)->pluck('id_grupo');

                if ($grupoIds->isNotEmpty()) {
                    // B.1) miembros de esos grupos
                    Miembro::whereIn('id_grupo', $grupoIds)->delete();

                    // B.2) borrar imágenes físicas de grupos
                    $grupoImgs = Grupo::whereIn('id_grupo', $grupoIds)->pluck('img_grupo');
                    foreach ($grupoImgs as $file) {
                        if ($file) {
                            $this->deleteMediaIfExists('img_grupos/'.$file);
                        }
                    }

                    // B.3) grupos
                    Grupo::whereIn('id_grupo', $grupoIds)->delete();
                }

                /* ===================================
                 * C) RELACIONES directas del usuario
                 * =================================== */
                Miembro::where('id_user', $id)->delete();
                Invitado::where('id_user', $id)->delete();
                Notification::where('id_user', $id)->delete();

                /* ========================
                 * D) IMAGEN DE PERFIL
                 * ======================== */
                $this->deleteProfileIfNotDefault($user->img_profile, 'img_profiles');

                /* ========================
                 * E) USUARIO
                 * ======================== */
                $user->delete();
            });

            return response()->json(['success' => 'true'], 200);

        } catch (\Throwable $e) {
            // \Log::error($e);
            return response()->json([
                'success' => 'false',
                'error'   => 'Error interno al eliminar el usuario'
            ], 500);
        }
    }
    
    //---- mis eventos 
    
    public function eventosCreados(Request $request)
    {
        // Mantener el mismo mensaje que tu script PHP si no llega el parámetro
        if (!$request->has('user')) {
            return response()->json(['error' => 'No se proporcionó el usuario'], 400);
        }
    
        $nick = (string) $request->query('user');
    
        // 1) Buscar el usuario por 'nick'
        $user = DB::table('data')->select('id_data')->where('nick', $nick)->first();
        if (!$user) {
            return response()->json(['error' => 'No se encontró un usuario con ese correo']);
        }
    
        // 2) Eventos creados por el usuario: confirm = 0
        $eventos = DB::table('eventos')
            ->where('id_user', $user->id_data)
            //->where('confirm', 0)
            ->orderByDesc('id_evento')
            ->get();
    
        $records = [];
    
        foreach ($eventos as $ev) {
            // Convertimos a array para poder mutar las claves como en PHP
            $row = (array) $ev;
            $site = '';
    
            if ((int) $ev->meeting === 0) {
                // Caso 1: evento con lugares propuestos por GPS (elegido marker=1)
                $row['eleccion'] = 0;
    
                // Traer el primer GPS con marker=1 para este evento
                $gpsElegido = DB::table('gps')
                    ->where('id_evento', $ev->id_evento)
                    ->where('marker', 1)
                    ->limit(1)
                    ->get()
                    ->toArray();
    
                if (!empty($gpsElegido)) {
                    $g = (array) $gpsElegido[0];
                    $row['eleccion']   = (int) ($g['marker'] ?? 0);
                    $row['eleccionId'] = $g['id_gps'] ?? null;
                    $row['fecha']      = $g['fecha'] ?? 'Fecha por definir';
                    $row['lugar']      = $g['location'] ?? 'Lugar por definir';
                    $row['hora']       = $g['hora'] ?? 'Hora por definir';
                } else {
                    $row['fecha'] = 'Fecha por definir';
                    $row['lugar'] = 'Lugar por definir';
                    $row['hora']  = 'Hora por definir';
                }
    
                // Adjuntar detalles (igual que en tu PHP: un array, vacío o con 1 item)
                $row['gps_details'] = array_map(function ($x) {
                    return (array) $x;
                }, $gpsElegido);
            } else {
                // Caso 2: meeting != 0 -> GPS específico ya elegido
                $gpsData = DB::table('gps')->where('id_gps', $ev->meeting)->first();
                if ($gpsData) {
                    $row['lugar'] = $gpsData->location;
                    $row['fecha'] = $gpsData->fecha;
                    $row['hora']  = $gpsData->hora;
                    $site         = $gpsData->location; // se usa para decidir imagen por defecto
                }
            }
    
            // 3) Contar invitados confirmados (confirm = 2)
            $row['confirmados'] = (int) DB::table('invitados')
                ->where('id_evento', $ev->id_evento)
                ->where('confirm', 2)
                ->count();
    
            // 4) Primera imagen del evento (o fallback según $site)
            $img = DB::table('img_eventos')
                ->select('img_url')
                ->where('id_evento', $ev->id_evento)
                ->limit(1)
                ->first();
    
            if ($img) {
                $row['imagen'] = $img->img_url;
            } else {
                if ($site === 'Meet') {
                    $row['imagen'] = 'Meet.png';
                } elseif ($site === 'Zoom') {
                    $row['imagen'] = 'Zoom.png';
                } else {
                    $row['imagen'] = 'default.png';
                }
            }
    
            $records[] = $row;
        }
    
        return response()->json(['records' => $records]);
    }


    // =============================
    // Helpers
    // =============================
    private function looksLikeBase64(string $value): bool
    {
        if ($value === '') return false;
        if (stripos($value, 'data:image') === 0) return true;
        return strlen($value) > 100 && preg_match('/^[A-Za-z0-9+\/=\s\r\n]+$/', $value);
    }

    private function saveBase64Webp(string $base64, string $folder)
    {
        if (preg_match('/^data:image\/\w+;base64,/', $base64)) {
            $base64 = substr($base64, strpos($base64, ',') + 1);
        }

        $bin = base64_decode($base64, true);
        if ($bin === false) return false;

        $gd = imagecreatefromstring($bin);
        if ($gd === false) return false;

        do {
            $file = uniqid('profile_', true) . '.webp';
            $existsInDb = Data::where('img_profile', $file)->exists();
        } while ($existsInDb);

        $dest = Storage::disk('media')->path($folder . '/' . $file);

        $ok = function_exists('imagewebp')
            ? imagewebp($gd, $dest, 80)
            : imagepng($gd, preg_replace('/\.webp$/', '.png', $dest), 6);

        imagedestroy($gd);

        if (!$ok) return false;

        // si guardó como png por fallback, ajusta nombre
        if (!function_exists('imagewebp')) {
            $file = preg_replace('/\.webp$/', '.png', $file);
        }

        return $file;
    }

    private function deleteProfileIfNotDefault(?string $file, string $folder): void
    {
        if (!$file || $file === 'default.png') return;
        $path = $folder . '/' . $file;
        if (Storage::disk('media')->exists($path)) {
            Storage::disk('media')->delete($path);
        }
    }

    private function deleteMediaIfExists(string $path): void
    {
        if (Storage::disk('media')->exists($path)) {
            Storage::disk('media')->delete($path);
        }
    }
}
