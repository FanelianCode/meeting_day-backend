<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Evento\InvitadoEventosRequest;
use App\Http\Requests\Api\V1\Evento\InvitadosSyncRequest;
use App\Http\Resources\Api\V1\EventoInvitadoResource;
use Illuminate\Support\Facades\DB;
use App\Models\Data;
use App\Models\Invitado;
use App\Models\Votaciones;

class EventoInvitadoController extends Controller
{
    /**
     * Listar eventos a los que he sido invitado (body-only).
     * Body: { "nick": "..." }
     * Respuesta: { "records": [ ... ] }
     */
    public function index(InvitadoEventosRequest $request)
    {
        $nick = $request->validated()['nick'];

        // 1) Resolver id_data del usuario por nick
        $user = DB::table('data')->select('id_data')->where('nick', $nick)->first();
        if (!$user) {
            return response()->json(['error' => 'Usuario no encontrado'], 404);
        }
        $idData = (int) $user->id_data;

        // 2) Invitaciones + datos base del evento + creador
        $invitaciones = DB::table('invitados as i')
            ->join('eventos as e', 'e.id_evento', '=', 'i.id_evento')
            ->join('data as d', 'd.id_data', '=', 'e.id_user') // creador
            ->where('i.id_user', $idData)
            ->orderByDesc('i.id_invi')
            ->get([
                'i.id_evento',
                'i.id_user as invitado',
                'i.confirm as confirmacion',

                'e.id_evento as e_id_evento',
                'e.id_user as e_id_user',
                'e.estado',
                'e.titulo',
                'e.descripcion',
                'e.tipo',
                'e.meeting',
                'e.confirm',
                'e.flimit',
                'e.hlimit',
                'e.time_zone',

                DB::raw("CONCAT(d.nombre,' ',d.apellido) as creador"),
            ]);

        $records = [];

        foreach ($invitaciones as $row) {
            // Base del evento (alineado a tus columnas reales)
            $evento = [
                'id_evento'   => (int) $row->e_id_evento,
                'id_user'     => (int) $row->e_id_user,   // creador del evento
                'estado'      => $row->estado,
                'titulo'      => $row->titulo,
                'descripcion' => $row->descripcion,
                'tipo'        => $row->tipo,
                'meeting'     => (int) $row->meeting,
                'confirm'     => $row->confirm,          // campo en eventos
                'flimit'      => $row->flimit,
                'hlimit'      => $row->hlimit,
                'time_zone'   => $row->time_zone,

                'creador'     => $row->creador ?? 'Usuario no encontrado',

                // Datos de la invitación
                'confirmacion'=> (int) $row->confirmacion,
                'invitado'    => (int) $row->invitado,
            ];

            // ========= Lógica GPS / meeting =========
            $site = 'default.png';

            if ($evento['meeting'] === 0) {
                // a) Buscar marker=1
                $gpsMarker = DB::table('gps')
                    ->where('id_evento', $evento['id_evento'])
                    ->where('marker', 1)
                    ->limit(1)
                    ->first();

                if ($gpsMarker) {
                    $evento['eleccion']   = (int) $gpsMarker->marker;
                    $evento['eleccionId'] = (int) $gpsMarker->id_gps;
                    $evento['fecha']      = $gpsMarker->fecha;
                    $evento['lugar']      = $gpsMarker->location;
                    $evento['hora']       = $gpsMarker->hora;
                    $evento['gps_details'] = [[
                        'id_gps'    => $gpsMarker->id_gps,
                        'id_evento' => $gpsMarker->id_evento,
                        'location'  => $gpsMarker->location,
                        'place'     => $gpsMarker->place,
                        'fecha'     => $gpsMarker->fecha,
                        'hora'      => $gpsMarker->hora,
                        'marker'    => $gpsMarker->marker,
                    ]];
                } else {
                    // b) Sin marker → listar todas las ubicaciones
                    $allGps = DB::table('gps')
                        ->where('id_evento', $evento['id_evento'])
                        ->get();

                    $evento['locations'] = $allGps->map(function ($g) {
                        return [
                            'id_gps'    => $g->id_gps,
                            'id_evento' => $g->id_evento,
                            'location'  => $g->location,
                            'place'     => $g->place,
                            'fecha'     => $g->fecha,
                            'hora'      => $g->hora,
                            'marker'    => $g->marker,
                        ];
                    })->values()->all();

                    $evento['fecha']       = 'Por definir';
                    $evento['lugar']       = 'Por definir';
                    $evento['hora']        = 'Por definir';
                    $evento['gps_details'] = [];
                }
            } else {
                // meeting != 0 → usar ese id_gps
                $gps = DB::table('gps')->where('id_gps', $evento['meeting'])->first();
                if ($gps) {
                    $evento['lugar'] = $gps->location ?? null;
                    $evento['fecha'] = $gps->fecha ?? null;
                    $evento['hora']  = $gps->hora ?? null;
                    $site = $gps->location ?? $site;
                }
            }

            // ========= Imagen (Meet / Zoom / primera imagen o default) =========
            if ($site === 'Meet') {
                $evento['imagen'] = 'Meet.png';
            } elseif ($site === 'Zoom') {
                $evento['imagen'] = 'Zoom.png';
            } else {
                $img = DB::table('img_eventos')
                    ->where('id_evento', $evento['id_evento'])
                    ->select('img_url')
                    ->limit(1)
                    ->first();

                $evento['imagen'] = $img ? $img->img_url : 'default.png';
            }

            // Guardar
            $records[] = (object) $evento; // casteo a objeto para que el Resource use ->prop sin errores
        }

        // 3) Respuesta con Resource
        return response()->json([
            'records' => EventoInvitadoResource::collection($records),
        ]);
    }
    
    
   public function syncByPhones(InvitadosSyncRequest $request)
    {
        $idEvento = (int) $request->input('id_evento');
    
        // 1) Normaliza preservando el "+", y deduplica
        $telefonosE164 = collect($request->input('invitados', []))
            ->filter(function ($t) { return $t !== null && $t !== ''; })
            ->map(function ($t) {
                $t = (string)$t;
                $t = trim($t);
                // Quita espacios, guiones y paréntesis, pero NO el "+" inicial
                $t = str_replace(array("\xC2\xA0",' ',"\t","\r","\n",'(',')','-','.'), '', $t);
                // Elimina "+" que no estén al inicio
                $t = preg_replace('/(?!^)\+/', '', $t);
                // Si no empieza por "+", añádelo por robustez (el Request ya lo exige)
                if ($t !== '' && substr($t, 0, 1) !== '+') {
                    $t = '+' . $t;
                }
                // Valida E.164 simple
                return preg_match('/^\+\d{8,15}$/', $t) ? $t : null;
            })
            ->filter(function ($t) { return $t !== null; })
            ->unique()
            ->values();
    
        if ($telefonosE164->isEmpty()) {
            return response()->json([
                'success'        => true,
                'id_evento'      => $idEvento,
                'creates'        => [],
                'deletes'        => [],
                'no_encontrados' => [],
                'creados_detalle'    => [],
                'eliminados_detalle' => [],
            ]);
        }
    
        // 2) Busca en BD EXACTAMENTE con "+"
        //    AJUSTA el nombre de columna si no es 'number'
        $usersByPhone = Data::query()
            ->whereIn('number', $telefonosE164->all())
            ->pluck('id_data', 'number'); // keys: '+57300...'
    
        // 3) Resuelve IDs actuales y no encontrados (manteniendo "+")
        $actualesIds   = array();
        $noEncontrados = array();
    
        foreach ($telefonosE164 as $pE164) {
            if (isset($usersByPhone[$pE164])) {
                $actualesIds[] = (int)$usersByPhone[$pE164];
            } else {
                $noEncontrados[] = $pE164;
            }
        }
    
        // 4) Ya registrados en el evento
        $registradosIds = Invitado::query()
            ->where('id_evento', $idEvento)
            ->pluck('id_user')
            ->map(function ($v) { return (int)$v; })
            ->toArray();
    
        // 5) Diferencias
        $creates = array_values(array_diff($actualesIds, $registradosIds));
        $deletes = array_values(array_diff($registradosIds, $actualesIds));
    
        DB::transaction(function () use ($idEvento, $creates, $deletes) {
            if (!empty($creates)) {
                $rows = array_map(function ($idUser) use ($idEvento) {
                    return ['id_evento' => $idEvento, 'id_user' => $idUser];
                }, $creates);
                Invitado::query()->insertOrIgnore($rows);
            }
    
            if (!empty($deletes)) {
                Invitado::query()
                    ->where('id_evento', $idEvento)
                    ->whereIn('id_user', $deletes)
                    ->delete();
    
                Votaciones::query()
                    ->where('id_evento', $idEvento)
                    ->whereIn('id_user', $deletes)
                    ->delete();
            }
        });
    
        // 6) Detalles (devolviendo siempre con "+")
        $creadosDetalle = empty($creates) ? [] :
            Data::query()->whereIn('id_data', $creates)
                ->get(['id_data','nombre','apellido','number'])
                ->map(function ($u) {
                    return [
                        'id_user'  => (int) $u->id_data,
                        'nombre'   => $u->nombre,
                        'apellido' => $u->apellido,
                        'number'   => (string) $u->number, // ya viene con '+'
                    ];
                })->values();
    
        $eliminadosDetalle = empty($deletes) ? [] :
            Data::query()->whereIn('id_data', $deletes)
                ->get(['id_data','nombre','apellido','number'])
                ->map(function ($u) {
                    return [
                        'id_user'  => (int) $u->id_data,
                        'nombre'   => $u->nombre,
                        'apellido' => $u->apellido,
                        'number'   => (string) $u->number,
                    ];
                })->values();
    
        return response()->json([
            'success'            => true,
            'id_evento'          => $idEvento,
            'creates'            => $creates,
            'deletes'            => $deletes,
            'no_encontrados'     => $noEncontrados,   // con "+"
            'creados_detalle'    => $creadosDetalle,
            'eliminados_detalle' => $eliminadosDetalle,
        ]);
    }



}
