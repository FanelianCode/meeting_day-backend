<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\Data;
use App\Models\Invitado;

class ContactsController extends Controller
{
    /**
     * GET /api/v1/contacts/numbers?nick=ALGO
     * Devuelve números distintos al del usuario indicado por 'nick'.
     * 200: ["3001112222","3123456789",...]
     * 200: []  (si el usuario existe pero no tiene número)
     * 404: {"error":"No se encontraron resultados para el nick proporcionado."}
     * 422: {"error":"Falta el parámetro 'nick'."}
     */
    public function numbers(Request $request): JsonResponse
    {
        $nick = trim((string) $request->query('user', ''));

        if ($nick === '') {
            return response()->json(['error' => "Falta el parámetro 'nick'."], 422);
        }

        $user = Data::where('nick', $nick)->first(['id_data', 'number']);
        if (!$user) {
            return response()->json(['error' => 'No se encontraron resultados para el nick proporcionado.'], 404);
        }

        if (empty($user->number)) {
            return response()->json([], 200);
        }

        $numbers = Data::query()
            ->whereNotNull('number')
            ->where('number', '<>', '')
            ->where('number', '<>', $user->number)
            ->distinct()
            ->pluck('number')
            ->values();

        return response()->json($numbers, 200);
    }

    /**
     * GET /api/v1/contacts/stats?number=3001234567
     * Respuesta:
     * {
     *   "telefono": "3001234567",
     *   "indicativo": 57,
     *   "total_registros": 12,
     *   "pendientes": 5,
     *   "aceptados": 6,
     *   "rechazados": 1
     * }
     * 422 si falta 'number', 404 si no existe en data.
     */
    public function estadisticasContacto(Request $request): JsonResponse
    {
        $number = trim((string) $request->query('number', ''));
        if ($number === '') {
            return response()->json(['error' => 'Number not provided'], 422);
        }

        // Normalización básica (quita espacios/guiones/paréntesis, conserva '+')
        $normalized = preg_replace('/[^\d+]/', '', $number);

        // Buscar exacto y, si no, con la versión normalizada
        $contact = Data::where('number', $number)->first(['id_data', 'indicativo', 'number']);
        if (!$contact && $normalized !== $number) {
            $contact = Data::where('number', $normalized)->first(['id_data', 'indicativo', 'number']);
        }

        if (!$contact) {
            return response()->json(['error' => 'No data found for the provided number'], 404);
        }

        $idData = (int) $contact->id_data;

        // Un solo query agregado para todos los contadores
        $agg = Invitado::where('id_user', $idData)
            ->selectRaw('COUNT(*) AS total_registros')
            ->selectRaw('SUM(CASE WHEN confirm IN (0,1) THEN 1 ELSE 0 END) AS pendientes')
            ->selectRaw('SUM(CASE WHEN confirm = 2 THEN 1 ELSE 0 END) AS aceptados')
            ->selectRaw('SUM(CASE WHEN confirm = 3 THEN 1 ELSE 0 END) AS rechazados')
            ->first();

        return response()->json([
            'telefono'        => $contact->number,
            'indicativo'      => (int) ($contact->indicativo ?? 0),
            'total_registros' => (int) ($agg->total_registros ?? 0),
            'pendientes'      => (int) ($agg->pendientes ?? 0),
            'aceptados'       => (int) ($agg->aceptados ?? 0),
            'rechazados'      => (int) ($agg->rechazados ?? 0),
        ], 200);
    }
}
