<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use App\Models\Miembro;
use App\Models\Data;

class MiembrosController extends Controller
{
    /**
     * Confirmar o eliminar un miembro.
     * - confirm == 3 => elimina el registro
     * - confirm en {0,1,2} => actualiza el campo 'confirm'
     *
     * Acepta:
     *   - idMiembro  (preferido) o id_miembro (compat)
     *   - confirm    (0,1,2,3)
     *
     * Respuestas JSON con status codes apropiados.
     */
    public function confirmar(Request $request)
    {
        // Validación flexible (acepta idMiembro o id_miembro)
        $validator = Validator::make($request->all(), [
            'idMiembro'  => 'nullable|integer|min:1',
            'id_miembro' => 'nullable|integer|min:1',
            'confirm'    => 'required|integer|in:0,1,2,3',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors'  => $validator->errors(),
                'message' => 'Datos inválidos.',
            ], 422);
        }

        // Resolver id del miembro desde cualquiera de los dos nombres
        $idMiembro = $request->input('idMiembro') ?? $request->input('id_miembro');
        if (!$idMiembro) {
            return response()->json([
                'success' => false,
                'message' => 'Se requiere idMiembro o id_miembro.',
            ], 422);
        }

        $confirm = (int) $request->input('confirm');

        // Buscar miembro
        $miembro = Miembro::find($idMiembro);
        if (!$miembro) {
            return response()->json([
                'success' => false,
                'message' => 'Miembro no encontrado.',
            ], 404);
        }

        // TODO (opcional): verificar permisos del usuario autenticado/owner del grupo

        // Eliminar si confirm == 3
        if ($confirm === 3) {
            $deleted = $miembro->delete();
            return $deleted
                ? response()->json([
                    'success' => true,
                    'message' => 'Miembro eliminado correctamente.',
                    'data'    => ['id_miembro' => (int) $idMiembro],
                ])
                : response()->json([
                    'success' => false,
                    'message' => 'No se pudo eliminar el miembro.',
                ], 400);
        }

        // Actualizar confirm (0,1,2)
        $miembro->confirm = $confirm;

        // Guardar solo si hay cambios
        if ($miembro->isDirty('confirm')) {
            $miembro->save();

            return response()->json([
                'success' => true,
                'message' => 'Miembro actualizado correctamente.',
                'data'    => [
                    'id_miembro' => (int) $miembro->id_miembro,
                    'id_grupo'   => (int) $miembro->id_grupo,
                    'id_user'    => (int) $miembro->id_user,
                    'confirm'    => (int) $miembro->confirm,
                ],
            ]);
        }

        // Sin cambios
        return response()->json([
            'success' => true,
            'message' => 'No hubo cambios (el valor de confirm ya era el mismo).',
            'data'    => [
                'id_miembro' => (int) $miembro->id_miembro,
                'id_grupo'   => (int) $miembro->id_grupo,
                'id_user'    => (int) $miembro->id_user,
                'confirm'    => (int) $miembro->confirm,
            ],
        ]);
    }

    /**
     * Sincroniza los miembros de un grupo a partir de una lista de teléfonos.
     * Recibe:
     *  - idGrupo   (int)
     *  - miembros  (array<string> teléfonos)
     * Lógica:
     *  - Mapea teléfonos -> id_data en tabla 'data'
     *  - Compara con miembros ya registrados en 'miembros'
     *  - Inserta faltantes y elimina sobrantes en una transacción
     * Devuelve:
     *  - creates (ids de usuarios añadidos)
     *  - deletes (ids de usuarios eliminados)
     *  - not_found (teléfonos que no existen en 'data')
     */
    public function syncByPhones(Request $request)
    {
        $validated = $request->validate([
            'idGrupo'    => ['required', 'integer', 'min:1'],
            'miembros'   => ['required', 'array', 'min:1'],
            'miembros.*' => ['string', 'min:3', 'max:300'], // teléfonos como string
        ]);

        $idGrupo   = (int) $validated['idGrupo'];
        $telefonos = array_values(array_unique($validated['miembros']));

        // Normalización simple (quitar espacios y guiones)
        $telefonos = array_map(function ($t) {
            return preg_replace('/\s+|-/', '', $t);
        }, $telefonos);

        // 1) Mapear teléfonos -> (id_data, number) con UNA sola consulta
        $dataRows              = Data::whereIn('number', $telefonos)->get(['id_data', 'number']);
        $idsActuales           = $dataRows->pluck('id_data')->all();
        $telefonosEncontrados  = $dataRows->pluck('number')->all();
        $notFoundPhones        = array_values(array_diff($telefonos, $telefonosEncontrados));

        // 2) Usuarios ya registrados en el grupo
        $idsRegistrados = Miembro::where('id_grupo', $idGrupo)->pluck('id_user')->all();

        // 3) Diferencias
        $creates = array_values(array_diff($idsActuales, $idsRegistrados));
        $deletes = array_values(array_diff($idsRegistrados, $idsActuales));

        // 4) Transacción: inserts y deletes en bloque
        DB::beginTransaction();
        try {
            if (!empty($creates)) {
                $rows = [];
                foreach ($creates as $idUser) {
                    $rows[] = [
                        'id_grupo' => $idGrupo,
                        'id_user'  => $idUser,
                        'confirm'  => 0, // o el default que manejes
                    ];
                }
                // Inserción en bloque (fuera del foreach)
                Miembro::insert($rows);
            }

            if (!empty($deletes)) {
                Miembro::where('id_grupo', $idGrupo)
                    ->whereIn('id_user', $deletes)
                    ->delete();
            }

            DB::commit();

            return response()->json([
                'success'            => true,
                'idGrupo'            => $idGrupo,
                'creates'            => $creates,          // ids de usuarios creados
                'deletes'            => $deletes,          // ids de usuarios eliminados
                'not_found'          => $notFoundPhones,   // teléfonos sin usuario en 'data'
                'total_actuales'     => count($idsActuales),
                'total_registrados'  => count($idsRegistrados),
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'error'   => 'Error al sincronizar miembros: ' . $e->getMessage(),
            ], 500);
        }
    }
}
