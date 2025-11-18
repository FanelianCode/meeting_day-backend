<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use App\Models\Notification;
use App\Models\Data;

class NotificationsController extends Controller
{
    /**
     * POST /api/v1/notifications/mark-read
     *
     * Acepta:
     *  - id_user: puede ser NICK (string) o id_data (numérico)
     *  - nick   : alias explícito
     *  - user   : alias adicional
     */
    public function markRead(Request $request)
    {
        $resolved = $this->resolveUserIdFromRequest($request);
        if (!$resolved['ok']) {
            return response()->json($resolved['response'], $resolved['status']);
        }

        $userId = $resolved['user_id'];

        $updated = Notification::where('id_user', $userId)
            ->where('is_read', 0)
            ->update(['is_read' => 1]);

        return response()->json([
            'success' => true,
            'user_id' => (int) $userId,
            'updated' => (int) $updated,
        ]);
    }

    /**
     * POST /api/v1/notifications/badge
     *
     * Devuelve cuántas notificaciones NO leídas tiene el usuario.
     *
     * Body:
     *  - id_user | nick | user
     *
     * Respuesta:
     * {
     *   "success": true,
     *   "user_id": 42,
     *   "unread": 5
     * }
     */
    public function badge(Request $request)
    {
        $resolved = $this->resolveUserIdFromRequest($request);
        if (!$resolved['ok']) {
            return response()->json($resolved['response'], $resolved['status']);
        }

        $userId = $resolved['user_id'];

        $unread = Notification::where('id_user', $userId)
            ->where('is_read', 0)
            ->count();

        return response()->json([
            'success' => true,
            'user_id' => (int) $userId,
            'unread'  => (int) $unread,
        ]);
    }

    /**
     * Resolver id_data a partir de id_user/nick/user.
     *
     * @return array{
     *   ok: bool,
     *   status: int,
     *   response?: array,
     *   user_id?: int
     * }
     */
    private function resolveUserIdFromRequest(Request $request): array
    {
        $validator = Validator::make($request->all(), [
            'id_user' => 'nullable|string',
            'nick'    => 'nullable|string',
            'user'    => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return [
                'ok'     => false,
                'status' => 422,
                'response' => [
                    'success' => false,
                    'error'   => 'Validación fallida.',
                    'errors'  => $validator->errors(),
                ],
            ];
        }

        $raw = $request->input('id_user')
            ?? $request->input('nick')
            ?? $request->input('user');

        if ($raw === null || $raw === '') {
            return [
                'ok'     => false,
                'status' => 422,
                'response' => [
                    'success' => false,
                    'error'   => 'Falta el identificador del usuario (id_user/nick/user).',
                ],
            ];
        }

        $idData = null;

        // Si es numérico válido, lo tomamos como id_data directo
        if (is_numeric($raw)) {
            $idData = (int) $raw;
            if ($idData <= 0) {
                $idData = null;
            }
        }

        // Si no, buscamos por nick
        if ($idData === null) {
            $idData = Data::where('nick', $raw)->value('id_data');
        }

        if (!$idData) {
            return [
                'ok'     => false,
                'status' => 404,
                'response' => [
                    'success' => false,
                    'error'   => 'No se encontró el usuario con el identificador: ' . (string) $raw,
                ],
            ];
        }

        return [
            'ok'      => true,
            'status'  => 200,
            'user_id' => (int) $idData,
        ];
    }
}
