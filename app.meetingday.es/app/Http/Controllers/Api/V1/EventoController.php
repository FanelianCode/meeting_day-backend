<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use App\Models\Data;
use App\Models\Evento;
use App\Models\Invitado;
use App\Models\Gps;
use App\Models\ImgEvento;
use App\Models\Cancelaciones;
use App\Models\Votaciones;

class EventoController extends Controller
{
    /**
     * Crea un evento (tipo 1 = virtual; tipo 2 = presencial con múltiples lugares)
     * Acepta:
     * - JSON (application/json)
     * - x-www-form-urlencoded
     * - multipart/form-data (fields + files)
     *
     * Campos clave:
     *  - idU (nick del usuario creador)
     *  - title, descrip
     *  - type (1 o 2) (string o int)
     *  - limitf, limith, timezone
     *  - contacts: array o string JSON de [{ "number": "300..." }, ...]
     *  - Si type=1: lugar, gps, fecha, hora (strings)
     *  - Si type=2: placesData: array o string JSON de [{location,gps,fecha,hora}, ...]
     *  - file[]: archivos opcionales (imágenes) en multipart
     */
    public function store(Request $request)
    {
        // 1) Normalización de entrada (para tolerar strings como en PHP puro)
        $payload = $request->all();

        // Contacts: aceptar string JSON o array
        $contacts = $payload['contacts'] ?? null;
        if (is_string($contacts)) {
            $decoded = json_decode($contacts, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $contacts = $decoded;
            }
        } elseif (!is_array($contacts)) {
            $contacts = []; // tolerante: si no viene, lo dejamos vacío
        }

        // placesData: aceptar string JSON o array
        $placesData = $payload['placesData'] ?? null;
        if (is_string($placesData)) {
            $decoded = json_decode($placesData, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $placesData = $decoded;
            }
        } elseif (!is_array($placesData) && !is_null($placesData)) {
            $placesData = null;
        }

        // type: string o int -> forzar a int
        $type = isset($payload['type']) ? (int) $payload['type'] : null;

        // 2) Validaciones mínimas (estilo PHP clásico)
        $required = ['idU', 'title', 'descrip', 'limitf', 'limith', 'timezone', 'type'];
        foreach ($required as $f) {
            if (!isset($payload[$f]) || $payload[$f] === '') {
                return response()->json(['success' => false, 'message' => "Falta el campo: {$f}"], 422);
            }
        }

        if ($type === 1) {
            foreach (['lugar','gps','fecha','hora'] as $f) {
                if (!isset($payload[$f]) || $payload[$f] === '') {
                    return response()->json(['success' => false, 'message' => "Falta el campo: {$f}"], 422);
                }
            }
        } elseif ($type === 2) {
            // placesData requerido en tipo 2
            if (empty($placesData) || !is_array($placesData)) {
                return response()->json(['success' => false, 'message' => "Falta el campo: placesData"], 422);
            }
        } else {
            return response()->json(['success' => false, 'message' => "Valor de 'type' inválido"], 422);
        }

        // 3) Buscar usuario creador por nick (idU)
        $user = Data::where('nick', $payload['idU'])->first();
        if (!$user) {
            return response()->json(['success' => false, 'message' => 'Usuario no encontrado'], 404);
        }

        DB::beginTransaction();
        try {
            // 4) Crear evento base
            $evento = Evento::create([
                'id_user'     => $user->id_data,
                'estado'      => 1,
                'titulo'      => (string) $payload['title'],
                'descripcion' => (string) $payload['descrip'],
                'tipo'        => $type,
                'meeting'     => 0,
                'flimit'      => (string) $payload['limitf'],
                'hlimit'      => (string) $payload['limith'],
                'time_zone'   => (string) $payload['timezone'],
            ]);

            // 5) Procesar invitados (si llegan)
            if (!empty($contacts) && is_array($contacts)) {
                foreach ($contacts as $c) {
                    // tolerante a claves
                    $number = $c['number'] ?? $c['num'] ?? $c['telefono'] ?? null;
                    if (!$number) {
                        continue;
                    }
                    $invitee = Data::where('number', $number)->first();
                    if ($invitee) {
                        Invitado::create([
                            'id_evento' => $evento->id_evento,
                            'id_user'   => $invitee->id_data,
                            // 'confirm' por defecto según tu schema (si aplica)
                        ]);
                    }
                }
            }

            // 6) Procesar Gps según tipo
            if ($type === 1) {
                // type 1: un único lugar y setear meeting con id_gps
                $gpsRow = Gps::create([
                    'id_evento' => $evento->id_evento,
                    'location'  => (string) $payload['lugar'],
                    'place'     => (string) $payload['gps'],
                    'fecha'     => (string) $payload['fecha'],
                    'hora'      => (string) $payload['hora'],
                ]);
                $evento->update(['meeting' => $gpsRow->id_gps]);

            } elseif ($type === 2) {
                // type 2: uno o varios lugares
                if (count($placesData) === 1) {
                    $p = $placesData[0];
                    $gpsRow = Gps::create([
                        'id_evento' => $evento->id_evento,
                        'location'  => (string) ($p['location'] ?? ''),
                        'place'     => (string) ($p['gps'] ?? ''),
                        'fecha'     => (string) ($p['fecha'] ?? ''),
                        'hora'      => (string) ($p['hora'] ?? ''),
                    ]);
                    $evento->update(['meeting' => $gpsRow->id_gps]);
                } else {
                    foreach ($placesData as $p) {
                        Gps::create([
                            'id_evento' => $evento->id_evento,
                            'location'  => (string) ($p['location'] ?? ''),
                            'place'     => (string) ($p['gps'] ?? ''),
                            'fecha'     => (string) ($p['fecha'] ?? ''),
                            'hora'      => (string) ($p['hora'] ?? ''),
                        ]);
                    }
                }
            }

            // 7) Procesar imágenes (si llegan). Guardamos como .webp en storage/app/media/img_eventos
            $savedAny = $this->handleUploadedImages($request, $evento->id_evento);

            DB::commit();

            return response()->json([
                'success'  => true,
                'message'  => 'Evento registrado exitosamente',
                'id_evento'=> $evento->id_evento,
                'imagenes' => $savedAny,
            ], 200);

        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }
    
    
    
    public function detalleEvento(Request $request, $id_evento = null, $id_invi = null)
    {
        // Acepta por ruta o por query (compat)
        $idEvento = $id_evento ?? (int) $request->query('id_evento', 0);
        $idInvi   = $id_invi   ?? (int) $request->query('id_invi', 0);
    
        if (!is_numeric($idEvento) || !is_numeric($idInvi) || (int)$idEvento <= 0 || (int)$idInvi <= 0) {
            return response()->json(['error' => 'Parámetros insuficientes o inválidos.'], 422);
        }
        $idEvento = (int) $idEvento;
        $idInvi   = (int) $idInvi;
    
        $evento = Evento::where('id_evento', $idEvento)->first();
        if (!$evento) {
            return response()->json(['error' => 'Evento no encontrado.'], 404);
        }
    
        // Base del row (similar a fetch_assoc del script)
        $row = $evento->toArray();
        $row['imagenes']     = [];
        $row['lugares']      = [];
        $row['gps_details']  = [];
        $row['confirmacion'] = [];
        $row['invitados']    = [];
        $row['eleccion']     = 0;
        $site = '';
    
        if ((int)$evento->meeting === 0) {
            // Elegido (marker=1) si existe
            $gpsChosen = Gps::where('id_evento', $idEvento)
                ->where('marker', 1)
                ->orderBy('id_gps')
                ->first();
    
            if ($gpsChosen) {
                $row['gps_details'][] = $gpsChosen->toArray();
                $row['eleccion']      = (int) $gpsChosen->marker;
                $row['eleccionId']    = (int) $gpsChosen->id_gps;
                $row['fecha']         = $gpsChosen->fecha ?? 'Por definir';
                $row['lugar']         = $gpsChosen->location ?? 'Por definir';
                $row['hora']          = $gpsChosen->hora ?? 'Por definir';
            } else {
                $row['fecha'] = 'Por definir';
                $row['lugar'] = 'Por definir';
                $row['hora']  = 'Por definir';
            }
    
            // Todos los lugares del evento
            $gpsAll = Gps::where('id_evento', $idEvento)->get();
            foreach ($gpsAll as $g) {
                $row['lugares'][] = $g->toArray();
            }
    
            // Voto del invitado (si existe)
            $voto = Votaciones::where('id_evento', $idEvento)
                ->where('id_user', $idInvi)
                ->value('id_gps');
            $row['voto'] = $voto ? (int) $voto : 0;
    
        } else {
            // meeting apunta a un único id_gps
            $gpsData = Gps::where('id_gps', $evento->meeting)->first();
            if ($gpsData) {
                $row['lugares'][] = $gpsData->toArray();
                $row['lugar']     = $gpsData->location;
                $row['fecha']     = $gpsData->fecha;
                $row['hora']      = $gpsData->hora;
                $site             = $gpsData->location ?? '';
            }
            // En este modo, el "voto" no aplica; mantener 0 para compat
            $row['voto'] = 0;
        }
    
        // Imágenes (Meet / Zoom / BD / default)
        if ($site === 'Meet') {
            $row['imagenes'] = ['Meet.png'];
        } elseif ($site === 'Zoom') {
            $row['imagenes'] = ['Zoom.png'];
        } else {
            $imgUrls = ImgEvento::where('id_evento', $idEvento)->pluck('img_url')->all();
            $row['imagenes'] = !empty($imgUrls) ? $imgUrls : ['default.png'];
        }
    
        // Totales de invitados
        $row['total_invitados'] = Invitado::where('id_evento', $idEvento)->count();
        $row['total_aceptados'] = Invitado::where('id_evento', $idEvento)->where('confirm', 2)->count();
    
        // Números de teléfono de los invitados
        $invitadosIds = Invitado::where('id_evento', $idEvento)->pluck('id_user')->all();
        if (!empty($invitadosIds)) {
            $row['invitados'] = Data::whereIn('id_data', $invitadosIds)->pluck('number')->all();
        } else {
            $row['invitados'] = [];
        }
    
        // Confirmación del invitado específico
        $confirm = Invitado::where('id_evento', $idEvento)
            ->where('id_user', $idInvi)
            ->value('confirm');
        $row['confirmacion'] = ($confirm === null) ? 'No confirmado' : (int) $confirm;
    
        return response()->json([
            'eventos' => [$row],
        ], 200, [], JSON_UNESCAPED_UNICODE);
    }
    
    
    public function activos(Request $request)
    {
        $nick = (string) $request->query('user', '');
        if ($nick === '') {
            return response()->json(['error' => 'Parámetro "user" es requerido'], 422);
        }

        // 1) Buscar usuario por nick
        $user = Data::select('id_data')->where('nick', $nick)->first();
        if (!$user) {
            return response()->json(['error' => 'Usuario no encontrado'], 404);
        }
        $idData = (int) $user->id_data;

        return DB::transaction(function () use ($idData) {
            $records = [];

            // ================================
            // A) Eventos donde es INVITADO (confirm = 2)
            // ================================
            $invitaciones = Invitado::query()
                ->where('id_user', $idData)
                ->where('confirm', 2)
                ->orderByDesc('id_invi')
                ->get(['id_evento','id_user as invitado']);

            foreach ($invitaciones as $inv) {
                $ev = Evento::where('id_evento', $inv->id_evento)->first();
                if (!$ev) continue;

                $row = $ev->toArray();

                // Creador (nombre completo)
                $creador = Data::select('nombre','apellido')->where('id_data', $ev->id_user)->first();
                $row['creador'] = $creador ? ($creador->nombre.' '.$creador->apellido) : 'Usuario no encontrado';

                // Enriquecer lugar/fecha/hora desde gps
                $site = '';
                $row['eleccion'] = 0;
                $row['gps_details'] = [];

                if ((int)$ev->meeting === 0) {
                    // Tomar el primer marker=1 del evento
                    $gps = Gps::where('id_evento', $ev->id_evento)
                        ->where('marker', 1)
                        ->orderBy('id_gps')
                        ->first();

                    if ($gps) {
                        $row['eleccion']   = (int) $gps->marker;
                        $row['eleccionId'] = (int) $gps->id_gps;
                        $row['fecha']      = $gps->fecha ?? 'Fecha por definir';
                        $row['lugar']      = $gps->location ?? 'Lugar por definir';
                        $row['hora']       = $gps->hora ?? 'Hora por definir';
                        $row['gps_details'][] = $gps->toArray();
                    } else {
                        $row['fecha'] = 'Fecha por definir';
                        $row['lugar'] = 'Lugar por definir';
                        $row['hora']  = 'Hora por definir';
                    }
                } else {
                    // meeting = id_gps fijo
                    $gps = Gps::where('id_gps', $ev->meeting)->first();
                    if ($gps) {
                        $row['lugar'] = $gps->location;
                        $row['fecha'] = $gps->fecha;
                        $row['hora']  = $gps->hora;
                        $site = $gps->location ?? '';
                    }
                }

                // Imagen
                $img = ImgEvento::select('img_url')
                    ->where('id_evento', $ev->id_evento)
                    ->orderBy('id_img', 'asc')
                    ->first();

                $row['imagen']  = $img ? $img->img_url : $this->fallbackImage($site);
                $row['invitado'] = $inv->invitado;
                $row['config']   = 2;

                $records[] = $row;
            }

            // ================================
            // B) Eventos CREADOS por el usuario (confirm=1, estado=1)
            // ================================
            $creados = Evento::where('id_user', $idData)
                ->where('confirm', 1)
                ->where('estado', 1)
                ->orderByDesc('id_evento')
                ->get();

            foreach ($creados as $ev) {
                $row = $ev->toArray();

                $row['creador'] = 'creado por ti';
                $site = '';
                $row['eleccion'] = 0;
                $row['gps_details'] = [];

                if ((int)$ev->meeting === 0) {
                    $gps = Gps::where('id_evento', $ev->id_evento)
                        ->where('marker', 1)
                        ->orderBy('id_gps')
                        ->first();

                    if ($gps) {
                        $row['eleccion']   = (int) $gps->marker;
                        $row['eleccionId'] = (int) $gps->id_gps;
                        $row['fecha']      = $gps->fecha ?? 'Fecha por definir';
                        $row['lugar']      = $gps->location ?? 'Lugar por definir';
                        $row['hora']       = $gps->hora ?? 'Hora por definir';
                        $row['gps_details'][] = $gps->toArray();
                    } else {
                        $row['fecha'] = 'Fecha por definir';
                        $row['lugar'] = 'Lugar por definir';
                        $row['hora']  = 'Hora por definir';
                    }
                } else {
                    $gps = Gps::where('id_gps', $ev->meeting)->first();
                    if ($gps) {
                        $row['lugar'] = $gps->location;
                        $row['fecha'] = $gps->fecha;
                        $row['hora']  = $gps->hora;
                        $site = $gps->location ?? '';
                    }
                }

                $img = ImgEvento::select('img_url')
                    ->where('id_evento', $ev->id_evento)
                    ->orderBy('id_img', 'asc')
                    ->first();

                $row['imagen']  = $img ? $img->img_url : $this->fallbackImage($site);
                $row['invitado'] = $idData; // el creador
                $row['config']   = 9;

                $records[] = $row;
            }

            // (Opcional) Orden global por id_evento DESC para mezclar ambos grupos
            usort($records, function ($a, $b) {
                return (($b['id_evento'] ?? 0) <=> ($a['id_evento'] ?? 0));
            });


            return response()->json(['records' => $records], 200);
        });
    }
    
    
    public function confirmar(Request $request)
    {
        $idEvento = (int) $request->input('evento', 0);
    
        if ($idEvento <= 0) {
            return response()->json([
                'success' => false,
                'message' => 'Parámetro evento no válido'
            ], 422);
        }
    
        $evento = Evento::find($idEvento);
        if (!$evento) {
            return response()->json([
                'success' => false,
                'message' => 'Evento no encontrado'
            ], 404);
        }
    
        try {
            $evento->confirm = 1;
            $evento->save();
    
            return response()->json([
                'success' => true,
                'message' => 'Evento confirmado exitosamente'
            ], 200);
    
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al confirmar el evento',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }
    
    public function cancelar(Request $request)
    {
        $idEvento = (int) $request->input('evento', 0);
        $motivo   = (string) $request->input('motivo', '');
    
        // Validaciones básicas
        if ($idEvento <= 0 || $motivo === '') {
            return response()->json([
                'success' => false,
                'message' => 'Faltan datos necesarios: evento o motivo.'
            ], 422);
        }
    
        $evento = Evento::find($idEvento);
        if (!$evento) {
            return response()->json([
                'success' => false,
                'message' => 'Evento no encontrado'
            ], 404);
        }
    
        DB::beginTransaction();
        try {
            // Guardar la cancelación en la tabla cancelaciones
            Cancelaciones::create([
                'id_evento' => $idEvento,
                'motivo'    => $motivo,
            ]);
    
            // Cambiar el estado del evento a 0 (cancelado)
            $evento->estado = 0;
            $evento->save();
    
            DB::commit();
    
            return response()->json([
                'success' => true,
                'message' => 'Cancelación registrada y estado actualizado correctamente.',
                'evento'  => $evento, // opcional: devuelve el evento actualizado
            ], 200);
    
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Error al procesar la solicitud: '.$e->getMessage(),
            ], 500);
        }
    }
    
    
    public function motivoCancelacion(Request $request)
    {
        // Lee el id desde el body (JSON o x-www-form-urlencoded)
        $id = $request->input('idCancelado');
    
        // Compat: si no es numérico o no viene, responde igual que el legacy
        if (!is_numeric($id) || (int)$id <= 0) {
            return response()->json(['motivo' => 'No encontrado']);
        }
    
        $id = (int) $id;
    
        // Buscar motivo
        $motivo = \App\Models\Cancelaciones::where('id_evento', $id)->value('motivo');
    
        return response()->json([
            'motivo' => $motivo ?: 'No encontrado',
        ]);
    }

    
    
    public function selectLocation(Request $request)
    {
        $idEvento = (int) $request->input('idEvento', 0);
        $type     = (int) $request->input('type', 0);
        $idGps    = (int) $request->input('idGps', 0);
        $idUser   = (int) $request->input('idUser', 0);
    
        // Validaciones básicas
        if ($idEvento <= 0 || $idGps <= 0 || !in_array($type, [1,2], true)) {
            return response()->json([
                'success' => false,
                'message' => 'Parámetros inválidos: idEvento, idGps o type.'
            ], 422);
        }
    
        // Validar existencia de evento
        $evento = Evento::find($idEvento);
        if (!$evento) {
            return response()->json(['success'=>false,'message'=>'Evento no encontrado'], 404);
        }
    
        // Validar que el GPS pertenezca al evento
        $gps = Gps::where('id_gps', $idGps)->where('id_evento', $idEvento)->first();
        if (!$gps) {
            return response()->json(['success'=>false,'message'=>'El GPS no pertenece al evento'], 422);
        }
    
        DB::beginTransaction();
        try {
            if ($type === 1) {
                // Desmarcar todos los GPS del evento (si quieres que solo quede 1 activo)
                Gps::where('id_evento', $idEvento)->update(['marker' => 0]);
    
                // Marcar el seleccionado
                $gps->marker = 1;
                $gps->save();
    
                DB::commit();
                return response()->json([
                    'success' => true,
                    'message' => 'GPS actualizado correctamente.',
                ], 200);
    
            } elseif ($type === 2) {
                if ($idUser <= 0) {
                    return response()->json([
                        'success' => false,
                        'message' => 'idUser requerido para votar'
                    ], 422);
                }
    
                // Insertar votación (controlando duplicados por índice único en BD)
                try {
                    Votaciones::create([
                        'id_evento' => $idEvento,
                        'id_user'   => $idUser,
                        'id_gps'    => $idGps,
                    ]);
    
                    DB::commit();
                    return response()->json([
                        'success' => true,
                        'message' => 'Votación registrada correctamente.',
                    ], 200);
    
                } catch (\Illuminate\Database\QueryException $e) {
                    DB::rollBack();
                    if (str_contains($e->getMessage(), 'votaciones_evento_user_unique')) {
                        return response()->json([
                            'success' => false,
                            'message' => 'Ya registraste una votación para este evento.'
                        ], 409);
                    }
                    throw $e;
                }
            }
    
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Tipo de operación no soportado.'
            ], 400);
    
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Error al procesar la solicitud: '.$e->getMessage(),
            ], 500);
        }
    }
    
    public function updateInvitadoConfirm(Request $request)
    {
        $idEvento = (int) $request->input('id_evento', 0);
        $idUser   = (int) $request->input('id_user', 0);
        $confirm  = $request->input('confirm', null);
    
        // Validaciones básicas
        if ($idEvento <= 0 || $idUser <= 0 || $confirm === null) {
            return response()->json([
                'success' => false,
                'message' => 'Faltan datos: id_evento, id_user o confirm.'
            ], 422);
        }
    
        // Opcional: restringe valores válidos de confirm (ajústalo a tu esquema)
        // Por ejemplo: 0 = rechazado, 1 = pendiente, 2 = aceptado
        if (!in_array((int)$confirm, [0,1,2,3], true)) {
            return response()->json([
                'success' => false,
                'message' => 'Valor de confirm inválido.'
            ], 422);
        }
    
        // Buscar relación invitado
        $inv = Invitado::where('id_evento', $idEvento)
            ->where('id_user', $idUser)
            ->first();
    
        if (!$inv) {
            return response()->json([
                'success' => false,
                'message' => 'Invitado no encontrado para este evento.'
            ], 404);
        }
    
        try {
            $inv->confirm = (int)$confirm;
            $inv->save();
    
            return response()->json([
                'success' => true,
                'message' => 'Confirmación actualizada correctamente.',
            ], 200);
    
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al actualizar confirmación: '.$e->getMessage(),
            ], 500);
        }
    }
    
    
    
    public function estadisticas(Request $request, $id_evento = null)
    {
        // Acepta id por ruta o por query/body (?idEvento=)
        $rawId = $id_evento ?? $request->query('idEvento') ?? $request->input('idEvento');
        if (!is_numeric($rawId) || (int)$rawId <= 0) {
            return response()->json(['error' => 'ID de evento no proporcionado o inválido']);
        }
        $idEvento = (int) $rawId;
    
        // Meta del evento (si no existe, se reporta type=0 como en legacy)
        $meta = Evento::select('tipo', 'meeting')->where('id_evento', $idEvento)->first();
        $type    = (int) ($meta->tipo    ?? 0);
        $meeting = (int) ($meta->meeting ?? 0);
    
        // Contadores de invitados
        $totalInv   = Invitado::where('id_evento', $idEvento)->count();
        $totalAcept = Invitado::where('id_evento', $idEvento)->where('confirm', 2)->count();
        $totalRech  = Invitado::where('id_evento', $idEvento)->where('confirm', 3)->count();
        $totalPend  = Invitado::where('id_evento', $idEvento)->whereIn('confirm', [0,1])->count();
    
        // Votos totales del evento
        $totalVotos = Votaciones::where('id_evento', $idEvento)->count();
    
        // id_gps distintos con sus totales de votos
        $voteCounts = Votaciones::select('id_gps', DB::raw('COUNT(*) as total_votaciones'))
            ->where('id_evento', $idEvento)
            ->whereNotNull('id_gps')
            ->groupBy('id_gps')
            ->get();
    
        // <-- reemplazo del arrow function por closure clásica
        $idGpsList = $voteCounts->pluck('id_gps')
            ->map(function ($v) { return (int) $v; })
            ->values()
            ->all();
    
        // Detalles de GPS para cada id_gps con votos
        $gpsRows = empty($idGpsList)
            ? collect()
            : Gps::whereIn('id_gps', $idGpsList)->get()->keyBy('id_gps');
    
        $gpsDetails = [];
        foreach ($voteCounts as $vc) {
            $idGps = (int) $vc->id_gps;
            $row   = $gpsRows->get($idGps);
    
            if ($row) {
                $arr = $row->toArray();
                $arr['total_votaciones'] = (int) $vc->total_votaciones;
                $gpsDetails[$idGps] = $arr;
            } else {
                $gpsDetails[$idGps] = [
                    'id_gps'           => $idGps,
                    'total_votaciones' => (int) $vc->total_votaciones,
                ];
            }
        }
    
        return response()->json([
            'type'          => $type,
            'meeting'       => $meeting,
            'invitados'     => (int) $totalInv,
            'aceptados'     => (int) $totalAcept,
            'rechazados'    => (int) $totalRech,
            'pendientes'    => (int) $totalPend,
            'votos'         => (int) $totalVotos,
            'idUbicaciones' => $idGpsList,
            'gpsDetails'    => $gpsDetails,
        ], 200, [], JSON_UNESCAPED_UNICODE);
    }

    
    

    /**
     * Imagen por defecto según $site (Meet/Zoom)
     */
    private function fallbackImage(string $site): string
    {
        $site = strtolower(trim($site));
        if ($site === 'meet') return 'Meet.png';
        if ($site === 'zoom') return 'Zoom.png';
        return 'default.png';
    }


    /**
     * Maneja archivos 'file' o 'file[]' en multipart, convierte a .webp y guarda registro en img_eventos.
     * Retorna array con nombres guardados.
     */
    private function handleUploadedImages(Request $request, int $idEvento): array
    {
        $saved = [];

        // Obtener archivos: soportar 'file' y 'file[]'
        $files = [];
        if ($request->hasFile('file')) {
            $f = $request->file('file');
            // puede venir 1 archivo o un array
            $files = is_array($f) ? $f : [$f];
        }

        if (empty($files)) {
            return $saved;
        }

        // Asegurar carpeta destino: storage/app/media/img_eventos
        $relativeDir = 'media/img_eventos';
        $absoluteDir = storage_path('app/' . $relativeDir);
        if (!is_dir($absoluteDir)) {
            @mkdir($absoluteDir, 0775, true);
        }

        foreach ($files as $file) {
            if (!$file->isValid()) {
                continue;
            }

            // Generar nombre único .webp y garantizar no duplicar
            do {
                $newFileName = uniqid('uploaded_', true) . '.webp';
                $exists = ImgEvento::where('img_url', $newFileName)->exists();
            } while ($exists);

            $destPath = $absoluteDir . DIRECTORY_SEPARATOR . $newFileName;

            // Convertir a webp con GD (similar a tu PHP original)
            $ok = $this->convertToWebp($file->getRealPath(), $destPath, 80);

            if (!$ok) {
                // fallback: guardar como original si falla (opcional)
                // $file->move($absoluteDir, $newFileName); // sería .webp inválido; mejor saltar
                continue;
            }

            // Registrar en BD
            ImgEvento::create([
                'id_evento' => $idEvento,
                'img_url'   => $newFileName,
            ]);

            $saved[] = $newFileName;
        }

        return $saved;
    }

    /**
     * Convierte una imagen a webp usando GD.
     * Retorna true si guarda exitosamente.
     */
    private function convertToWebp(string $sourcePath, string $destPath, int $quality = 80): bool
    {
        // Detectar mime
        $info = @getimagesize($sourcePath);
        if (!$info || !isset($info['mime'])) {
            // Intentar carga genérica
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
                    // preservar transparencia PNG
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
                // fallback genérico
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
}
